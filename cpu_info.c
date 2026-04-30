/*
+----------------------------------------------------------------------+
  | Copyright (c) The PHP Group                                          |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | https://www.php.net/license/3_01.txt                                 |
  +----------------------------------------------------------------------+
  | Cross-platform CPU usage probes.                                     |
  |                                                                      |
  | Returns raw monotonic counters for two layers:                       |
  |   - the current process (sum across all threads),                    |
  |   - the host system (sum across all logical CPUs).                   |
  | Callers compute deltas between two snapshots themselves; cpu_usage() |
  | provides a convenience wrapper that maintains an internal "previous" |
  | snapshot and returns ready-to-use percentages.                       |
  +----------------------------------------------------------------------+
*/

#include "cpu_info.h"

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#ifdef PHP_WIN32
#  include <windows.h>
#else
#  include <sys/time.h>
#  include <sys/resource.h>
#  include <time.h>
#  include <unistd.h>
#endif

zend_class_entry *async_ce_cpu_snapshot = NULL;

typedef struct {
	uint64_t wall_ns;
	uint64_t process_user_ns;
	uint64_t process_system_ns;
	uint64_t system_idle_ns;
	uint64_t system_busy_ns;
	uint32_t cpu_count;
	bool has_system_data;
} async_cpu_snapshot_t;

/* Module-global state for cpu_usage() */
static async_cpu_snapshot_t cpu_usage_prev;
static bool cpu_usage_prev_valid = false;

#ifdef ZTS
static MUTEX_T cpu_usage_mutex = NULL;
#  define CPU_USAGE_LOCK()   tsrm_mutex_lock(cpu_usage_mutex)
#  define CPU_USAGE_UNLOCK() tsrm_mutex_unlock(cpu_usage_mutex)
#else
#  define CPU_USAGE_LOCK()   ((void)0)
#  define CPU_USAGE_UNLOCK() ((void)0)
#endif

/* ====================================================================
 * Platform helpers
 * ==================================================================== */

#ifdef PHP_WIN32

static uint64_t filetime_to_ns(FILETIME ft)
{
	ULARGE_INTEGER u;
	u.LowPart  = ft.dwLowDateTime;
	u.HighPart = ft.dwHighDateTime;
	/* FILETIME counts 100-nanosecond intervals. */
	return u.QuadPart * 100ULL;
}

static uint64_t monotonic_ns(void)
{
	static LARGE_INTEGER freq = {0};
	LARGE_INTEGER counter;

	if (freq.QuadPart == 0) {
		QueryPerformanceFrequency(&freq);
	}
	QueryPerformanceCounter(&counter);

	if (freq.QuadPart == 0) {
		return 0;
	}
	/* Compute (counter * 1e9) / freq without losing the fractional part. */
	uint64_t whole = (uint64_t)(counter.QuadPart / freq.QuadPart) * 1000000000ULL;
	uint64_t frac  = (uint64_t)(counter.QuadPart % freq.QuadPart) * 1000000000ULL / (uint64_t)freq.QuadPart;
	return whole + frac;
}

static uint32_t cpu_count_now(void)
{
	DWORD n = GetActiveProcessorCount(ALL_PROCESSOR_GROUPS);
	if (n == 0) {
		SYSTEM_INFO si;
		GetSystemInfo(&si);
		n = si.dwNumberOfProcessors;
	}
	return n ? (uint32_t)n : 1;
}

static void capture_process_cpu(uint64_t *user_ns, uint64_t *sys_ns)
{
	FILETIME create, exit_ft, kernel, user;

	if (GetProcessTimes(GetCurrentProcess(), &create, &exit_ft, &kernel, &user)) {
		*user_ns = filetime_to_ns(user);
		*sys_ns  = filetime_to_ns(kernel);
	} else {
		*user_ns = 0;
		*sys_ns  = 0;
	}
}

static bool capture_system_cpu(uint64_t *idle_ns, uint64_t *busy_ns)
{
	FILETIME idle, kernel, user;

	if (!GetSystemTimes(&idle, &kernel, &user)) {
		*idle_ns = 0;
		*busy_ns = 0;
		return false;
	}

	uint64_t i = filetime_to_ns(idle);
	uint64_t k = filetime_to_ns(kernel); /* On Windows this includes idle. */
	uint64_t u = filetime_to_ns(user);

	*idle_ns = i;
	*busy_ns = (k + u > i) ? (k + u - i) : 0;
	return true;
}

