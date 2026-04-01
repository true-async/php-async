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
/// Thread-safe channel (persistent memory)
///////////////////////////////////////////////////////////

typedef struct _async_thread_channel_s async_thread_channel_t;

struct _async_thread_channel_s {
	/* ABI base (must be first) — provides event + send/receive pointers */
	zend_async_channel_t channel;

	/* Buffered data storage (pemalloc allocator) */
	circular_buffer_t buffer;

	/* Mutex protecting buffer and trigger mappings */
	pthread_mutex_t mutex;

	/* Channel capacity (always >= 1) */
	int32_t capacity;

	/* Trigger events for cross-thread notification.
	 * HashTable: thread_id (zend_ulong) → zend_async_trigger_event_t*
	 * Created lazily on first send/recv from a given thread.
	 * Coroutines subscribe to their thread's trigger event via add_callback.
	 * Other threads fire trigger() to wake them up (thread-safe uv_async_send). */
	HashTable receiver_triggers;  /* triggers for threads waiting to receive */
	HashTable sender_triggers;    /* triggers for threads waiting to send */

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
