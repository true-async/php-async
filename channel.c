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

#include "channel.h"
#include "php_async.h"
#include "exceptions.h"
#include "future.h"
#include "async_API.h"
#include "scheduler.h"
#include "coroutine.h"
#include "channel_arginfo.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "internal/zval_circular_buffer.h"

/**
 * Channel - CSP-style message passing between coroutines.
 *
 * Architecture (similar to Go):
 * - Two waiting queues: waiting_receivers, waiting_senders
 * - zend_async_resume_when registers with waker (so SUSPEND works)
 * - We control wake order via our queues
 * - On wake, coroutine retries and takes value from channel
 */

#define METHOD(name) PHP_METHOD(Async_Channel, name)
#define THIS_CHANNEL ASYNC_CHANNEL_FROM_OBJ(Z_OBJ_P(ZEND_THIS))

#define THROW_IF_CLOSED(channel) \
	if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(&(channel)->channel.event))) { \
		zend_throw_exception(async_ce_channel_exception, "Channel is closed", 0); \
		RETURN_THROWS(); \
	}

#define ENSURE_COROUTINE_CONTEXT \
	if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL)) { \
		async_scheduler_launch(); \
		if (UNEXPECTED(EG(exception) != NULL)) { \
			RETURN_THROWS(); \
		} \
	}

/* Mark Future token as used (non-blocking path) and throw if already fired. */
#define CANCELLATION_TOKEN_PREPARE(ct) \
	if ((ct) != NULL && UNEXPECTED(async_resolve_cancel_token(ct))) { \
		RETURN_THROWS(); \
	}

zend_class_entry *async_ce_channel = NULL;
zend_class_entry *async_ce_channel_exception = NULL;
static zend_object_handlers async_channel_handlers;

///////////////////////////////////////////////////////////////////////////////
// Waiter
///////////////////////////////////////////////////////////////////////////////

typedef struct
{
	zend_coroutine_event_callback_t callback; /* inherits from coroutine callback */
	zend_future_t *future;                    /* NULL for coroutine waiter */
} channel_waiter_t;

///////////////////////////////////////////////////////////////////////////////
// Helpers
///////////////////////////////////////////////////////////////////////////////

static zend_always_inline bool channel_is_buffered(async_channel_t *channel)
{
	return channel->capacity > 0;
}

static zend_always_inline bool channel_is_empty(async_channel_t *channel)
{
	if (channel_is_buffered(channel)) {
		return circular_buffer_is_empty(&channel->buffer);
	}
	return !channel->rendezvous_has_value;
}

static zend_always_inline bool channel_is_full(async_channel_t *channel)
{
	if (channel_is_buffered(channel)) {
		return circular_buffer_count(&channel->buffer) >= (size_t) channel->capacity;
	}
	return channel->rendezvous_has_value;
}

static zend_always_inline size_t channel_count(async_channel_t *channel)
{
	if (channel_is_buffered(channel)) {
		return circular_buffer_count(&channel->buffer);
	}
	return channel->rendezvous_has_value ? 1 : 0;
}

///////////////////////////////////////////////////////////////////////////////
// Queue operations
///////////////////////////////////////////////////////////////////////////////

static void channel_init_queues(async_channel_t *channel)
{
	channel->waiting_receivers.data = NULL;
	channel->waiting_receivers.length = 0;
	channel->waiting_receivers.capacity = 0;

	channel->waiting_senders.data = NULL;
	channel->waiting_senders.length = 0;
	channel->waiting_senders.capacity = 0;
}

static void channel_free_queues(async_channel_t *channel)
{
	if (channel->waiting_receivers.data) {
		efree(channel->waiting_receivers.data);
	}
	if (channel->waiting_senders.data) {
		efree(channel->waiting_senders.data);
	}
}

static void channel_queue_push(zend_async_callbacks_vector_t *queue, channel_waiter_t *waiter)
{
	if (queue->length >= queue->capacity) {
		uint32_t new_capacity = queue->capacity ? queue->capacity * 2 : 4;
		queue->data = erealloc(queue->data, new_capacity * sizeof(void *));
		queue->capacity = new_capacity;
	}
	waiter->callback.base.ref_count++;
	queue->data[queue->length++] = (zend_async_event_callback_t *) waiter;
}

