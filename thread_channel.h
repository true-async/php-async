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
#ifndef ASYNC_THREAD_CHANNEL_H
#define ASYNC_THREAD_CHANNEL_H

#include "php_async_api.h"
#include <Zend/zend_async_API.h>
#include "internal/circular_buffer.h"
#include <pthread.h>

///////////////////////////////////////////////////////////
/// Thread-safe channel waiter
///////////////////////////////////////////////////////////

typedef struct _thread_channel_waiter_s {
	zend_ulong thread_id;
	zend_ulong coroutine_id;
	zend_async_event_callback_t *callback;
} thread_channel_waiter_t;

typedef struct {
	thread_channel_waiter_t *data;
	uint32_t length;
	uint32_t capacity;
} thread_channel_waiter_queue_t;

///////////////////////////////////////////////////////////
/// Thread-safe channel (persistent memory)
///////////////////////////////////////////////////////////

typedef struct _async_thread_channel_s async_thread_channel_t;

struct _async_thread_channel_s {
	/* ABI base (must be first) — provides event + send/receive pointers */
	zend_async_channel_t channel;

	/* Buffered data storage (pemalloc allocator) */
	circular_buffer_t buffer;

	/* Mutex protecting buffer and waiter queues */
	pthread_mutex_t mutex;

	/* Channel capacity (always >= 1) */
	int32_t capacity;

	/* Mapping: thread_id → uv_async_t* for cross-thread notification.
	 * Created lazily on first send/recv from a given thread. */
	HashTable thread_handles;

	/* Waiting coroutines (pemalloc'd) */
	thread_channel_waiter_queue_t waiting_receivers;
	thread_channel_waiter_queue_t waiting_senders;

	/* Reference count for cross-thread sharing */
	zend_atomic_int ref_count;
};

///////////////////////////////////////////////////////////
/// PHP object wrapper (emalloc, per-thread)
///////////////////////////////////////////////////////////

typedef struct _thread_channel_object_s {
	async_thread_channel_t *channel;  /* pemalloc'd, shared */
	zend_object std;                  /* must be last */
} thread_channel_object_t;

/* Class entries */
extern zend_class_entry *async_ce_thread_channel;
extern zend_class_entry *async_ce_thread_channel_exception;

/* Convert zend_object to thread_channel_object_t */
#define ASYNC_THREAD_CHANNEL_FROM_OBJ(obj) \
	((thread_channel_object_t *)((char *)(obj) - XtOffsetOf(thread_channel_object_t, std)))

/* Registration function */
void async_register_thread_channel_ce(void);

#endif /* ASYNC_THREAD_CHANNEL_H */
