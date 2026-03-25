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

#include "thread_channel.h"
#include "php_async.h"
#include "exceptions.h"
#include "thread.h"
#include "async_API.h"
#include "scheduler.h"
#include "coroutine.h"
#include "thread_channel_arginfo.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "internal/zval_circular_buffer.h"

#define METHOD(name) PHP_METHOD(Async_ThreadChannel, name)
#define THIS_CHANNEL() (ASYNC_THREAD_CHANNEL_FROM_OBJ(Z_OBJ_P(ZEND_THIS))->channel)

#define THROW_IF_CLOSED(ch) \
	if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(&(ch)->channel.event))) { \
		zend_throw_exception(async_ce_thread_channel_exception, "ThreadChannel is closed", 0); \
		RETURN_THROWS(); \
	}

#define ENSURE_COROUTINE_CONTEXT \
	if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL)) { \
		async_scheduler_launch(); \
		if (UNEXPECTED(EG(exception) != NULL)) { \
			RETURN_THROWS(); \
		} \
	}

zend_class_entry *async_ce_thread_channel = NULL;
zend_class_entry *async_ce_thread_channel_exception = NULL;
static zend_object_handlers async_thread_channel_handlers;

///////////////////////////////////////////////////////////////////////////////
// Waiter queue helpers (pemalloc)
///////////////////////////////////////////////////////////////////////////////

static void waiter_queue_init(thread_channel_waiter_queue_t *queue)
{
	queue->data = NULL;
	queue->length = 0;
	queue->capacity = 0;
}

static void waiter_queue_push(thread_channel_waiter_queue_t *queue, thread_channel_waiter_t *waiter)
{
	if (queue->length >= queue->capacity) {
		uint32_t new_capacity = queue->capacity ? queue->capacity * 2 : 4;
		queue->data = perealloc(queue->data, new_capacity * sizeof(thread_channel_waiter_t), 1);
		queue->capacity = new_capacity;
	}
	queue->data[queue->length++] = *waiter;
}

static bool waiter_queue_pop(thread_channel_waiter_queue_t *queue, thread_channel_waiter_t *out)
{
	if (queue->length == 0) {
		return false;
	}
	*out = queue->data[0];
	queue->length--;
	/* Swap with last — O(1) */
	queue->data[0] = queue->data[queue->length];
	return true;
}

static void waiter_queue_destroy(thread_channel_waiter_queue_t *queue)
{
	if (queue->data) {
		pefree(queue->data, 1);
		queue->data = NULL;
	}
	queue->length = 0;
	queue->capacity = 0;
}

///////////////////////////////////////////////////////////////////////////////
// Thread channel allocation / destruction
///////////////////////////////////////////////////////////////////////////////

static async_thread_channel_t *thread_channel_create(int32_t capacity)
{
	async_thread_channel_t *ch = pecalloc(1, sizeof(async_thread_channel_t), 1);

	ch->capacity = capacity;
	zend_atomic_int_store(&ch->ref_count, 1);

	/* Init mutex */
	pthread_mutex_init(&ch->mutex, NULL);

	/* Init ring buffer with pemalloc allocator — +1 for sentinel slot */
	circular_buffer_ctor(&ch->buffer, capacity + 1, sizeof(zval), &zend_std_persistent_allocator);

	/* Init thread handle mapping (persistent) */
	zend_hash_init(&ch->thread_handles, 4, NULL, NULL, 1);

	/* Init waiter queues */
	waiter_queue_init(&ch->waiting_receivers);
	waiter_queue_init(&ch->waiting_senders);

	return ch;
}

static void thread_channel_destroy(async_thread_channel_t *ch)
{
	/* Drain buffer — release all transferred zvals */
	zval tmp;
	while (circular_buffer_is_not_empty(&ch->buffer) &&
		   circular_buffer_pop(&ch->buffer, &tmp) == SUCCESS) {
		async_thread_release_transferred_zval(&tmp);
	}
	circular_buffer_dtor(&ch->buffer);

	/* TODO: close and free all uv_async_t handles in thread_handles */
	zend_hash_destroy(&ch->thread_handles);

	/* Destroy waiter queues */
	waiter_queue_destroy(&ch->waiting_receivers);
	waiter_queue_destroy(&ch->waiting_senders);

	/* Destroy mutex */
	pthread_mutex_destroy(&ch->mutex);

	pefree(ch, 1);
}

static void thread_channel_addref(async_thread_channel_t *ch)
{
	int old;
	do {
		old = zend_atomic_int_load(&ch->ref_count);
	} while (!zend_atomic_int_compare_exchange(&ch->ref_count, &old, old + 1));
}

static void thread_channel_delref(async_thread_channel_t *ch)
{
	int old;
	do {
		old = zend_atomic_int_load(&ch->ref_count);
	} while (!zend_atomic_int_compare_exchange(&ch->ref_count, &old, old - 1));

	if (old == 1) {
		thread_channel_destroy(ch);
	}
}

