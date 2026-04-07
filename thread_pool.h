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
#include "internal/circular_buffer.h"
#include <Zend/zend_async_API.h>
#include <pthread.h>

///////////////////////////////////////////////////////////
/// Task (persistent memory, passed through internal queue)
///////////////////////////////////////////////////////////

typedef struct _async_thread_pool_task_s {
	zval callable;                         /* transferred callable (pemalloc) */
	zval args;                             /* transferred args array (pemalloc) */
	zend_future_shared_state_t *state;     /* shared state for result delivery */
} async_thread_pool_task_t;

///////////////////////////////////////////////////////////
/// Thread pool (persistent memory, shared between threads)
///////////////////////////////////////////////////////////

typedef struct _async_thread_pool_s async_thread_pool_t;

struct _async_thread_pool_s {
	/* Task queue */
	circular_buffer_t task_queue;

	/* Mutex + condvar for task queue synchronization */
	pthread_mutex_t mutex;
	pthread_cond_t cond;

	/* Number of worker threads */
	int32_t worker_count;

	/* Counts */
	zend_atomic_int pending_count;   /* tasks in queue */
	zend_atomic_int running_count;   /* tasks being executed */

	/* State flags */
	zend_atomic_int closed;          /* no new submissions */

	/* Worker thread handles */
	pthread_t *workers;              /* array of worker_count threads */

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
