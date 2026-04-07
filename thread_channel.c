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
#include "TSRM/TSRM.h"

#define METHOD(name) PHP_METHOD(Async_ThreadChannel, name)
#define THIS_CHANNEL() (ASYNC_THREAD_CHANNEL_FROM_OBJ(Z_OBJ_P(ZEND_THIS))->channel)

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
// Trigger helpers
///////////////////////////////////////////////////////////////////////////////

/* Ensure wrapper has a trigger event (lazy init). */
static zend_async_trigger_event_t *ensure_wrapper_trigger(thread_channel_object_t *obj)
{
	if (obj->event == NULL) {
		zend_async_trigger_event_t *trigger = ZEND_ASYNC_NEW_TRIGGER_EVENT();
		ZEND_ASYNC_EVENT_REF_SET(obj, XtOffsetOf(thread_channel_object_t, std), &trigger->base);
	}
	return ASYNC_THREAD_CHANNEL_TRIGGER(obj);
}

/* Register wrapper's trigger in a channel trigger set. Mutex must be held. */
static void register_trigger(HashTable *triggers, thread_channel_object_t *obj)
{
	zend_hash_index_update_ptr(triggers, (zend_ulong)(uintptr_t) obj, ASYNC_THREAD_CHANNEL_TRIGGER(obj));
}

/* Unregister wrapper's trigger from a channel trigger set. Mutex must be held. */
static void unregister_trigger(HashTable *triggers, thread_channel_object_t *obj)
{
	zend_hash_index_del(triggers, (zend_ulong)(uintptr_t) obj);
}

/* Fire all trigger events in the given mapping to wake up waiting threads. */
static void fire_all_triggers(HashTable *triggers)
{
	zend_async_trigger_event_t *trigger;
	ZEND_HASH_FOREACH_PTR(triggers, trigger) {
		trigger->trigger(trigger);
	} ZEND_HASH_FOREACH_END();
}

///////////////////////////////////////////////////////////////////////////////
// Thread channel allocation / destruction
///////////////////////////////////////////////////////////////////////////////

async_thread_channel_t *async_thread_channel_create(int32_t capacity)
{
	async_thread_channel_t *ch = pecalloc(1, sizeof(async_thread_channel_t), 1);

	ch->capacity = capacity;
	zend_atomic_int_store(&ch->ref_count, 1);

	pthread_mutex_init(&ch->mutex, NULL);

	/* +1 for sentinel slot in circular buffer */
	circular_buffer_ctor(&ch->buffer, capacity + 1, sizeof(zval), &zend_std_persistent_allocator);

	/* Triggers are owned by wrappers, not by channel — no dtor */
	zend_hash_init(&ch->receiver_triggers, 0, NULL, NULL, 1);
	zend_hash_init(&ch->sender_triggers, 0, NULL, NULL, 1);

	return ch;
}