///////////////////////////////////////////////////////////////////////////////
// Event handlers
///////////////////////////////////////////////////////////////////////////////

static bool thread_channel_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_push(event, callback);
}

static bool thread_channel_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_remove(event, callback);
}

static bool thread_channel_dispose(zend_async_event_t *event)
{
	return true;
}

static bool thread_channel_start(zend_async_event_t *event)
{
	return true;
}

static bool thread_channel_stop(zend_async_event_t *event)
{
	return true;
}

static zend_string *thread_channel_info(zend_async_event_t *event)
{
	/* Event is first member of async_thread_channel_t via channel.event */
	async_thread_channel_t *ch = (async_thread_channel_t *) event;

	return zend_strpprintf(0,
		"ThreadChannel(capacity=%d, receivers=%u, senders=%u)",
		ch->capacity,
		ch->waiting_receivers.length,
		ch->waiting_senders.length);
}

static void thread_channel_event_init(async_thread_channel_t *ch, thread_channel_object_t *obj)
{
	zend_async_event_t *event = &ch->channel.event;
	memset(event, 0, sizeof(zend_async_event_t));

	event->flags = ZEND_ASYNC_EVENT_F_ZEND_OBJ;
	event->zend_object_offset = XtOffsetOf(thread_channel_object_t, std);
	event->add_callback = thread_channel_add_callback;
	event->del_callback = thread_channel_del_callback;
	event->start = thread_channel_start;
	event->stop = thread_channel_stop;
	event->dispose = thread_channel_dispose;
	event->info = thread_channel_info;
}

///////////////////////////////////////////////////////////////////////////////
// Object handlers
///////////////////////////////////////////////////////////////////////////////

static HashTable *async_thread_channel_get_gc(zend_object *object, zval **table, int *num)
{
	/* Thread channel data is in persistent memory — not GC-tracked */
	*table = NULL;
	*num = 0;
	return NULL;
}

static zend_object *async_thread_channel_create_object(zend_class_entry *ce)
{
	thread_channel_object_t *obj = zend_object_alloc(sizeof(thread_channel_object_t), ce);

	zend_object_std_init(&obj->std, ce);
	obj->std.handlers = &async_thread_channel_handlers;
	obj->channel = NULL; /* Allocated in __construct */

	return &obj->std;
}

static void async_thread_channel_dtor_object(zend_object *object)
{
	thread_channel_object_t *obj = ASYNC_THREAD_CHANNEL_FROM_OBJ(object);

	if (obj->channel != NULL && !ZEND_ASYNC_EVENT_IS_CLOSED(&obj->channel->channel.event)) {
		ZEND_ASYNC_EVENT_SET_CLOSED(&obj->channel->channel.event);
		/* TODO: wake all waiters with exception */
	}

	zend_object_std_dtor(object);
}

static void async_thread_channel_free_object(zend_object *object)
{
	thread_channel_object_t *obj = ASYNC_THREAD_CHANNEL_FROM_OBJ(object);

	if (obj->channel != NULL) {
		zend_async_callbacks_free(&obj->channel->channel.event);
		thread_channel_delref(obj->channel);
		obj->channel = NULL;
	}
}

///////////////////////////////////////////////////////////////////////////////
// PHP Methods
///////////////////////////////////////////////////////////////////////////////

METHOD(__construct)
{
	zend_long capacity = 16;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(capacity)
	ZEND_PARSE_PARAMETERS_END();

	if (capacity < 1) {
		zend_argument_value_error(1, "must be >= 1");
		RETURN_THROWS();
	}

	thread_channel_object_t *obj = ASYNC_THREAD_CHANNEL_FROM_OBJ(Z_OBJ_P(ZEND_THIS));
	obj->channel = thread_channel_create((int32_t) capacity);

	thread_channel_event_init(obj->channel, obj);
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

	async_thread_channel_t *ch = THIS_CHANNEL();
	THROW_IF_CLOSED(ch)

	pthread_mutex_lock(&ch->mutex);

	/* Check closed under lock */
	if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(&ch->channel.event))) {
		pthread_mutex_unlock(&ch->mutex);
		zend_throw_exception(async_ce_thread_channel_exception, "ThreadChannel is closed", 0);
		RETURN_THROWS();
	}

	/* Transfer value to persistent memory */
	zval persistent_copy;
	async_thread_transfer_zval(&persistent_copy, value);

	if (circular_buffer_count(&ch->buffer) < (size_t) ch->capacity) {
		/* Buffer has space — push and notify waiting receiver */
		circular_buffer_push(&ch->buffer, &persistent_copy, false);

		thread_channel_waiter_t waiter;
		if (waiter_queue_pop(&ch->waiting_receivers, &waiter)) {
			pthread_mutex_unlock(&ch->mutex);
			/* TODO: uv_async_send to waiter's thread to resume coroutine */
			(void) waiter;
			return;
		}

		pthread_mutex_unlock(&ch->mutex);
		return;
	}

	/* Buffer is full — need to wait for space */
	/* TODO: register as waiting sender, unlock, ZEND_ASYNC_SUSPEND(), retry */
	pthread_mutex_unlock(&ch->mutex);
	async_thread_release_transferred_zval(&persistent_copy);
	zend_throw_exception(async_ce_thread_channel_exception,
		"ThreadChannel is full (back-pressure not yet implemented)", 0);
	RETURN_THROWS();
}

