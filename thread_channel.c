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
#include "zend_common.h"
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

/* Mark Future token as used (non-blocking path) and throw if already fired. */
#define CANCELLATION_TOKEN_PREPARE(ct) \
	if ((ct) != NULL && UNEXPECTED(async_resolve_cancel_token(ct))) { \
		RETURN_THROWS(); \
	}

zend_class_entry *async_ce_thread_channel = NULL;
zend_class_entry *async_ce_thread_channel_exception = NULL;
static zend_object_handlers async_thread_channel_handlers;

///////////////////////////////////////////////////////////////////////////////
// Trigger helpers
///////////////////////////////////////////////////////////////////////////////

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
// Per-thread registry of channels created on this thread
///////////////////////////////////////////////////////////////////////////////

/* Channels created on the current thread, holding a ref each. Closed at this
 * thread's shutdown (async_thread_channel_close_owned) so a worker parked on a
 * channel whose owner finished without close() wakes and exits. Thread-local:
 * only the owning thread touches its own ASYNC_G(thread_channels) — no lock. */
static void thread_channel_registry_add(async_thread_channel_t *ch)
{
	async_thread_channel_addref(ch);
	zend_hash_index_add_ptr(&ASYNC_G(thread_channels), (zend_ulong)(uintptr_t) ch, ch);
}

void async_thread_channel_close_owned(void)
{
	async_thread_channel_t *ch;
	ZEND_HASH_FOREACH_PTR(&ASYNC_G(thread_channels), ch) {
		async_thread_channel_close(ch);
		/* Release the registry's ref. The channel survives while a wrapper or a
		 * worker still holds it; the woken worker drops the last ref and frees. */
		ch->channel.event.dispose(&ch->channel.event);
	} ZEND_HASH_FOREACH_END();
	zend_hash_clean(&ASYNC_G(thread_channels));
}

///////////////////////////////////////////////////////////////////////////////
// C-level send/receive (coroutine-aware)
///////////////////////////////////////////////////////////////////////////////

static bool thread_channel_send(zend_async_channel_t *channel, zval *value);
static bool thread_channel_receive(zend_async_channel_t *channel, zval *result, zend_async_event_t *cancellation);

/* Send, with an optional event that ends a wait on a full buffer. False means the
 * value was refused, the channel is closed, or the event fired; EG(exception) does
 * not tell them apart (a timeout token leaves one pending), the event's state does. */
