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
#include "thread_channel.h"
#include <Zend/zend_async_API.h>

///////////////////////////////////////////////////////////
/// Thread pool (persistent memory, shared between threads)
///////////////////////////////////////////////////////////

typedef struct _async_thread_pool_s async_thread_pool_t;

struct _async_thread_pool_s {
	/* Number of worker threads */
	int32_t worker_count;

	/* Task channel (shared, persistent memory) */
	async_thread_channel_t *task_channel;

	/* Counts (atomic — accessed from multiple threads) */
	zend_atomic_int pending_count;
	zend_atomic_int running_count;

	/* State flags */
	zend_atomic_int closed;

	/* Worker thread events (array of worker_count, ecalloc'd in main) */
	zend_async_thread_event_t **workers;

	/* Reference count for cross-thread sharing */
	zend_atomic_int ref_count;
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
	((thread_pool_object_t *)((char *)(obj) - XtOffsetOf(thread_pool_object_t, std)))

/* Registration function */
void async_register_thread_pool_ce(void);

#endif /* ASYNC_THREAD_POOL_H */