METHOD(recv)
{
	zend_object *cancellation_token = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation_token, async_ce_completable)
	ZEND_PARSE_PARAMETERS_END();

	ENSURE_COROUTINE_CONTEXT

	async_thread_channel_t *ch = THIS_CHANNEL();

	pthread_mutex_lock(&ch->mutex);

	if (circular_buffer_is_not_empty(&ch->buffer)) {
		/* Data available — pop and notify waiting sender */
		zval persistent_zval;
		circular_buffer_pop(&ch->buffer, &persistent_zval);

		thread_channel_waiter_t waiter;
		if (waiter_queue_pop(&ch->waiting_senders, &waiter)) {
			/* TODO: uv_async_send to waiter's thread to resume coroutine */
			(void) waiter;
		}

		pthread_mutex_unlock(&ch->mutex);

		/* Load from persistent memory into current thread */
		async_thread_load_zval(return_value, &persistent_zval);
		async_thread_release_transferred_zval(&persistent_zval);
		return;
	}

	/* Buffer empty — check if closed */
	if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(&ch->channel.event))) {
		pthread_mutex_unlock(&ch->mutex);
		zend_throw_exception(async_ce_thread_channel_exception, "ThreadChannel is closed", 0);
		RETURN_THROWS();
	}

	/* TODO: register as waiting receiver, unlock, ZEND_ASYNC_SUSPEND(), retry */
	pthread_mutex_unlock(&ch->mutex);
	zend_throw_exception(async_ce_thread_channel_exception,
		"ThreadChannel is empty (blocking recv not yet implemented)", 0);
	RETURN_THROWS();
}

METHOD(close)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_channel_t *ch = THIS_CHANNEL();

	pthread_mutex_lock(&ch->mutex);

	if (ZEND_ASYNC_EVENT_IS_CLOSED(&ch->channel.event)) {
		pthread_mutex_unlock(&ch->mutex);
		return;
	}

	ZEND_ASYNC_EVENT_SET_CLOSED(&ch->channel.event);

	/* TODO: wake all waiters with exception via uv_async_send */

	pthread_mutex_unlock(&ch->mutex);
}

METHOD(isClosed)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_BOOL(ZEND_ASYNC_EVENT_IS_CLOSED(&THIS_CHANNEL()->channel.event));
}

METHOD(capacity)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_LONG(THIS_CHANNEL()->capacity);
}

METHOD(count)
{
	ZEND_PARSE_PARAMETERS_NONE();
	async_thread_channel_t *ch = THIS_CHANNEL();
	pthread_mutex_lock(&ch->mutex);
	size_t count = circular_buffer_count(&ch->buffer);
	pthread_mutex_unlock(&ch->mutex);
	RETURN_LONG(count);
}

METHOD(isEmpty)
{
	ZEND_PARSE_PARAMETERS_NONE();
	async_thread_channel_t *ch = THIS_CHANNEL();
	pthread_mutex_lock(&ch->mutex);
	bool empty = circular_buffer_is_empty(&ch->buffer);
	pthread_mutex_unlock(&ch->mutex);
	RETURN_BOOL(empty);
}

METHOD(isFull)
{
	ZEND_PARSE_PARAMETERS_NONE();
	async_thread_channel_t *ch = THIS_CHANNEL();
	pthread_mutex_lock(&ch->mutex);
	bool full = circular_buffer_count(&ch->buffer) >= (size_t) ch->capacity;
	pthread_mutex_unlock(&ch->mutex);
	RETURN_BOOL(full);
}

///////////////////////////////////////////////////////////////////////////////
// Registration
///////////////////////////////////////////////////////////////////////////////

void async_register_thread_channel_ce(void)
{
	async_ce_thread_channel_exception = register_class_Async_ThreadChannelException(async_ce_async_exception);

	async_ce_thread_channel = register_class_Async_ThreadChannel(async_ce_awaitable, zend_ce_countable);

	async_ce_thread_channel->create_object = async_thread_channel_create_object;

	memcpy(&async_thread_channel_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	async_thread_channel_handlers.offset = XtOffsetOf(thread_channel_object_t, std);
	async_thread_channel_handlers.get_gc = async_thread_channel_get_gc;
	async_thread_channel_handlers.dtor_obj = async_thread_channel_dtor_object;
	async_thread_channel_handlers.free_obj = async_thread_channel_free_object;
	async_thread_channel_handlers.clone_obj = NULL;
}