static bool thread_channel_send_ex(
	zend_async_channel_t *channel, zval *value, zend_async_event_t *cancellation)
{
	async_thread_channel_t *ch = (async_thread_channel_t *) channel;
	zend_async_trigger_event_t *trigger = NULL;

	/* Transfer value to persistent memory (once, reused across retries) */
	zval persistent_copy;
	async_thread_transfer_zval(&persistent_copy, value);

	if (UNEXPECTED(Z_TYPE(persistent_copy) == IS_UNDEF)) {
		/* Not transferable; async_thread_transfer_zval already threw. Nothing goes
		 * into the buffer: a receiver cannot tell an undefined slot from a message. */
		return false;
	}

retry:
	ASYNC_MUTEX_LOCK(ch->mutex);

	/* Check closed under lock */
	if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(&ch->channel.event))) {
		ASYNC_MUTEX_UNLOCK(ch->mutex);
		async_thread_release_transferred_zval(&persistent_copy);
		if (trigger != NULL) {
			trigger->base.dispose(&trigger->base);
		}
		zend_throw_exception(async_ce_thread_channel_exception, "ThreadChannel is closed", 0);
		return false;
	}

	if (circular_buffer_count(&ch->buffer) < (size_t) ch->capacity) {
		/* Buffer has space — push and notify waiting receivers */
		circular_buffer_push(&ch->buffer, &persistent_copy, false);
		fire_all_triggers(&ch->receiver_triggers);
		ASYNC_MUTEX_UNLOCK(ch->mutex);
		if (trigger != NULL) {
			trigger->base.dispose(&trigger->base);
		}
		return true;
	}

	/* Buffer is full — create trigger (once), register and suspend */
	if (trigger == NULL) {
		trigger = ZEND_ASYNC_NEW_TRIGGER_EVENT();
	}
	zend_hash_index_update_ptr(&ch->sender_triggers, (zend_ulong)(uintptr_t) trigger, trigger);
	ASYNC_MUTEX_UNLOCK(ch->mutex);

	zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE,
		&trigger->base, false, zend_async_waker_callback_resolve, NULL);

	if (cancellation != NULL) {
		if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(cancellation))) {
			/* zend_async_resume_when refuses a closed event, so suspending would arm
			 * the channel trigger alone and never time out. No race with the register
			 * below: an event closes from a loop callback, and the loop is not running. */
			ASYNC_MUTEX_LOCK(ch->mutex);
			zend_hash_index_del(&ch->sender_triggers, (zend_ulong)(uintptr_t) trigger);
			ASYNC_MUTEX_UNLOCK(ch->mutex);
			ZEND_ASYNC_WAKER_DESTROY(ZEND_ASYNC_CURRENT_COROUTINE);
			async_thread_release_transferred_zval(&persistent_copy);
			trigger->base.dispose(&trigger->base);

			return false;
		}

		zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE,
			cancellation, false, zend_async_waker_callback_resolve, NULL);
	}

	/* A bailout through SUSPEND would skip the dispose paths below and leak the
	 * trigger (open uv_async blocks uv_loop_close). Catch, dispose, re-raise. */
	bool channel_bailed = false;
	zend_try {
		ZEND_ASYNC_SUSPEND();
	} zend_catch {
		channel_bailed = true;
	} zend_end_try();

	if (UNEXPECTED(channel_bailed)) {
		ASYNC_MUTEX_LOCK(ch->mutex);
		zend_hash_index_del(&ch->sender_triggers, (zend_ulong)(uintptr_t) trigger);
		ASYNC_MUTEX_UNLOCK(ch->mutex);
		ZEND_ASYNC_WAKER_DESTROY(ZEND_ASYNC_CURRENT_COROUTINE);
		async_thread_release_transferred_zval(&persistent_copy);
		trigger->base.dispose(&trigger->base);
		zend_bailout();
	}

	ZEND_ASYNC_WAKER_DESTROY(ZEND_ASYNC_CURRENT_COROUTINE);

	/* Woke up — remove from sender queue */
	ASYNC_MUTEX_LOCK(ch->mutex);
	zend_hash_index_del(&ch->sender_triggers, (zend_ulong)(uintptr_t) trigger);
	const bool closed = ZEND_ASYNC_EVENT_IS_CLOSED(&ch->channel.event);
	const bool still_full = circular_buffer_count(&ch->buffer) >= (size_t) ch->capacity;
	ASYNC_MUTEX_UNLOCK(ch->mutex);

	if (EG(exception)) {
		async_thread_release_transferred_zval(&persistent_copy);
		trigger->base.dispose(&trigger->base);
		return false;
	}

	if (cancellation != NULL && ZEND_ASYNC_EVENT_IS_CLOSED(cancellation) && closed == false && still_full) {
		/* One freed slot wakes every parked sender, so the buffer alone cannot say
		 * this call was cancelled: the losers of that race retry instead of reporting. */
		async_thread_release_transferred_zval(&persistent_copy);
		trigger->base.dispose(&trigger->base);
		return false;
	}

	goto retry;
}

static bool thread_channel_send(zend_async_channel_t *channel, zval *value)
{
	return thread_channel_send_ex(channel, value, NULL);
}