#else /* POSIX */

static uint64_t monotonic_ns(void)
{
	struct timespec ts;
	if (clock_gettime(CLOCK_MONOTONIC, &ts) != 0) {
		return 0;
	}
	return (uint64_t)ts.tv_sec * 1000000000ULL + (uint64_t)ts.tv_nsec;
}

static uint32_t cpu_count_now(void)
{
	long n = sysconf(_SC_NPROCESSORS_ONLN);
	return n > 0 ? (uint32_t)n : 1;
}

static void capture_process_cpu(uint64_t *user_ns, uint64_t *sys_ns)
{
	struct rusage ru;

	if (getrusage(RUSAGE_SELF, &ru) == 0) {
		*user_ns = (uint64_t)ru.ru_utime.tv_sec * 1000000000ULL
				 + (uint64_t)ru.ru_utime.tv_usec * 1000ULL;
		*sys_ns  = (uint64_t)ru.ru_stime.tv_sec * 1000000000ULL
				 + (uint64_t)ru.ru_stime.tv_usec * 1000ULL;
	} else {
		*user_ns = 0;
		*sys_ns  = 0;
	}
}

static bool capture_system_cpu(uint64_t *idle_ns, uint64_t *busy_ns)
{
	FILE *fp = fopen("/proc/stat", "r");
	if (fp == NULL) {
		*idle_ns = 0;
		*busy_ns = 0;
		return false;
	}

	char buf[512];
	if (fgets(buf, sizeof(buf), fp) == NULL) {
		fclose(fp);
		*idle_ns = 0;
		*busy_ns = 0;
		return false;
	}
	fclose(fp);

	/* Format:
	 *   cpu  user nice system idle iowait irq softirq steal guest guest_nice
	 * guest / guest_nice are already accounted for in user / nice respectively
	 * by the kernel (since 2.6.24), so we ignore them.
	 */
	unsigned long long user = 0, nice_v = 0, system = 0, idle = 0,
					   iowait = 0, irq = 0, softirq = 0, steal = 0;
	int n = sscanf(buf, "cpu %llu %llu %llu %llu %llu %llu %llu %llu",
				   &user, &nice_v, &system, &idle, &iowait, &irq, &softirq, &steal);
	if (n < 4) {
		*idle_ns = 0;
		*busy_ns = 0;
		return false;
	}

	long ticks_per_sec = sysconf(_SC_CLK_TCK);
	if (ticks_per_sec <= 0) {
		ticks_per_sec = 100;
	}
	double ns_per_tick = 1e9 / (double) ticks_per_sec;

	unsigned long long total_idle = idle + iowait;
	unsigned long long total_busy = user + nice_v + system + irq + softirq + steal;

	*idle_ns = (uint64_t)((double) total_idle * ns_per_tick);
	*busy_ns = (uint64_t)((double) total_busy * ns_per_tick);
	return true;
}

#endif /* PHP_WIN32 */

static void capture_snapshot(async_cpu_snapshot_t *snap)
{
	snap->wall_ns = monotonic_ns();
	capture_process_cpu(&snap->process_user_ns, &snap->process_system_ns);
	snap->has_system_data = capture_system_cpu(&snap->system_idle_ns, &snap->system_busy_ns);
	snap->cpu_count = cpu_count_now();
}

/* ====================================================================
 * Object population
 * ==================================================================== */

static void populate_snapshot_object(zend_object *obj, const async_cpu_snapshot_t *snap)
{
	zval v;

#define SET_LONG(name, val) do { \
		ZVAL_LONG(&v, (zend_long)(val)); \
		zend_update_property(async_ce_cpu_snapshot, obj, (name), sizeof(name) - 1, &v); \
	} while (0)

	SET_LONG("wallNs",          snap->wall_ns);
	SET_LONG("processUserNs",   snap->process_user_ns);
	SET_LONG("processSystemNs", snap->process_system_ns);
	SET_LONG("systemIdleNs",    snap->system_idle_ns);
	SET_LONG("systemBusyNs",    snap->system_busy_ns);
	SET_LONG("cpuCount",        snap->cpu_count);

#undef SET_LONG
}

/* ====================================================================
 * PHP API
 * ==================================================================== */

PHP_METHOD(Async_CpuSnapshot, __construct)
{
	ZEND_PARSE_PARAMETERS_NONE();
}

