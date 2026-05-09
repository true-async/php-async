/*
+----------------------------------------------------------------------+
  | Copyright (c) The PHP Group                                          |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | https://www.php.net/license/3_01.txt                                 |
  +----------------------------------------------------------------------+
  | Author: Edmond                                                       |
  +----------------------------------------------------------------------+
*/
#include "fuzz.h"

#ifdef ZEND_ASYNC_FUZZ

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static uint64_t parse_u64(const char *s, uint64_t fallback)
{
	if (s == NULL || *s == '\0') {
		return fallback;
	}
	char *end = NULL;
	int base = 10;
	if (s[0] == '0' && (s[1] == 'x' || s[1] == 'X')) {
		base = 16;
	}
	unsigned long long v = strtoull(s, &end, base);
	if (end == s) {
		return fallback;
	}
	return (uint64_t) v;
}

void async_fuzz_init(async_fuzz_state_t *state)
{
	memset(state, 0, sizeof(*state));
	state->mode      = ASYNC_FUZZ_MODE_FIFO;
	state->seed      = 0;
	state->rng_state = 0;
	state->pct_k     = 0;

	const char *seed_env = getenv("TRUE_ASYNC_FUZZ_SEED");
	state->seed      = parse_u64(seed_env, 0);
	state->rng_state = state->seed ^ 0xA5A5A5A5DEADBEEFULL;

	const char *sched = getenv("TRUE_ASYNC_SCHED");
	if (sched == NULL || sched[0] == '\0' || strncmp(sched, "fifo", 4) == 0) {
		state->mode = ASYNC_FUZZ_MODE_FIFO;
		return;
	}

	if (strncmp(sched, "random", 6) == 0) {
		state->mode = ASYNC_FUZZ_MODE_RANDOM;
		const char *colon = strchr(sched, ':');
		if (colon != NULL) {
			uint64_t s = parse_u64(colon + 1, state->seed);
			state->seed      = s;
			state->rng_state = s ^ 0xA5A5A5A5DEADBEEFULL;
		}
		if (getenv("TRUE_ASYNC_FUZZ_VERBOSE")) fprintf(stderr, "[async-fuzz] scheduler=random seed=0x%llx\n",
				(unsigned long long) state->seed);
		return;
	}

	if (strncmp(sched, "pct", 3) == 0) {
		state->mode = ASYNC_FUZZ_MODE_PCT;
		const char *colon = strchr(sched, ':');
		if (colon != NULL) {
			state->seed      = parse_u64(colon + 1, state->seed);
			state->rng_state = state->seed ^ 0xA5A5A5A5DEADBEEFULL;
			const char *colon2 = strchr(colon + 1, ':');
			if (colon2 != NULL) {
				state->pct_k = (uint32_t) parse_u64(colon2 + 1, 3);
			}
		}
		if (getenv("TRUE_ASYNC_FUZZ_VERBOSE")) fprintf(stderr, "[async-fuzz] scheduler=pct seed=0x%llx k=%u (not yet implemented, FIFO)\n",
				(unsigned long long) state->seed, state->pct_k);
		return;
	}

	if (getenv("TRUE_ASYNC_FUZZ_VERBOSE")) fprintf(stderr, "[async-fuzz] unknown TRUE_ASYNC_SCHED=%s, falling back to fifo\n", sched);
}

#endif /* ZEND_ASYNC_FUZZ */