static bool thread_channel_receive(
	zend_async_channel_t *channel, zval *result, zend_async_event_t *cancellation)
{
	async_thread_channel_t *ch = (async_thread_channel_t *) channel;
	zend_async_trigger_event_t *trigger = NULL;
	const bool wait_only = (result == NULL);

retry:
	ASYNC_MUTEX_LOCK(ch->mutex);

	if (!wait_only && circular_buffer_is_not_empty(&ch->buffer)) {
		/* Data available — pop and notify waiting senders */
		zval persistent_zval;
		circular_buffer_pop(&ch->buffer, &persistent_zval);
		fire_all_triggers(&ch->sender_triggers);
		ASYNC_MUTEX_UNLOCK(ch->mutex);

		async_thread_load_zval(result, &persistent_zval);
		async_thread_release_transferred_zval(&persistent_zval);

		if (trigger != NULL) {
			trigger->base.dispose(&trigger->base);
		}
		return true;
	}

	/* Buffer empty (or wait_only) — check if closed */
	if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(&ch->channel.event))) {
		ASYNC_MUTEX_UNLOCK(ch->mutex);
		if (trigger != NULL) {
			trigger->base.dispose(&trigger->base);
		}
		/* wait_only callers expect a quiet false on close. */
		if (!wait_only) {
			zend_throw_exception(async_ce_thread_channel_exception, "ThreadChannel is closed", 0);
		}
		return false;
	}

	/* Buffer empty, not closed — create trigger (once), register and suspend.
	 * If a cancellation event was supplied, also resume on it. */
	if (trigger == NULL) {
		trigger = ZEND_ASYNC_NEW_TRIGGER_EVENT();
	}
	zend_hash_index_update_ptr(&ch->receiver_triggers, (zend_ulong)(uintptr_t) trigger, trigger);
	ASYNC_MUTEX_UNLOCK(ch->mutex);

	zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE,
		&trigger->base, false, zend_async_waker_callback_resolve, NULL);

	if (cancellation != NULL) {
		if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(cancellation))) {
			/* Same guard as in thread_channel_send_ex: zend_async_resume_when refuses
			 * a closed event, and suspending without it never times out. */
			ASYNC_MUTEX_LOCK(ch->mutex);
			zend_hash_index_del(&ch->receiver_triggers, (zend_ulong)(uintptr_t) trigger);
			ASYNC_MUTEX_UNLOCK(ch->mutex);
			ZEND_ASYNC_WAKER_DESTROY(ZEND_ASYNC_CURRENT_COROUTINE);
			trigger->base.dispose(&trigger->base);

			return false;
		}

		zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE,
			cancellation, false, zend_async_waker_callback_resolve, NULL);
	}

	/* A bailout through SUSPEND would skip the dispose paths below and leak the
	 * trigger (open uv_async blocks uv_loop_close). Catch, dispose, re-raise. */
	bool channel_bailed = false;
	zend_try {
		ZEND_ASYNC_SUSPEND();
	} zend_catch {
		channel_bailed = true;
	} zend_end_try();

	if (UNEXPECTED(channel_bailed)) {
		ASYNC_MUTEX_LOCK(ch->mutex);
		zend_hash_index_del(&ch->receiver_triggers, (zend_ulong)(uintptr_t) trigger);
		ASYNC_MUTEX_UNLOCK(ch->mutex);
		ZEND_ASYNC_WAKER_DESTROY(ZEND_ASYNC_CURRENT_COROUTINE);
		trigger->base.dispose(&trigger->base);
		zend_bailout();
	}

	ZEND_ASYNC_WAKER_DESTROY(ZEND_ASYNC_CURRENT_COROUTINE);

	/* Woke up — remove from receiver queue, observe closed state */
	ASYNC_MUTEX_LOCK(ch->mutex);
	zend_hash_index_del(&ch->receiver_triggers, (zend_ulong)(uintptr_t) trigger);
	const bool closed = ZEND_ASYNC_EVENT_IS_CLOSED(&ch->channel.event);
	ASYNC_MUTEX_UNLOCK(ch->mutex);

	if (EG(exception)) {
		trigger->base.dispose(&trigger->base);
		return false;
	}

	if (wait_only) {
		/* Wake delivered (send, close, or cancellation) — caller decides
		 * what to do; we don't consume and we don't throw. */
		trigger->base.dispose(&trigger->base);
		return !closed;
	}

	if (cancellation != NULL && ZEND_ASYNC_EVENT_IS_CLOSED(cancellation) && closed == false) {
		/* Non-wait_only call: return false without an exception, which the caller
		 * distinguishes from the closed-channel path. One send wakes every parked
		 * receiver, so the losers of that race park again instead of reporting. */
		ASYNC_MUTEX_LOCK(ch->mutex);
		const bool still_empty = !circular_buffer_is_not_empty(&ch->buffer);
		ASYNC_MUTEX_UNLOCK(ch->mutex);

		if (still_empty) {
			trigger->base.dispose(&trigger->base);
			return false;
		}
	}

	goto retry;
}

