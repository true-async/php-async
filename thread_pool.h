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
#ifndef ASYNC_THREAD_POOL_H
#define ASYNC_THREAD_POOL_H

#include "php_async_api.h"
#include "future.h"
#include "thread.h"
#include "thread_channel.h"
#include <Zend/zend_async_API.h>

///////////////////////////////////////////////////////////
/// Thread pool (persistent memory, shared between threads)
///////////////////////////////////////////////////////////

typedef struct _async_thread_pool_s async_thread_pool_t;

struct _async_thread_pool_s {
	/* Base structure (must be first for casting) */
	zend_async_thread_pool_t base;

	/* Task channel (shared, persistent memory) */
	async_thread_channel_t *task_channel;

	/* Optional bootloader: deep-copied closure to be executed once per worker
	 * on startup, before the task receive loop. The snapshot's `entry` slot
	 * holds the bootloader copy (the pool has no per-pool "entry" — each task
	 * brings its own snapshot). NULL when no bootloader was provided. */
	async_thread_snapshot_t *bootloader_snapshot;

	/* When true, each PHP-closure task runs inside its own coroutine in the
	 * worker's scheduler; completion is delivered via an event callback that
	 * resolves the future. When false, tasks run synchronously inline. */
	bool coroutine_mode;

	/* Max concurrent task coroutines per worker (0 = unlimited). Only
	 * meaningful in coroutine_mode. Total pool concurrency = workers ×
	 * concurrency. Worker blocks on a slot-trigger before each receive
	 * once its active count hits this limit. */
	int32_t concurrency;

	/* Set by cancel() before channel.close. Coroutine workers see it on
	 * wakeup and scope-cancel in-flight tasks; sync workers can't be
	 * preempted out of zend_call_function, so they ignore it. */
	zend_atomic_int cancel_requested;
};

///////////////////////////////////////////////////////////
/// PHP object wrapper (emalloc, per-thread)
///////////////////////////////////////////////////////////

typedef struct _thread_pool_object_s {
	async_thread_pool_t *pool;     /* pemalloc'd, shared */
	zend_object std;               /* must be last */
} thread_pool_object_t;

/* Class entries */
extern zend_class_entry *async_ce_thread_pool;
extern zend_class_entry *async_ce_thread_pool_exception;

/* Convert zend_object to thread_pool_object_t */
#define ASYNC_THREAD_POOL_FROM_OBJ(obj) \
	((thread_pool_object_t *)((char *)(obj) - offsetof(thread_pool_object_t, std)))

/* Factory — matches `zend_async_new_thread_pool_t`. Registered with
 * `zend_async_thread_pool_register` and invoked from PHP `__construct`.
 * `bootloader` may be NULL; `coroutine_mode`=false uses the basic
 * synchronous-task path; `concurrency`=0 means unlimited (only used
 * in coroutine mode). See ZEND_ASYNC_NEW_THREAD_POOL macro. */
zend_async_thread_pool_t *async_thread_pool_create(
	int32_t worker_count, int32_t queue_size, const zend_fcall_t *bootloader,
	bool coroutine_mode, int32_t concurrency);

/* Registration function */
void async_register_thread_pool_ce(void);

#endif /* ASYNC_THREAD_POOL_H */
