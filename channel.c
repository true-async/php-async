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

zend_class_entry *async_ce_channel = NULL;
zend_class_entry *async_ce_channel_exception = NULL;
static zend_object_handlers async_channel_handlers;

///////////////////////////////////////////////////////////////////////////////
// Waiter
///////////////////////////////////////////////////////////////////////////////

typedef struct {
	zend_coroutine_event_callback_t base;
	async_channel_t *channel;
} channel_waiter_t;

static void channel_waiter_dummy_callback(zend_async_event_t *event,
	zend_async_event_callback_t *callback, void *result, zend_object *exception)
{
}

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
		return circular_buffer_count(&channel->buffer) >= (size_t)channel->capacity;
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

static zend_always_inline bool channel_has_waiting_receivers(async_channel_t *channel)
{
	return channel->waiting_receivers.length > 0;
}

static zend_always_inline bool channel_has_waiting_senders(async_channel_t *channel)
{
	return channel->waiting_senders.length > 0;
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
	queue->data[queue->length++] = (zend_async_event_callback_t *)waiter;
}

static channel_waiter_t *channel_queue_pop(zend_async_callbacks_vector_t *queue)
{
	if (queue->length == 0) {
		return NULL;
	}
	channel_waiter_t *waiter = (channel_waiter_t *)queue->data[0];
	queue->length--;
	if (queue->length > 0) {
		memmove(queue->data, queue->data + 1, queue->length * sizeof(void *));
	}
	return waiter;
}

///////////////////////////////////////////////////////////////////////////////
// Wake operations
///////////////////////////////////////////////////////////////////////////////

static bool channel_wake_receiver(async_channel_t *channel)
{
	channel_waiter_t *waiter = channel_queue_pop(&channel->waiting_receivers);
	if (waiter == NULL) {
		return false;
	}

	channel->channel.event.del_callback(&channel->channel.event, &waiter->base.base);
	async_coroutine_resume(waiter->base.coroutine, NULL, false);
	efree(waiter);
	return true;
}

static bool channel_wake_sender(async_channel_t *channel)
{
	channel_waiter_t *waiter = channel_queue_pop(&channel->waiting_senders);
	if (waiter == NULL) {
		return false;
	}

	channel->channel.event.del_callback(&channel->channel.event, &waiter->base.base);
	async_coroutine_resume(waiter->base.coroutine, NULL, false);
	efree(waiter);
	return true;
}

static void channel_wake_all(async_channel_t *channel, zend_object *exception)
{
	channel_waiter_t *waiter;

	while ((waiter = channel_queue_pop(&channel->waiting_receivers)) != NULL) {
		channel->channel.event.del_callback(&channel->channel.event, &waiter->base.base);
		async_coroutine_resume(waiter->base.coroutine, exception, false);
		efree(waiter);
	}

	while ((waiter = channel_queue_pop(&channel->waiting_senders)) != NULL) {
		channel->channel.event.del_callback(&channel->channel.event, &waiter->base.base);
		async_coroutine_resume(waiter->base.coroutine, exception, false);
		efree(waiter);
	}

	ZEND_ASYNC_CALLBACKS_NOTIFY(&channel->channel.event, NULL, exception);
}

///////////////////////////////////////////////////////////////////////////////
// Wait operations
///////////////////////////////////////////////////////////////////////////////

static void channel_wait_for_data(async_channel_t *channel)
{
	channel_waiter_t *waiter = ecalloc(1, sizeof(channel_waiter_t));
	waiter->base.base.callback = channel_waiter_dummy_callback;
	waiter->base.base.ref_count = 1;
	waiter->base.coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	waiter->base.event = &channel->channel.event;
	waiter->channel = channel;

	channel_queue_push(&channel->waiting_receivers, waiter);

	zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE, &channel->channel.event,
		false, channel_waiter_dummy_callback, &waiter->base);

	ZEND_ASYNC_SUSPEND();
}

static void channel_wait_for_space(async_channel_t *channel)
{
	channel_waiter_t *waiter = ecalloc(1, sizeof(channel_waiter_t));
	waiter->base.base.callback = channel_waiter_dummy_callback;
	waiter->base.base.ref_count = 1;
	waiter->base.coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	waiter->base.event = &channel->channel.event;
	waiter->channel = channel;

	channel_queue_push(&channel->waiting_senders, waiter);

	zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE, &channel->channel.event,
		false, channel_waiter_dummy_callback, &waiter->base);

	ZEND_ASYNC_SUSPEND();
}

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

static void channel_event_init(async_channel_t *channel)
{
	zend_async_event_t *event = &channel->channel.event;
	memset(event, 0, sizeof(zend_async_event_t));

	event->flags = ZEND_ASYNC_EVENT_F_ZEND_OBJ;
	event->zend_object_offset = XtOffsetOf(async_channel_t, std);
	event->add_callback = channel_add_callback;
	event->del_callback = channel_del_callback;
	event->dispose = channel_dispose;
}