void async_thread_channel_close(async_thread_channel_t *ch)
{
	ASYNC_MUTEX_LOCK(ch->mutex);

	if (ZEND_ASYNC_EVENT_IS_CLOSED(&ch->channel.event)) {
		ASYNC_MUTEX_UNLOCK(ch->mutex);
		return;
	}

	ZEND_ASYNC_EVENT_SET_CLOSED(&ch->channel.event);
	fire_all_triggers(&ch->receiver_triggers);
	fire_all_triggers(&ch->sender_triggers);

	ASYNC_MUTEX_UNLOCK(ch->mutex);
}

static void thread_channel_close(zend_async_channel_t *channel)
{
	async_thread_channel_close((async_thread_channel_t *) channel);
}

///////////////////////////////////////////////////////////////////////////////
// Thread channel allocation / destruction
///////////////////////////////////////////////////////////////////////////////

static bool thread_channel_event_dispose(zend_async_event_t *event);

async_thread_channel_t *async_thread_channel_create(int32_t capacity)
{
	async_thread_channel_t *ch = pecalloc(1, sizeof(async_thread_channel_t), 1);

	ch->capacity = capacity;
	zend_atomic_int_store(&ch->ref_count, 1);

	ASYNC_MUTEX_INIT(ch->mutex);

	/* +1 for sentinel slot in circular buffer */
	circular_buffer_ctor(&ch->buffer, capacity + 1, sizeof(zval), &zend_std_persistent_allocator);

	/* Triggers are owned by callers, not by channel — no dtor */
	zend_hash_init(&ch->receiver_triggers, 0, NULL, NULL, 1);
	zend_hash_init(&ch->sender_triggers, 0, NULL, NULL, 1);

	ch->channel.send = thread_channel_send;
	ch->channel.receive = thread_channel_receive;
	ch->channel.close = thread_channel_close;
	ch->channel.event.dispose = thread_channel_event_dispose;

	thread_channel_registry_add(ch);

	return ch;
}