static channel_waiter_t *channel_queue_pop(zend_async_callbacks_vector_t *queue)
{
	if (queue->length == 0) {
		return NULL;
	}
	channel_waiter_t *waiter = (channel_waiter_t *) queue->data[0];
	queue->length--;
	/* Swap with last element - O(1) instead of memmove O(n) */
	queue->data[0] = queue->data[queue->length];
	return waiter;
}

static bool channel_queue_remove(zend_async_callbacks_vector_t *queue, channel_waiter_t *waiter)
{
	for (uint32_t i = 0; i < queue->length; i++) {
		if (queue->data[i] == (zend_async_event_callback_t *) waiter) {
			queue->length--;
			queue->data[i] = queue->data[queue->length];
			return true;
		}
	}
	return false;
}

///////////////////////////////////////////////////////////////////////////////
// Wake operations
///////////////////////////////////////////////////////////////////////////////

/* Forward declarations */
static bool channel_wake_sender(async_channel_t *channel);

static bool channel_wake_receiver(async_channel_t *channel)
{
	channel_waiter_t *waiter = channel_queue_pop(&channel->waiting_receivers);
	if (waiter == NULL) {
		return false;
	}

	channel->channel.event.del_callback(&channel->channel.event, &waiter->callback.base);

	if (waiter->future != NULL) {
		/* Future waiter: take value from channel and complete future */
		zval value;
		if (channel_is_buffered(channel)) {
			zval_circular_buffer_pop(&channel->buffer, &value);
		} else {
			ZVAL_COPY(&value, &channel->rendezvous_value);
			zval_ptr_dtor(&channel->rendezvous_value);
			ZVAL_UNDEF(&channel->rendezvous_value);
			channel->rendezvous_has_value = false;
		}
		ZEND_FUTURE_COMPLETE(waiter->future, &value);
		zval_ptr_dtor(&value);
		channel_wake_sender(channel);
	} else {
		/* Coroutine waiter: just wake up */
		waiter->callback.base.callback(&channel->channel.event, &waiter->callback.base, NULL, NULL);
	}

	ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
	return true;
}

static bool channel_wake_sender(async_channel_t *channel)
{
	channel_waiter_t *waiter = channel_queue_pop(&channel->waiting_senders);
	if (waiter == NULL) {
		return false;
	}

	channel->channel.event.del_callback(&channel->channel.event, &waiter->callback.base);
	waiter->callback.base.callback(&channel->channel.event, &waiter->callback.base, NULL, NULL);
	ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
	return true;
}

static void channel_wake_all(async_channel_t *channel, zend_object *exception)
{
	channel_waiter_t *waiter;

	while ((waiter = channel_queue_pop(&channel->waiting_receivers)) != NULL) {
		channel->channel.event.del_callback(&channel->channel.event, &waiter->callback.base);
		if (waiter->future != NULL) {
			ZEND_FUTURE_REJECT(waiter->future, exception);
		} else {
			waiter->callback.base.callback(&channel->channel.event, &waiter->callback.base, NULL, exception);
		}
		ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
	}

	while ((waiter = channel_queue_pop(&channel->waiting_senders)) != NULL) {
		channel->channel.event.del_callback(&channel->channel.event, &waiter->callback.base);
		waiter->callback.base.callback(&channel->channel.event, &waiter->callback.base, NULL, exception);
		ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
	}

	ZEND_ASYNC_CALLBACKS_NOTIFY(&channel->channel.event, NULL, exception);
}

///////////////////////////////////////////////////////////////////////////////
// Wait operations
///////////////////////////////////////////////////////////////////////////////