PHP_METHOD(Async_CpuSnapshot, now)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_cpu_snapshot_t snap;
	capture_snapshot(&snap);

	object_init_ex(return_value, async_ce_cpu_snapshot);
	populate_snapshot_object(Z_OBJ_P(return_value), &snap);
}

PHP_FUNCTION(Async_loadavg)
{
	ZEND_PARSE_PARAMETERS_NONE();

#ifdef PHP_WIN32
	RETURN_NULL();
#else
	double avg[3];
	if (getloadavg(avg, 3) != 3) {
		RETURN_NULL();
	}
	array_init_size(return_value, 3);
	add_next_index_double(return_value, avg[0]);
	add_next_index_double(return_value, avg[1]);
	add_next_index_double(return_value, avg[2]);
#endif
}

PHP_FUNCTION(Async_cpu_usage)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_cpu_snapshot_t cur;
	capture_snapshot(&cur);

	CPU_USAGE_LOCK();
	bool first_call = !cpu_usage_prev_valid;
	async_cpu_snapshot_t prev = cpu_usage_prev;
	cpu_usage_prev = cur;
	cpu_usage_prev_valid = true;
	CPU_USAGE_UNLOCK();

	double interval_sec    = 0.0;
	double process_cores   = 0.0;
	double process_percent = 0.0;
	double system_percent  = 0.0;

	if (!first_call && cur.wall_ns > prev.wall_ns) {
		uint64_t dwall = cur.wall_ns - prev.wall_ns;
		uint64_t dproc_user = cur.process_user_ns   > prev.process_user_ns
						   ? cur.process_user_ns   - prev.process_user_ns   : 0;
		uint64_t dproc_sys  = cur.process_system_ns > prev.process_system_ns
						   ? cur.process_system_ns - prev.process_system_ns : 0;
		uint64_t dproc = dproc_user + dproc_sys;

		interval_sec  = (double) dwall / 1e9;
		process_cores = (double) dproc / (double) dwall;
		if (cur.cpu_count > 0) {
			process_percent = process_cores / (double) cur.cpu_count * 100.0;
		}

		if (cur.has_system_data && prev.has_system_data) {
			uint64_t didle = cur.system_idle_ns > prev.system_idle_ns
						   ? cur.system_idle_ns - prev.system_idle_ns : 0;
			uint64_t dbusy = cur.system_busy_ns > prev.system_busy_ns
						   ? cur.system_busy_ns - prev.system_busy_ns : 0;
			uint64_t total = didle + dbusy;
			if (total > 0) {
				system_percent = (double) dbusy / (double) total * 100.0;
			}
		}
	}

	array_init(return_value);
	add_assoc_double(return_value, "process_cores",   process_cores);
	add_assoc_double(return_value, "process_percent", process_percent);
	add_assoc_double(return_value, "system_percent",  system_percent);
	add_assoc_long  (return_value, "cpu_count",       (zend_long) cur.cpu_count);
	add_assoc_double(return_value, "interval_sec",    interval_sec);

#ifdef PHP_WIN32
	add_assoc_null(return_value, "loadavg");
#else
	double avg[3];
	if (getloadavg(avg, 3) == 3) {
		zval la;
		array_init_size(&la, 3);
		add_next_index_double(&la, avg[0]);
		add_next_index_double(&la, avg[1]);
		add_next_index_double(&la, avg[2]);
		add_assoc_zval(return_value, "loadavg", &la);
	} else {
		add_assoc_null(return_value, "loadavg");
	}
#endif
}

/* ====================================================================
 * Lifecycle
 * ==================================================================== */

void async_cpu_info_module_init(void)
{
#ifdef ZTS
	if (cpu_usage_mutex == NULL) {
		cpu_usage_mutex = tsrm_mutex_alloc();
	}
#endif
	cpu_usage_prev_valid = false;
	memset(&cpu_usage_prev, 0, sizeof(cpu_usage_prev));
}

void async_cpu_usage_reset_state(void)
{
	CPU_USAGE_LOCK();
	cpu_usage_prev_valid = false;
	memset(&cpu_usage_prev, 0, sizeof(cpu_usage_prev));
	CPU_USAGE_UNLOCK();
}

void async_cpu_info_module_shutdown(void)
{
#ifdef ZTS
	if (cpu_usage_mutex != NULL) {
		tsrm_mutex_free(cpu_usage_mutex);
		cpu_usage_mutex = NULL;
	}
#endif
	cpu_usage_prev_valid = false;
}
