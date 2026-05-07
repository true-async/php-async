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
#ifndef ASYNC_CHANNEL_H
#define ASYNC_CHANNEL_H

#include "php_async_api.h"
#include <Zend/zend_async_API.h>
#include "internal/circular_buffer.h"

/* Channel event types passed as result in notify */
typedef enum
{
	ASYNC_CHANNEL_EVENT_DATA_PUSHED = 1,
	ASYNC_CHANNEL_EVENT_DATA_POPPED = 2,
	ASYNC_CHANNEL_EVENT_CLOSED = 3
} async_channel_event_t;

/* Why the channel closed. Mirrors the Async\ChannelCloseReason PHP enum. */
typedef enum
{
	CHANNEL_CLOSE_EXPLICIT,
	CHANNEL_CLOSE_DISPOSED,
	CHANNEL_CLOSE_NO_PRODUCERS,
	CHANNEL_CLOSE_NO_CONSUMERS,
	CHANNEL_CLOSE_DEADLOCK,
	CHANNEL_CLOSE_SCOPE_DISPOSED,
	CHANNEL_CLOSE_REASON_COUNT
} channel_close_reason_t;

/* Extended callback used to subscribe a channel to its owner scope's event.
 * The scope_event back-pointer lets the channel del_callback itself when it
 * dies first; the dispose handler clears it when the scope dies first, so
 * neither side ever dereferences freed memory. */
typedef struct
{
	zend_async_event_callback_t base;
	zend_async_event_t *scope_event;
} channel_scope_callback_t;

typedef struct _async_channel_s async_channel_t;

struct _async_channel_s
{
	/* ABI structure (must be first) */
	zend_async_channel_t channel;

	/* Channel capacity: 0 = rendezvous, >0 = buffered */
	int32_t capacity;

	/* For buffered channels (capacity > 0) */
	circular_buffer_t buffer;

	/* For rendezvous channels (capacity = 0): single value storage */
	zval rendezvous_value;
	bool rendezvous_has_value;

	/* Waiting queues (like Go's recvq/sendq) */
	zend_async_callbacks_vector_t waiting_receivers; /* coroutines waiting for data */
	zend_async_callbacks_vector_t waiting_senders;   /* coroutines waiting for space */

	/* Per-channel deadlock timer. */
	int32_t no_producer_timeout_ms;
	int32_t no_consumer_timeout_ms;
	bool hard_timeouts;
	zend_async_timer_event_t *deadlock_timer;
	zend_async_event_callback_t deadlock_callback;
	channel_close_reason_t pending_timeout_reason; /* which side the live timer guards */

	/* Reason recorded once the channel actually closes. */
	channel_close_reason_t close_reason;

	/* Owner-scope binding: channel auto-closes when the scope it was created
	 * in is disposed/cancelled. scope_close_callback.scope_event == NULL when
	 * not bound (or when the scope already fired the callback and died). */
	channel_scope_callback_t scope_close_callback;

	/* PHP object handle (must be last for final class) */
	zend_object std;
};

/* Class entries */
extern zend_class_entry *async_ce_channel;
extern zend_class_entry *async_ce_channel_exception;
extern zend_class_entry *async_ce_channel_close_reason;

/* Convert zend_object to async_channel_t */
#define ASYNC_CHANNEL_FROM_OBJ(obj) ((async_channel_t *) ((char *) (obj) - XtOffsetOf(async_channel_t, std)))

/* Convert zend_async_channel_t to async_channel_t */
#define ASYNC_CHANNEL_FROM_ZEND(zend_channel) ((async_channel_t *) (zend_channel))

/* Registration function */
void async_register_channel_ce(void);

/* Bulk-close every soft-timer channel currently in potential-deadlock state
 * with reason CHANNEL_CLOSE_DEADLOCK. Returns true if any were closed.
 * Called by the scheduler when it detects a global deadlock so blocked
 * coroutines surface a ChannelException instead of a generic Deadlock error. */
bool async_channel_resolve_deadlocks(void);

#endif /* ASYNC_CHANNEL_H */