static void thread_channel_destroy(async_thread_channel_t *ch)
{
	/* Close and notify waiters before destroying */
	if (!ZEND_ASYNC_EVENT_IS_CLOSED(&ch->channel.event)) {
		ASYNC_MUTEX_LOCK(ch->mutex);
		ZEND_ASYNC_EVENT_SET_CLOSED(&ch->channel.event);
		fire_all_triggers(&ch->receiver_triggers);
		fire_all_triggers(&ch->sender_triggers);
		ASYNC_MUTEX_UNLOCK(ch->mutex);
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

	ASYNC_MUTEX_DESTROY(ch->mutex);
	pefree(ch, 1);
}

void async_thread_channel_addref(async_thread_channel_t *ch)
{
	int old;
	do {
		old = zend_atomic_int_load(&ch->ref_count);
	} while (!zend_atomic_int_compare_exchange(&ch->ref_count, &old, old + 1));
}

static bool thread_channel_event_dispose(zend_async_event_t *event)
{
	/* channel.event is at offset 0, so direct cast is safe */
	async_thread_channel_t *ch = (async_thread_channel_t *) event;

	int old;
	do {
		old = zend_atomic_int_load(&ch->ref_count);
	} while (!zend_atomic_int_compare_exchange(&ch->ref_count, &old, old - 1));

	if (old == 1) {
		thread_channel_destroy(ch);
	}

	return true;
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
		ASYNC_MUTEX_LOCK(obj->channel->mutex);
		unregister_trigger(&obj->channel->receiver_triggers, obj);
		unregister_trigger(&obj->channel->sender_triggers, obj);
		ASYNC_MUTEX_UNLOCK(obj->channel->mutex);
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

		async_thread_channel_addref(src_obj->channel);
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
		obj->channel->channel.event.dispose(&obj->channel->channel.event);
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

	if (capacity < 1 || capacity > INT32_MAX) {
		zend_argument_value_error(1, "must be between 1 and %d", INT32_MAX);
		RETURN_THROWS();
	}

	thread_channel_object_t *obj = ASYNC_THREAD_CHANNEL_FROM_OBJ(Z_OBJ_P(ZEND_THIS));
	obj->channel = async_thread_channel_create((int32_t) capacity);
}

/* Throw what `Channel::recv()` throws for a fired token: OperationCanceledException
 * with the token's own exception as previous, so one catch covers both channel
 * classes. A token that never fired is left alone — that wait ended some other way. */
static void report_cancellation(zend_object *token)
{
	if (token == NULL || !ZEND_ASYNC_EVENT_IS_CLOSED(ZEND_ASYNC_OBJECT_TO_EVENT(token))) {
		return;
	}

	/* A timeout token leaves its TimeoutException in EG() rather than on the event,
	 * where async_resolve_cancel_token would not find it and would replace it. */
	zend_object *raised = EG(exception);

	if (raised != NULL) {
		GC_ADDREF(raised);
		zend_clear_exception();
	}

	async_resolve_cancel_token(token);

	ZEND_ASSERT(EG(exception) != NULL);
	zend_exception_set_previous(EG(exception), raised);
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

	zend_async_event_t *cancellation =
		cancellation_token != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation_token) : NULL;

	if (!thread_channel_send_ex(&THIS_CHANNEL()->channel, value, cancellation)) {
		report_cancellation(cancellation_token);
		RETURN_THROWS();
	}
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

	zend_async_event_t *cancellation =
		cancellation_token != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation_token) : NULL;

	if (!THIS_CHANNEL()->channel.receive(&THIS_CHANNEL()->channel, return_value, cancellation)) {
		report_cancellation(cancellation_token);
		RETURN_THROWS();
	}
}

METHOD(close)
{
	ZEND_PARSE_PARAMETERS_NONE();
	THIS_CHANNEL()->channel.close(&THIS_CHANNEL()->channel);
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
	ASYNC_MUTEX_LOCK(ch->mutex);
	size_t count = circular_buffer_count(&ch->buffer);
	ASYNC_MUTEX_UNLOCK(ch->mutex);
	RETURN_LONG(count);
}

METHOD(isEmpty)
{
	ZEND_PARSE_PARAMETERS_NONE();
	async_thread_channel_t *ch = THIS_CHANNEL();
	ASYNC_MUTEX_LOCK(ch->mutex);
	bool empty = circular_buffer_is_empty(&ch->buffer);
	ASYNC_MUTEX_UNLOCK(ch->mutex);
	RETURN_BOOL(empty);
}

METHOD(isFull)
{
	ZEND_PARSE_PARAMETERS_NONE();
	async_thread_channel_t *ch = THIS_CHANNEL();
	ASYNC_MUTEX_LOCK(ch->mutex);
	bool full = circular_buffer_count(&ch->buffer) >= (size_t) ch->capacity;
	ASYNC_MUTEX_UNLOCK(ch->mutex);
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
	async_thread_channel_handlers.offset = offsetof(thread_channel_object_t, std);
	async_thread_channel_handlers.get_gc = async_thread_channel_get_gc;
	async_thread_channel_handlers.dtor_obj = async_thread_channel_dtor_object;
	async_thread_channel_handlers.free_obj = async_thread_channel_free_object;
	async_thread_channel_handlers.clone_obj = NULL;
	async_thread_channel_handlers.transfer_obj = async_thread_channel_transfer_obj;
}