static void thread_channel_destroy(async_thread_channel_t *ch)
{
	/* Close and notify waiters before destroying */
	if (!ZEND_ASYNC_EVENT_IS_CLOSED(&ch->channel.event)) {
		pthread_mutex_lock(&ch->mutex);
		ZEND_ASYNC_EVENT_SET_CLOSED(&ch->channel.event);
		fire_all_triggers(&ch->receiver_triggers);
		fire_all_triggers(&ch->sender_triggers);
		pthread_mutex_unlock(&ch->mutex);
	}

	/* Drain buffer — release all transferred zvals */
	zval tmp;
	while (circular_buffer_is_not_empty(&ch->buffer) &&
		   circular_buffer_pop(&ch->buffer, &tmp) == SUCCESS) {
		async_thread_release_transferred_zval(&tmp);
	}
	circular_buffer_dtor(&ch->buffer);

	zend_hash_destroy(&ch->receiver_triggers);
	zend_hash_destroy(&ch->sender_triggers);

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
// Channel event (used only for closed flag, not for coroutine subscriptions)
///////////////////////////////////////////////////////////////////////////////

static void thread_channel_event_init(async_thread_channel_t *ch)
{
	memset(&ch->channel.event, 0, sizeof(zend_async_event_t));
}

///////////////////////////////////////////////////////////////////////////////
// Object handlers
///////////////////////////////////////////////////////////////////////////////

static HashTable *async_thread_channel_get_gc(zend_object *object, zval **table, int *num)
{
	*table = NULL;
	*num = 0;
	return NULL;
}

static zend_object *async_thread_channel_create_object(zend_class_entry *ce)
{
	thread_channel_object_t *obj = zend_object_alloc(sizeof(thread_channel_object_t), ce);

	zend_object_std_init(&obj->std, ce);
	obj->std.handlers = &async_thread_channel_handlers;
	obj->channel = NULL;
	obj->event = NULL;

	return &obj->std;
}

static void async_thread_channel_dtor_object(zend_object *object)
{
	thread_channel_object_t *obj = ASYNC_THREAD_CHANNEL_FROM_OBJ(object);

	/* Unregister our trigger from channel's trigger sets */
	if (obj->channel != NULL) {
		pthread_mutex_lock(&obj->channel->mutex);
		unregister_trigger(&obj->channel->receiver_triggers, obj);
		unregister_trigger(&obj->channel->sender_triggers, obj);
		pthread_mutex_unlock(&obj->channel->mutex);
	}

	zend_object_std_dtor(object);
}

static zend_object *async_thread_channel_transfer_obj(
	zend_object *object, zend_async_thread_transfer_ctx_t *ctx,
	zend_object_transfer_kind_t kind, zend_object_transfer_default_fn default_fn)
{
	if (kind == ZEND_OBJECT_TRANSFER) {
		/* Transfer: pemalloc wrapper via default, then copy channel pointer */
		zend_object *dst = default_fn(object, ctx, sizeof(thread_channel_object_t));

		thread_channel_object_t *src_obj = ASYNC_THREAD_CHANNEL_FROM_OBJ(object);
		thread_channel_object_t *dst_obj = ASYNC_THREAD_CHANNEL_FROM_OBJ(dst);

		thread_channel_addref(src_obj->channel);
		dst_obj->channel = src_obj->channel;

		return dst;
	} else {
		/* Load: create emalloc object via default, then restore channel pointer */
		zend_object *dst = default_fn(object, ctx, 0);

		thread_channel_object_t *src_obj = ASYNC_THREAD_CHANNEL_FROM_OBJ(object);
		thread_channel_object_t *dst_obj = ASYNC_THREAD_CHANNEL_FROM_OBJ(dst);

		dst_obj->channel = src_obj->channel;

		return dst;
	}
}

static void async_thread_channel_free_object(zend_object *object)
{
	thread_channel_object_t *obj = ASYNC_THREAD_CHANNEL_FROM_OBJ(object);

	if (obj->event != NULL) {
		obj->event->dispose(obj->event);
		obj->event = NULL;
	}

	if (obj->channel != NULL) {
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
	obj->channel = async_thread_channel_create((int32_t) capacity);

	thread_channel_event_init(obj->channel);
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

	thread_channel_object_t *wrapper = ASYNC_THREAD_CHANNEL_FROM_OBJ(Z_OBJ_P(ZEND_THIS));
	async_thread_channel_t *ch = wrapper->channel;

	/* Transfer value to persistent memory */
	zval persistent_copy;
	async_thread_transfer_zval(&persistent_copy, value);

retry:
	pthread_mutex_lock(&ch->mutex);

	/* Check closed under lock */
	if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(&ch->channel.event))) {
		pthread_mutex_unlock(&ch->mutex);
		async_thread_release_transferred_zval(&persistent_copy);
		zend_throw_exception(async_ce_thread_channel_exception, "ThreadChannel is closed", 0);
		RETURN_THROWS();
	}

	if (circular_buffer_count(&ch->buffer) < (size_t) ch->capacity) {
		/* Buffer has space — push and notify waiting receivers */
		circular_buffer_push(&ch->buffer, &persistent_copy, false);
		fire_all_triggers(&ch->receiver_triggers);
		pthread_mutex_unlock(&ch->mutex);
		return;
	}

	/* Buffer is full — ensure trigger, register and suspend */
	ensure_wrapper_trigger(wrapper);
	register_trigger(&ch->sender_triggers, wrapper);
	pthread_mutex_unlock(&ch->mutex);

	zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE,
		wrapper->event, false, zend_async_waker_callback_resolve, NULL);

	ZEND_ASYNC_SUSPEND();
	ZEND_ASYNC_WAKER_DESTROY(ZEND_ASYNC_CURRENT_COROUTINE);

	/* Woke up — remove from sender queue */
	pthread_mutex_lock(&ch->mutex);
	unregister_trigger(&ch->sender_triggers, wrapper);
	pthread_mutex_unlock(&ch->mutex);

	if (EG(exception)) {
		async_thread_release_transferred_zval(&persistent_copy);
		RETURN_THROWS();
	}

	goto retry;
}

METHOD(recv)
{
	zend_object *cancellation_token = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation_token, async_ce_completable)
	ZEND_PARSE_PARAMETERS_END();

	ENSURE_COROUTINE_CONTEXT

	thread_channel_object_t *wrapper = ASYNC_THREAD_CHANNEL_FROM_OBJ(Z_OBJ_P(ZEND_THIS));
	async_thread_channel_t *ch = wrapper->channel;

retry:
	pthread_mutex_lock(&ch->mutex);

	if (circular_buffer_is_not_empty(&ch->buffer)) {
		/* Data available — pop and notify waiting senders */
		zval persistent_zval;
		circular_buffer_pop(&ch->buffer, &persistent_zval);
		fire_all_triggers(&ch->sender_triggers);
		pthread_mutex_unlock(&ch->mutex);

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

	/* Buffer empty, not closed — ensure trigger, register and suspend */
	ensure_wrapper_trigger(wrapper);
	register_trigger(&ch->receiver_triggers, wrapper);
	pthread_mutex_unlock(&ch->mutex);

	zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE,
		wrapper->event, false, zend_async_waker_callback_resolve, NULL);

	ZEND_ASYNC_SUSPEND();
	ZEND_ASYNC_WAKER_DESTROY(ZEND_ASYNC_CURRENT_COROUTINE);

	/* Woke up — remove from receiver queue */
	pthread_mutex_lock(&ch->mutex);
	unregister_trigger(&ch->receiver_triggers, wrapper);
	pthread_mutex_unlock(&ch->mutex);

	if (EG(exception)) {
		RETURN_THROWS();
	}

	goto retry;
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

	fire_all_triggers(&ch->receiver_triggers);
	fire_all_triggers(&ch->sender_triggers);

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
	async_ce_thread_channel->default_object_handlers = &async_thread_channel_handlers;
	async_thread_channel_handlers.offset = XtOffsetOf(thread_channel_object_t, std);
	async_thread_channel_handlers.get_gc = async_thread_channel_get_gc;
	async_thread_channel_handlers.dtor_obj = async_thread_channel_dtor_object;
	async_thread_channel_handlers.free_obj = async_thread_channel_free_object;
	async_thread_channel_handlers.clone_obj = NULL;
	async_thread_channel_handlers.transfer_obj = async_thread_channel_transfer_obj;
}