static void
channel_wait_for(async_channel_t *channel, zend_async_callbacks_vector_t *queue, zend_object *cancellation_token)
{
	if (cancellation_token != NULL && UNEXPECTED(async_resolve_cancel_token(cancellation_token))) {
		return;
	}

	channel_waiter_t *waiter = ecalloc(1, sizeof(channel_waiter_t));
	waiter->callback.base.callback = zend_async_waker_callback_resolve;
	waiter->callback.base.ref_count = 1;
	waiter->callback.coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	waiter->callback.event = &channel->channel.event;
	waiter->future = NULL;

	channel_queue_push(queue, waiter);

	zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE,
						   &channel->channel.event,
						   false,
						   zend_async_waker_callback_resolve,
						   &waiter->callback);

	if (cancellation_token != NULL) {
		zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE,
							   ZEND_ASYNC_OBJECT_TO_EVENT(cancellation_token),
							   false,
							   zend_async_waker_callback_cancel,
							   NULL);
	}

	ZEND_ASYNC_SUSPEND();

	/* Cleanup after waking up */
	if (channel_queue_remove(queue, waiter)) {
		/* Was still in queue (cancellation/close case) - release queue's ref */
		ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
	}
	/* Release our initial ref */
	ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
}

#define CHANNEL_WAIT_FOR_DATA(ch, ct) channel_wait_for((ch), &(ch)->waiting_receivers, (ct))

#define CHANNEL_WAIT_FOR_SPACE(ch, ct) channel_wait_for((ch), &(ch)->waiting_senders, (ct))

///////////////////////////////////////////////////////////////////////////////
// Event handlers (for Awaitable interface)
///////////////////////////////////////////////////////////////////////////////

static bool channel_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_push(event, callback);
}

static bool channel_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_remove(event, callback);
}

static bool channel_dispose(zend_async_event_t *event)
{
	return true;
}

static bool channel_start(zend_async_event_t *event)
{
	return true;
}

static bool channel_stop(zend_async_event_t *event)
{
	return true;
}

static zend_string *channel_info(zend_async_event_t *event)
{
	const async_channel_t *channel = (async_channel_t *) event;

	return zend_strpprintf(0,
						   "Channel(capacity=%d, receivers=%u, senders=%u)",
						   channel->capacity,
						   channel->waiting_receivers.length,
						   channel->waiting_senders.length);
}

static void channel_event_init(async_channel_t *channel)
{
	zend_async_event_t *event = &channel->channel.event;
	memset(event, 0, sizeof(zend_async_event_t));

	event->flags = ZEND_ASYNC_EVENT_F_ZEND_OBJ;
	event->zend_object_offset = XtOffsetOf(async_channel_t, std);
	event->add_callback = channel_add_callback;
	event->del_callback = channel_del_callback;
	event->start = channel_start;
	event->stop = channel_stop;
	event->dispose = channel_dispose;
	event->info = channel_info;
}

///////////////////////////////////////////////////////////////////////////////
// Object handlers
///////////////////////////////////////////////////////////////////////////////

static HashTable *async_channel_get_gc(zend_object *object, zval **table, int *num)
{
	async_channel_t *channel = ASYNC_CHANNEL_FROM_OBJ(object);

	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();

	if (channel_is_buffered(channel)) {
		circular_buffer_t *cb = &channel->buffer;
		size_t idx = cb->tail;
		while (idx != cb->head) {
			zval *val = (zval *) ((char *) cb->data + idx * cb->item_size);
			zend_get_gc_buffer_add_zval(buf, val);
			idx = (idx + 1) & (cb->capacity - 1);
		}
	} else if (channel->rendezvous_has_value) {
		zend_get_gc_buffer_add_zval(buf, &channel->rendezvous_value);
	}

	zend_get_gc_buffer_use(buf, table, num);
	return NULL;
}

static zend_object *async_channel_create_object(zend_class_entry *ce)
{
	async_channel_t *channel = zend_object_alloc(sizeof(async_channel_t), ce);

	zend_object_std_init(&channel->std, ce);
	channel->std.handlers = &async_channel_handlers;

	channel_event_init(channel);
	channel_init_queues(channel);

	channel->capacity = 0;
	ZVAL_UNDEF(&channel->rendezvous_value);
	channel->rendezvous_has_value = false;

	return &channel->std;
}

