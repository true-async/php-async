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
#ifndef ASYNC_POOL_H
#define ASYNC_POOL_H

#include "php_async_api.h"
#include <Zend/zend_async_API.h>
#include "internal/circular_buffer.h"

///////////////////////////////////////////////////////////////////////////////
// Internal pool structure (all logic here)
///////////////////////////////////////////////////////////////////////////////

typedef struct _zend_async_pool_s zend_async_pool_t;

/* Pool waiter - coroutine waiting for resource */
typedef struct {
	zend_coroutine_event_callback_t callback;
} zend_async_pool_waiter_t;

struct _zend_async_pool_s {
	zend_async_event_t event;           /* for callbacks/waiters */

	/* Callbacks */
	zend_fcall_t *factory;              /* create resource (required) */
	zend_fcall_t *destructor;           /* destroy resource (nullable) */
	zend_fcall_t *healthcheck;          /* background health check (nullable) */
	zend_fcall_t *before_acquire;       /* check before acquire (nullable) */
	zend_fcall_t *before_release;       /* action on release (nullable) */

	/* Storage */
	circular_buffer_t idle;             /* idle resources (zval) */
	uint32_t active_count;              /* resources currently in use */

	/* Config */
	uint32_t min_size;
	uint32_t max_size;

	/* Waiters queue - like waiting_receivers in channel */
	zend_async_callbacks_vector_t waiters;

	/* Healthcheck timer */
	zend_async_timer_event_t *healthcheck_timer;
	uint32_t healthcheck_interval_ms;
};

/* Helper macros */
#define ZEND_ASYNC_POOL_TOTAL(pool) \
	(circular_buffer_count(&(pool)->idle) + (pool)->active_count)

#define ZEND_ASYNC_POOL_IS_CLOSED(pool) \
	ZEND_ASYNC_EVENT_IS_CLOSED(&(pool)->event)

///////////////////////////////////////////////////////////////////////////////
// C API functions
///////////////////////////////////////////////////////////////////////////////

/* Create a new pool */
zend_async_pool_t *zend_async_pool_create(
	zend_fcall_t *factory,
	zend_fcall_t *destructor,
	zend_fcall_t *healthcheck,
	zend_fcall_t *before_acquire,
	zend_fcall_t *before_release,
	uint32_t min_size,
	uint32_t max_size,
	uint32_t healthcheck_interval_ms
);

/* Acquire resource (blocking) */
bool zend_async_pool_acquire(
	zend_async_pool_t *pool,
	zval *result,
	zend_long timeout_ms
);

/* Try to acquire resource (non-blocking) */
bool zend_async_pool_try_acquire(
	zend_async_pool_t *pool,
	zval *result
);

/* Release resource back to pool */
void zend_async_pool_release(
	zend_async_pool_t *pool,
	zval *resource
);

/* Close pool (wake waiters with exception) */
void zend_async_pool_close(zend_async_pool_t *pool);

/* Destroy pool (free memory) */
void zend_async_pool_destroy(zend_async_pool_t *pool);

/* Statistics */
uint32_t zend_async_pool_count(zend_async_pool_t *pool);
uint32_t zend_async_pool_idle_count(zend_async_pool_t *pool);
uint32_t zend_async_pool_active_count(zend_async_pool_t *pool);

///////////////////////////////////////////////////////////////////////////////
// PHP object wrapper
///////////////////////////////////////////////////////////////////////////////

typedef struct _async_pool_s async_pool_t;

struct _async_pool_s {
	zend_async_pool_t *pool;    /* pointer to internal pool */
	zend_object std;            /* PHP object (must be last) */
};

/* Class entries */
extern zend_class_entry *async_ce_pool;
extern zend_class_entry *async_ce_pool_exception;

/* Convert macros */
#define ASYNC_POOL_FROM_OBJ(obj) \
	((async_pool_t *)((char *)(obj) - XtOffsetOf(async_pool_t, std)))

/* Registration function */
void async_register_pool_ce(void);

#endif /* ASYNC_POOL_H */