///////////////////////////////////////////////////////////////////////////////
// Object handlers
///////////////////////////////////////////////////////////////////////////////

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

static void async_channel_free_object(zend_object *object)
{
	async_channel_t *channel = ASYNC_CHANNEL_FROM_OBJ(object);

	if (!ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		ZEND_ASYNC_EVENT_SET_CLOSED(&channel->channel.event);
		zval ex;
		object_init_ex(&ex, async_ce_channel_exception);
		zend_update_property_string(async_ce_channel_exception, Z_OBJ(ex),
			"message", sizeof("message") - 1, "Channel is closed");
		channel_wake_all(channel, Z_OBJ(ex));
		zval_ptr_dtor(&ex);
	}

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

	zend_object_std_dtor(object);
}

///////////////////////////////////////////////////////////////////////////////
// Iterator
///////////////////////////////////////////////////////////////////////////////

typedef struct {
	zend_object_iterator it;
	async_channel_t *channel;
	zval current;
	bool valid;
	bool started;
} channel_iterator_t;

static void channel_iterator_dtor(zend_object_iterator *iter)
{
	channel_iterator_t *iterator = (channel_iterator_t *)iter;
	zval_ptr_dtor(&iterator->current);
	zval_ptr_dtor(&iter->data);
}

static zend_result channel_iterator_valid(zend_object_iterator *iter)
{
	return ((channel_iterator_t *)iter)->valid ? SUCCESS : FAILURE;
}

static zval *channel_iterator_get_current_data(zend_object_iterator *iter)
{
	return &((channel_iterator_t *)iter)->current;
}

static void channel_iterator_get_current_key(zend_object_iterator *iter, zval *key)
{
	ZVAL_NULL(key);
}

static void channel_iterator_move_forward(zend_object_iterator *iter)
{
	channel_iterator_t *iterator = (channel_iterator_t *)iter;
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

	channel_wait_for_data(channel);

	if (EG(exception)) {
		iterator->valid = false;
		return;
	}

	goto retry;
}

static void channel_iterator_rewind(zend_object_iterator *iter)
{
	channel_iterator_t *iterator = (channel_iterator_t *)iter;
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
	channel->capacity = (int32_t)capacity;

	if (capacity > 0) {
		/* circular_buffer uses one slot as sentinel, so allocate capacity+1 */
		if (circular_buffer_ctor(&channel->buffer, capacity + 1, sizeof(zval),
				&zend_std_persistent_allocator) == FAILURE) {
			zend_throw_error(NULL, "Failed to allocate channel buffer");
			RETURN_THROWS();
		}
	}
}

METHOD(send)
{
	zval *value;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(value)
	ZEND_PARSE_PARAMETERS_END();

	ENSURE_COROUTINE_CONTEXT

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

	channel_wait_for_space(channel);

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
	ZEND_PARSE_PARAMETERS_NONE();

	ENSURE_COROUTINE_CONTEXT

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

	channel_wait_for_data(channel);

	if (EG(exception)) {
		RETURN_THROWS();
	}

	goto retry;
}

METHOD(recvAsync)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_channel_t *channel = THIS_CHANNEL;

	async_future_state_t *state = async_future_state_create();
	if (state == NULL) {
		RETURN_THROWS();
	}

	zend_future_t *future = (zend_future_t *)state->event;

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
		zval ex;
		object_init_ex(&ex, async_ce_channel_exception);
		zend_update_property_string(async_ce_channel_exception, Z_OBJ(ex),
			"message", sizeof("message") - 1, "Channel is closed");
		ZEND_FUTURE_REJECT(future, Z_OBJ(ex));
		zval_ptr_dtor(&ex);
	}
	/* TODO: pending future - connect to channel */

	RETURN_OBJ(&state->std);
}

METHOD(close)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_channel_t *channel = THIS_CHANNEL;

	if (ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		return;
	}

	ZEND_ASYNC_EVENT_SET_CLOSED(&channel->channel.event);

	zval ex;
	object_init_ex(&ex, async_ce_channel_exception);
	zend_update_property_string(async_ce_channel_exception, Z_OBJ(ex),
		"message", sizeof("message") - 1, "Channel is closed");
	channel_wake_all(channel, Z_OBJ(ex));
	zval_ptr_dtor(&ex);
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

	async_ce_channel = register_class_Async_Channel(
		async_ce_awaitable,
		zend_ce_aggregate,
		zend_ce_countable
	);

	async_ce_channel->create_object = async_channel_create_object;
	async_ce_channel->get_iterator = channel_get_iterator;

	memcpy(&async_channel_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	async_channel_handlers.offset = XtOffsetOf(async_channel_t, std);
	async_channel_handlers.free_obj = async_channel_free_object;
	async_channel_handlers.clone_obj = NULL;
}