static void async_channel_dtor_object(zend_object *object)
{
	async_channel_t *channel = ASYNC_CHANNEL_FROM_OBJ(object);

	if (!ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		ZEND_ASYNC_EVENT_SET_CLOSED(&channel->channel.event);
		zend_object *ex = async_new_exception(async_ce_channel_exception, "Channel is closed");
		channel_wake_all(channel, ex);
		OBJ_RELEASE(ex);
	}

	zend_object_std_dtor(object);
}

static void async_channel_free_object(zend_object *object)
{
	async_channel_t *channel = ASYNC_CHANNEL_FROM_OBJ(object);

	zend_async_callbacks_free(&channel->channel.event);
	channel_free_queues(channel);

	if (channel_is_buffered(channel)) {
		zval tmp;
		while (!circular_buffer_is_empty(&channel->buffer) &&
			   zval_circular_buffer_pop(&channel->buffer, &tmp) == SUCCESS) {
			zval_ptr_dtor(&tmp);
		}
		circular_buffer_dtor(&channel->buffer);
	} else if (channel->rendezvous_has_value) {
		zval_ptr_dtor(&channel->rendezvous_value);
	}
}

///////////////////////////////////////////////////////////////////////////////
// Iterator
///////////////////////////////////////////////////////////////////////////////

typedef struct
{
	zend_object_iterator it;
	async_channel_t *channel;
	zval current;
	bool valid;
	bool started;
} channel_iterator_t;

static void channel_iterator_dtor(zend_object_iterator *iter)
{
	channel_iterator_t *iterator = (channel_iterator_t *) iter;
	zval_ptr_dtor(&iterator->current);
	zval_ptr_dtor(&iter->data);
}

static zend_result channel_iterator_valid(zend_object_iterator *iter)
{
	return ((channel_iterator_t *) iter)->valid ? SUCCESS : FAILURE;
}

static zval *channel_iterator_get_current_data(zend_object_iterator *iter)
{
	return &((channel_iterator_t *) iter)->current;
}

static void channel_iterator_get_current_key(zend_object_iterator *iter, zval *key)
{
	ZVAL_NULL(key);
}

static void channel_iterator_move_forward(zend_object_iterator *iter)
{
	channel_iterator_t *iterator = (channel_iterator_t *) iter;
	async_channel_t *channel = iterator->channel;

	zval_ptr_dtor(&iterator->current);
	ZVAL_UNDEF(&iterator->current);

retry:
	if (!channel_is_empty(channel)) {
		if (channel_is_buffered(channel)) {
			zval_circular_buffer_pop(&channel->buffer, &iterator->current);
		} else {
			ZVAL_COPY(&iterator->current, &channel->rendezvous_value);
			zval_ptr_dtor(&channel->rendezvous_value);
			ZVAL_UNDEF(&channel->rendezvous_value);
			channel->rendezvous_has_value = false;
		}
		channel_wake_sender(channel);
		iterator->valid = true;
		return;
	}

	if (ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		iterator->valid = false;
		return;
	}

	CHANNEL_WAIT_FOR_DATA(channel, 0);

	if (EG(exception)) {
		iterator->valid = false;
		return;
	}

	goto retry;
}

static void channel_iterator_rewind(zend_object_iterator *iter)
{
	channel_iterator_t *iterator = (channel_iterator_t *) iter;
	if (!iterator->started) {
		iterator->started = true;
		channel_iterator_move_forward(iter);
	}
}

static const zend_object_iterator_funcs channel_iterator_funcs = {
	.dtor = channel_iterator_dtor,
	.valid = channel_iterator_valid,
	.get_current_data = channel_iterator_get_current_data,
	.get_current_key = channel_iterator_get_current_key,
	.move_forward = channel_iterator_move_forward,
	.rewind = channel_iterator_rewind,
};

static zend_object_iterator *channel_get_iterator(zend_class_entry *ce, zval *object, int by_ref)
{
	if (by_ref) {
		zend_throw_error(NULL, "Cannot iterate channel by reference");
		return NULL;
	}

	channel_iterator_t *iterator = ecalloc(1, sizeof(channel_iterator_t));
	zend_iterator_init(&iterator->it);

	iterator->it.funcs = &channel_iterator_funcs;
	ZVAL_COPY(&iterator->it.data, object);
	iterator->channel = ASYNC_CHANNEL_FROM_OBJ(Z_OBJ_P(object));
	ZVAL_UNDEF(&iterator->current);
	iterator->valid = true;
	iterator->started = false;

	return &iterator->it;
}

