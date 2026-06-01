/*
+----------------------------------------------------------------------+
  | Copyright (c) The PHP Group                                          |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | https://www.php.net/license/3_01.txt                                 |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Edmond                                                       |
  +----------------------------------------------------------------------+
*/
#ifndef PHP_ASYNC_H
#define PHP_ASYNC_H

#include <php.h>

#ifdef PHP_WIN32
#include "libuv/uv.h"
#else
#include <uv.h>
#endif

#include "coroutine.h"
#include "internal/circular_buffer.h"

#ifdef PHP_WIN32

#else

#endif

extern zend_module_entry async_module_entry;
#define phpext_async_ptr &async_module_entry

#include "php_async_api.h"

PHP_ASYNC_API extern zend_class_entry *async_ce_awaitable;
PHP_ASYNC_API extern zend_class_entry *async_ce_completable;
PHP_ASYNC_API extern zend_class_entry *async_ce_timeout;
PHP_ASYNC_API extern zend_class_entry *async_ce_signal;
PHP_ASYNC_API extern zend_class_entry *async_ce_circuit_breaker_state;
PHP_ASYNC_API extern zend_class_entry *async_ce_circuit_breaker;
PHP_ASYNC_API extern zend_class_entry *async_ce_filesystem_event;
PHP_ASYNC_API extern zend_class_entry *async_ce_fs_watcher;
PHP_ASYNC_API extern zend_class_entry *async_ce_circuit_breaker_strategy;

#define PHP_ASYNC_NAME "true_async"
#define PHP_ASYNC_VERSION "0.7.0"
#define PHP_ASYNC_NAME_VERSION "true async v0.7.0"

#define REACTOR_CHECK_INTERVAL (100 * 1000000) // ms in nanoseconds

/* Main scheduler-loop reactor-poll throttle, adaptive window.
 * While coroutines are runnable the reactor is polled at most once per this
 * window, to amortise the epoll/io_uring poll over a batch of micro-coroutines.
 * The window is chosen per-iteration:
 *   - MAX (10ms) when no libuv timer is imminent — lets pipelined / keep-alive
 *     HTTP/1 batch many requests per poll (throughput);
 *   - MIN (1ms) when a libuv timer is due within MIN — QUIC ACK/PTO must fire
 *     on time or HTTP/3 collapses. */
#define REACTOR_POLL_THROTTLE_NS     (1 * 1000000)  // 1ms — floor, when a timer is imminent
#define REACTOR_POLL_THROTTLE_MAX_NS (10 * 1000000) // 10ms — coarse batch window, no near timer

typedef struct
{
	// The first field must be a reference to a Zend object.
	zend_object *std;
	zend_async_event_dispose_t prev_dispose;
} async_timeout_ext_t;

/**
 * Structure of an Awaitable interface object that holds a reference to an event object.
 */
typedef struct
{
	ZEND_ASYNC_EVENT_REF_PROLOG
	// Pointer to the event object, which is a timer event.
	zend_async_timer_event_t *event;
	zend_object std;
} async_timeout_object_t;

#define ASYNC_TIMEOUT_FROM_EVENT(ev) ((async_timeout_ext_t *) ((char *) (ev) + (ev)->extra_offset))
#define ASYNC_TIMEOUT_FROM_OBJ(obj) ((async_timeout_object_t *) ((char *) (obj) - (obj)->handlers->offset))

#ifdef ZEND_ASYNC_FUZZ
#include "internal/fuzz.h"
#endif

ZEND_BEGIN_MODULE_GLOBALS(async)
// Microtask queue
circular_buffer_t microtasks;
/* Queue of coroutine_queue */
circular_buffer_t coroutine_queue;
/* Queue of resumed coroutines for event cleanup */
circular_buffer_t resumed_coroutines;
/* List of coroutines  */
HashTable coroutines;
/* The transfer structure is used to return to the main execution context. */
zend_fiber_transfer *main_transfer;
/* The main flow stack */
zend_vm_stack main_vm_stack;
/* System root context */
zend_async_context_t *root_context;
/* The default concurrency */
int default_concurrency;

/* Fiber context pool for performance optimization */
circular_buffer_t fiber_context_pool;

/* The reactor */
uv_loop_t uvloop;
bool reactor_started;
HashTable active_io_handles; /* tracks all IO handles issued by the reactor */

/* Global signal management for all platforms */
HashTable *signal_handlers; /* signum -> uv_signal_t* */
HashTable *signal_events;   /* signum -> HashTable* (signal events) */
HashTable *process_events;  /* dedicated for SIGCHLD process events */

#ifdef PHP_WIN32
uv_thread_t *watcherThread;
HANDLE ioCompletionPort;
unsigned int countWaitingDescriptors;
bool isRunning;
uv_async_t *uvloop_wakeup;
/* Circular buffer of libuv_process_t ptr */
circular_buffer_t *pid_queue;
#endif

/* Reactor execution optimization */
uint64_t last_reactor_tick;

/* Debug: print coroutine wait info on deadlock detection */
bool debug_deadlock;

/* Soft-timer channels currently in potential-deadlock state.
 * Closed in bulk by async_channel_resolve_deadlocks() when the scheduler
 * sees the loop empty, so deadlocks surface as ChannelException with
 * reason "deadlock_resolved" rather than as a generic Deadlock error. */
HashTable deadlock_channels;

#ifdef PHP_WIN32
#endif

#ifdef ZEND_ASYNC_FUZZ
async_fuzz_state_t fuzz;
#endif
ZEND_END_MODULE_GLOBALS(async)

ZEND_EXTERN_MODULE_GLOBALS(async)

#define ASYNC_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(async, v)
#define ASYNC_GLOBALS ZEND_MODULE_GLOBALS_BULK(async)

#if defined(ZTS) && defined(COMPILE_DL_ASYNC)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

#endif // ASYNC_H