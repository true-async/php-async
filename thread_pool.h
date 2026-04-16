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
	/* Base structure (must be first for casting) */
	zend_async_thread_pool_t base;

	/* Task channel (shared, persistent memory) */
	async_thread_channel_t *task_channel;
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

/* Factory — creates a new thread pool (returns base pointer for API registration) */
zend_async_thread_pool_t *async_thread_pool_create(int32_t worker_count, int32_t queue_size);

/* Registration function */
void async_register_thread_pool_ce(void);

#endif /* ASYNC_THREAD_POOL_H */