///////////////////////////////////////////////////////////////////////////////
// PHP Methods
///////////////////////////////////////////////////////////////////////////////

METHOD(__construct)
{
	zend_long capacity = 0;

	ZEND_PARSE_PARAMETERS_START(0, 1)
	Z_PARAM_OPTIONAL
	Z_PARAM_LONG(capacity)
	ZEND_PARSE_PARAMETERS_END();

	if (capacity < 0) {
		zend_argument_value_error(1, "must be >= 0");
		RETURN_THROWS();
	}

	async_channel_t *channel = THIS_CHANNEL;
	channel->capacity = (int32_t) capacity;

	if (capacity > 0) {
		/* circular_buffer uses one slot as sentinel, so allocate capacity+1 */
		if (circular_buffer_ctor(&channel->buffer, capacity + 1, sizeof(zval), &zend_std_persistent_allocator) ==
			FAILURE) {
			zend_throw_error(NULL, "Failed to allocate channel buffer");
			RETURN_THROWS();
		}
	}
}

METHOD(send)
{
	zval *value;
	zend_object *cancellation_token = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
	Z_PARAM_ZVAL(value)
	Z_PARAM_OPTIONAL
	Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation_token, async_ce_completable)
	ZEND_PARSE_PARAMETERS_END();

	ENSURE_COROUTINE_CONTEXT
	CANCELLATION_TOKEN_PREPARE(cancellation_token)

	async_channel_t *channel = THIS_CHANNEL;

retry:
	THROW_IF_CLOSED(channel)

	if (channel_is_buffered(channel)) {
		if (!channel_is_full(channel)) {
			zval_circular_buffer_push(&channel->buffer, value, false);
			channel_wake_receiver(channel);
			return;
		}
	} else {
		if (!channel->rendezvous_has_value) {
			ZVAL_COPY(&channel->rendezvous_value, value);
			channel->rendezvous_has_value = true;
			channel_wake_receiver(channel);
			return;
		}
	}

	CHANNEL_WAIT_FOR_SPACE(channel, cancellation_token);

	if (EG(exception)) {
		RETURN_THROWS();
	}

	goto retry;
}

METHOD(sendAsync)
{
	zval *value;

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_ZVAL(value)
	ZEND_PARSE_PARAMETERS_END();

	async_channel_t *channel = THIS_CHANNEL;

	if (ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		RETURN_FALSE;
	}

	if (channel_is_buffered(channel)) {
		if (channel_is_full(channel)) {
			RETURN_FALSE;
		}
		zval_circular_buffer_push(&channel->buffer, value, false);
		channel_wake_receiver(channel);
		RETURN_TRUE;
	}

	if (channel->rendezvous_has_value) {
		RETURN_FALSE;
	}

	ZVAL_COPY(&channel->rendezvous_value, value);
	channel->rendezvous_has_value = true;
	channel_wake_receiver(channel);
	RETURN_TRUE;
}

METHOD(recv)
{
	zend_object *cancellation_token = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
	Z_PARAM_OPTIONAL
	Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation_token, async_ce_completable)
	ZEND_PARSE_PARAMETERS_END();

	ENSURE_COROUTINE_CONTEXT
	CANCELLATION_TOKEN_PREPARE(cancellation_token)

	async_channel_t *channel = THIS_CHANNEL;

retry:
	if (!channel_is_empty(channel)) {
		if (channel_is_buffered(channel)) {
			zval_circular_buffer_pop(&channel->buffer, return_value);
		} else {
			ZVAL_COPY(return_value, &channel->rendezvous_value);
			zval_ptr_dtor(&channel->rendezvous_value);
			ZVAL_UNDEF(&channel->rendezvous_value);
			channel->rendezvous_has_value = false;
		}
		channel_wake_sender(channel);
		return;
	}

	THROW_IF_CLOSED(channel)

	CHANNEL_WAIT_FOR_DATA(channel, cancellation_token);

	if (EG(exception)) {
		RETURN_THROWS();
	}

	goto retry;
}

