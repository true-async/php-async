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
#ifndef ASYNC_INTERNAL_FUZZ_H
#define ASYNC_INTERNAL_FUZZ_H

#include "php_config.h"

#ifdef ZEND_ASYNC_FUZZ

#include <stdint.h>
#include <stddef.h>

typedef enum {
	ASYNC_FUZZ_MODE_FIFO   = 0,
	ASYNC_FUZZ_MODE_RANDOM = 1,
	ASYNC_FUZZ_MODE_PCT    = 2,
} async_fuzz_mode_t;

typedef struct {
	async_fuzz_mode_t mode;
	uint64_t          seed;
	uint64_t          rng_state;
	/* PCT parameters (mode == PCT) */
	uint32_t          pct_k;
} async_fuzz_state_t;

/* Read ASYNC_FUZZ_SEED and ASYNC_SCHED env vars, populate state.
 * Safe to call multiple times. */
void async_fuzz_init(async_fuzz_state_t *state);

/* SplitMix64 — fast, statistically decent, no dependencies. */
static inline uint64_t async_fuzz_next_u64(async_fuzz_state_t *state)
{
	uint64_t z = (state->rng_state += 0x9E3779B97F4A7C15ULL);
	z = (z ^ (z >> 30)) * 0xBF58476D1CE4E5B9ULL;
	z = (z ^ (z >> 27)) * 0x94D049BB133111EBULL;
	return z ^ (z >> 31);
}

static inline uint32_t async_fuzz_next_u32_below(async_fuzz_state_t *state, uint32_t bound)
{
	if (bound <= 1) {
		return 0;
	}
	return (uint32_t) (async_fuzz_next_u64(state) % bound);
}

/* Decide swap index in [0, count) for the next pop from the ready queue.
 * Returns 0 in FIFO mode (no-op swap). In RANDOM mode flips a coin and
 * picks a uniform random offset on heads. */
static inline uint32_t async_fuzz_scheduler_pick(async_fuzz_state_t *state, uint32_t count)
{
	if (state->mode == ASYNC_FUZZ_MODE_FIFO || count <= 1) {
		return 0;
	}
	if (state->mode == ASYNC_FUZZ_MODE_RANDOM) {
		/* Coin flip — keep some FIFO bias so progress invariants still hold. */
		if ((async_fuzz_next_u64(state) & 1ULL) == 0) {
			return 0;
		}
		return async_fuzz_next_u32_below(state, count);
	}
	/* TODO: PCT mode — placeholder, falls through to FIFO for now. */
	return 0;
}

#endif /* ZEND_ASYNC_FUZZ */

#endif /* ASYNC_INTERNAL_FUZZ_H */