METHOD(recvAsync)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_channel_t *channel = THIS_CHANNEL;

	zend_future_t *future = ZEND_ASYNC_NEW_FUTURE(false);
	if (future == NULL) {
		RETURN_THROWS();
	}

	if (!channel_is_empty(channel)) {
		zval result;
		if (channel_is_buffered(channel)) {
			zval_circular_buffer_pop(&channel->buffer, &result);
		} else {
			ZVAL_COPY(&result, &channel->rendezvous_value);
			zval_ptr_dtor(&channel->rendezvous_value);
			ZVAL_UNDEF(&channel->rendezvous_value);
			channel->rendezvous_has_value = false;
		}
		channel_wake_sender(channel);
		ZEND_FUTURE_COMPLETE(future, &result);
		zval_ptr_dtor(&result);
	} else if (ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		zend_object *ex = async_new_exception(async_ce_channel_exception, "Channel is closed");
		ZEND_FUTURE_REJECT(future, ex);
		OBJ_RELEASE(ex);
	} else {
		/* Channel is empty but open - register pending future waiter */
		channel_waiter_t *waiter = ecalloc(1, sizeof(channel_waiter_t));
		waiter->callback.base.callback = NULL; /* Not used for future waiters */
		waiter->callback.base.ref_count = 1;
		waiter->callback.coroutine = NULL;
		waiter->callback.event = &channel->channel.event;
		waiter->future = future;

		channel_queue_push(&channel->waiting_receivers, waiter);
		ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base); /* Release our ref, queue holds one */
	}

	RETURN_OBJ(ZEND_ASYNC_NEW_FUTURE_OBJ(future));
}

METHOD(close)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_channel_t *channel = THIS_CHANNEL;

	if (ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		return;
	}

	ZEND_ASYNC_EVENT_SET_CLOSED(&channel->channel.event);

	zend_object *ex = async_new_exception(async_ce_channel_exception, "Channel is closed");
	channel_wake_all(channel, ex);
	OBJ_RELEASE(ex);
}

METHOD(isClosed)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_BOOL(ZEND_ASYNC_EVENT_IS_CLOSED(&THIS_CHANNEL->channel.event));
}

METHOD(capacity)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_LONG(THIS_CHANNEL->capacity);
}

METHOD(count)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_LONG(channel_count(THIS_CHANNEL));
}

METHOD(isEmpty)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_BOOL(channel_is_empty(THIS_CHANNEL));
}

METHOD(isFull)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_BOOL(channel_is_full(THIS_CHANNEL));
}

METHOD(getIterator)
{
	ZEND_PARSE_PARAMETERS_NONE();

	zend_object_iterator *iter = channel_get_iterator(async_ce_channel, ZEND_THIS, 0);
	if (iter == NULL) {
		RETURN_THROWS();
	}

	zval iterator_zval;
	ZVAL_OBJ(&iterator_zval, &iter->std);
	RETURN_ZVAL(&iterator_zval, 1, 1);
}

///////////////////////////////////////////////////////////////////////////////
// Registration
///////////////////////////////////////////////////////////////////////////////

void async_register_channel_ce(void)
{
	async_ce_channel_exception = register_class_Async_ChannelException(async_ce_async_exception);

	async_ce_channel = register_class_Async_Channel(async_ce_awaitable, zend_ce_aggregate, zend_ce_countable);

	async_ce_channel->create_object = async_channel_create_object;
	async_ce_channel->get_iterator = channel_get_iterator;

	memcpy(&async_channel_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	async_channel_handlers.offset = XtOffsetOf(async_channel_t, std);
	async_channel_handlers.get_gc = async_channel_get_gc;
	async_channel_handlers.dtor_obj = async_channel_dtor_object;
	async_channel_handlers.free_obj = async_channel_free_object;
	async_channel_handlers.clone_obj = NULL;
}
