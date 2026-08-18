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
#include "zend_enum.h"
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
		zend_throw_exception_internal(make_channel_exception((channel)->close_reason)); \
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
zend_class_entry *async_ce_channel_close_reason = NULL;
static zend_object_handlers async_channel_handlers;

static zend_object *channel_close_reason_case(channel_close_reason_t reason)
{
	const char *name;
	switch (reason) {
		case CHANNEL_CLOSE_EXPLICIT:       name = "EXPLICIT"; break;
		case CHANNEL_CLOSE_DISPOSED:       name = "DISPOSED"; break;
		case CHANNEL_CLOSE_NO_PRODUCERS:   name = "NO_PRODUCERS"; break;
		case CHANNEL_CLOSE_NO_CONSUMERS:   name = "NO_CONSUMERS"; break;
		case CHANNEL_CLOSE_DEADLOCK:       name = "DEADLOCK"; break;
		case CHANNEL_CLOSE_SCOPE_DISPOSED: name = "SCOPE_DISPOSED"; break;
		default:                           name = "EXPLICIT"; break;
	}
	return zend_enum_get_case_cstr(async_ce_channel_close_reason, name);
}

static zend_object *make_channel_exception(channel_close_reason_t reason)
{
	const char *message;
	switch (reason) {
		case CHANNEL_CLOSE_EXPLICIT:       message = "Channel is closed"; break;
		case CHANNEL_CLOSE_DISPOSED:       message = "Channel disposed"; break;
		case CHANNEL_CLOSE_NO_PRODUCERS:   message = "Channel deadlock: no producers"; break;
		case CHANNEL_CLOSE_NO_CONSUMERS:   message = "Channel deadlock: no consumers"; break;
		case CHANNEL_CLOSE_DEADLOCK:       message = "Channel deadlock"; break;
		case CHANNEL_CLOSE_SCOPE_DISPOSED: message = "Channel closed: owner scope disposed"; break;
		default:                           message = "Channel is closed"; break;
	}

	zend_object *ex = async_new_exception(async_ce_channel_exception, "%s", message);

	zval reason_val;
	ZVAL_OBJ_COPY(&reason_val, channel_close_reason_case(reason));
	zend_update_property(async_ce_channel_exception, ex, "reason", sizeof("reason") - 1, &reason_val);
	zval_ptr_dtor(&reason_val);

	return ex;
}

///////////////////////////////////////////////////////////////////////////////
// Waiter
///////////////////////////////////////////////////////////////////////////////

typedef struct
{
	zend_coroutine_event_callback_t callback; /* inherits from coroutine callback */
	zend_future_t *future;                    /* NULL for coroutine waiter */
	/* A value (receiver) or a free slot (sender) is held for this waiter. */
	bool reserved : 1;
	/* Rendezvous sender parked on its own value: it waits for the value to be
	 * taken, not for a free slot, so no reservation is made for it. */
	bool delivering : 1;
} channel_waiter_t;

/* Extra payload appended to the Future returned by recvAsync().
 * If the user drops the Future before the channel completes it (e.g. unset(),
 * await_any_or_fail() picked another Future), zend_future_dispose() runs and
 * frees the future. The channel's queued waiter still holds a raw pointer to
 * that future — letting the channel later write to freed memory.
 *
 * We override the future's dispose handler so it first removes the waiter
 * from the channel's queue and releases the waiter's callback ref.
 *
 * Channel lifetime is guaranteed: channel_close() rejects every future waiter,
 * setting ZEND_ASYNC_EVENT_F_CLOSED on the future. Once a future is CLOSED the
 * waiter has already been popped and the override skips queue cleanup, so we
 * never deref a freed channel pointer. */
typedef struct
{
	async_channel_t *channel;
	channel_waiter_t *waiter;
	zend_async_event_dispose_t prev_dispose;
} channel_recv_future_extra_t;

#define ASYNC_CHANNEL_RECV_FUTURE_EXTRA(future) \
	((channel_recv_future_extra_t *) ((char *) (future) + sizeof(zend_future_t)))

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

static zend_always_inline size_t channel_free_space(async_channel_t *channel)
{
	if (channel_is_buffered(channel)) {
		return (size_t) channel->capacity - circular_buffer_count(&channel->buffer);
	}
	return channel->rendezvous_has_value ? 0 : 1;
}

/* A value no woken receiver has been promised. Anyone but the waiter holding
 * the reservation may take a value only while this is true. */
static zend_always_inline bool channel_has_free_value(async_channel_t *channel)
{
	return channel_count(channel) > channel->reserved_receivers;
}

/* The same rule for the other side: a slot no woken sender has been promised. */
static zend_always_inline bool channel_has_free_slot(async_channel_t *channel)
{
	return channel_free_space(channel) > channel->reserved_senders;
}

/* Moves one value out of the channel. The caller establishes first that the
 * value is theirs to take: channel_has_free_value(), or a reservation of its
 * own. */
static void channel_take_value(async_channel_t *channel, zval *result)
{
	if (channel_is_buffered(channel)) {
		zval_circular_buffer_pop(&channel->buffer, result);
		return;
	}

	ZVAL_COPY(result, &channel->rendezvous_value);
	zval_ptr_dtor(&channel->rendezvous_value);
	ZVAL_UNDEF(&channel->rendezvous_value);
	channel->rendezvous_has_value = false;
	channel->rendezvous_committed = false;
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

/* Removes the entry at index, keeping the queue in arrival order. The queue's
 * reference on the waiter is not released here; the caller owns that.
 *
 * The tail is shifted rather than the last element swapped into the hole: which
 * waiter a handed-on reservation reaches has to follow arrival order, otherwise
 * it depends on who left the queue last. The price is O(n) in the length of the
 * tail instead of the O(1) of a swap. */
static void channel_queue_remove_at(zend_async_callbacks_vector_t *queue, uint32_t index)
{
	queue->length--;
	if (index < queue->length) {
		memmove(queue->data + index, queue->data + index + 1, (queue->length - index) * sizeof(void *));
	}
}

/* Oldest waiter the channel may still serve: one holding a reservation has been
 * served already, and a rendezvous sender waiting for its own value to be taken
 * is parked for that and not for a slot. */
static channel_waiter_t *channel_queue_first_unreserved(zend_async_callbacks_vector_t *queue)
{
	for (uint32_t i = 0; i < queue->length; i++) {
		channel_waiter_t *waiter = (channel_waiter_t *) queue->data[i];
		if (!waiter->reserved && !waiter->delivering) {
			return waiter;
		}
	}
	return NULL;
}

/* The rendezvous sender parked on the value in the slot. At most one exists:
 * the slot holds one value, and a sender leaves the queue when its value is
 * taken. */
static channel_waiter_t *channel_queue_delivering(zend_async_callbacks_vector_t *queue)
{
	for (uint32_t i = 0; i < queue->length; i++) {
		channel_waiter_t *waiter = (channel_waiter_t *) queue->data[i];
		if (waiter->delivering) {
			return waiter;
		}
	}
	return NULL;
}

static bool channel_queue_remove(zend_async_callbacks_vector_t *queue, channel_waiter_t *waiter)
{
	for (uint32_t i = 0; i < queue->length; i++) {
		if (queue->data[i] == (zend_async_event_callback_t *) waiter) {
			channel_queue_remove_at(queue, i);
			return true;
		}
	}
	return false;
}

static bool channel_recv_future_dispose(zend_async_event_t *event)
{
	zend_future_t *future = (zend_future_t *) event;
	channel_recv_future_extra_t *extra = ASYNC_CHANNEL_RECV_FUTURE_EXTRA(future);

	/* Only act if the channel hasn't already drained this waiter. The CLOSED
	 * flag is set by zend_future_resolve(), which is called from
	 * channel_wake_receiver() / channel_wake_all() right after the waiter is
	 * popped from the queue — so !IS_CLOSED reliably means the waiter is still
	 * queued and the channel is still alive. */
	if (!ZEND_ASYNC_EVENT_IS_CLOSED(event) && extra->waiter != NULL && extra->channel != NULL) {
		channel_waiter_t *waiter = extra->waiter;
		async_channel_t *channel = extra->channel;
		extra->waiter = NULL;

		if (channel_queue_remove(&channel->waiting_receivers, waiter)) {
			channel->channel.event.del_callback(&channel->channel.event, &waiter->callback.base);
			/* Drop the queue's ref on the waiter; this frees it when ref_count hits 0. */
			ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
		}
	}

	return extra->prev_dispose != NULL ? extra->prev_dispose(event) : true;
}

///////////////////////////////////////////////////////////////////////////////
// Deadlock timer
///////////////////////////////////////////////////////////////////////////////

static void channel_close(async_channel_t *channel, channel_close_reason_t reason);

static void channel_deadlock_callback_dispose(zend_async_event_callback_t *callback, zend_async_event_t *event)
{
	/* Embedded callback — no heap free. */
}

static void channel_deadlock_fire(zend_async_event_t *timer_event,
								  zend_async_event_callback_t *callback,
								  void *result,
								  zend_object *exception)
{
	async_channel_t *channel = (async_channel_t *) ((char *) callback - offsetof(async_channel_t, deadlock_callback));

	if (ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		return;
	}

	channel_close(channel, channel->pending_timeout_reason);
}

/* Stops the timer; does not touch close_reason. */
static void channel_disarm_deadlock_timer(async_channel_t *channel)
{
	if (channel->deadlock_timer == NULL) {
		return;
	}

	zend_async_event_t *base = &channel->deadlock_timer->base;
	base->stop(base);
	base->del_callback(base, &channel->deadlock_callback);
	ZEND_ASYNC_EVENT_RELEASE(base);
	channel->deadlock_timer = NULL;

	zend_hash_index_del(&ASYNC_G(deadlock_channels), (zend_ulong) (uintptr_t) channel);
}

static void channel_arm_deadlock_timer(async_channel_t *channel, channel_close_reason_t reason, int32_t timeout_ms)
{
	if (timeout_ms <= 0 || channel->deadlock_timer != NULL ||
		ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		return;
	}

	zend_async_timer_event_t *timer = ZEND_ASYNC_NEW_TIMER_EVENT((zend_ulong) timeout_ms, false);
	if (timer == NULL) {
		return;
	}

	if (!channel->hard_timeouts) {
		ZEND_ASYNC_EVENT_SET_HIDDEN(&timer->base);
		/* Soft timers don't keep the loop alive on their own, so register
		 * for early bulk-close by the global deadlock resolver. */
		zend_hash_index_add_ptr(&ASYNC_G(deadlock_channels), (zend_ulong) (uintptr_t) channel, channel);
	}

	channel->deadlock_callback.ref_count = 1;
	channel->deadlock_callback.callback = channel_deadlock_fire;
	channel->deadlock_callback.dispose = channel_deadlock_callback_dispose;

	timer->base.add_callback(&timer->base, &channel->deadlock_callback);
	if (!timer->base.start(&timer->base)) {
		timer->base.del_callback(&timer->base, &channel->deadlock_callback);
		ZEND_ASYNC_EVENT_RELEASE(&timer->base);
		return;
	}

	channel->deadlock_timer = timer;
	channel->pending_timeout_reason = reason;
}

/* A waiter holding a reservation is not starving (its value or its slot is kept
 * for it), so it does not arm the timer. Both counts can be non-zero at once: a
 * reserved receiver stays queued until it runs, and a sender can fill the buffer
 * meanwhile. */
static void channel_refresh_deadlock_timer(async_channel_t *channel)
{
	const bool has_recv = channel->waiting_receivers.length > channel->reserved_receivers;
	const bool has_send = channel->waiting_senders.length > channel->reserved_senders;

	if (!has_recv && !has_send) {
		channel_disarm_deadlock_timer(channel);
		return;
	}

	const channel_close_reason_t reason = has_recv ? CHANNEL_CLOSE_NO_PRODUCERS : CHANNEL_CLOSE_NO_CONSUMERS;
	const int32_t timeout = has_recv ? channel->no_producer_timeout_ms : channel->no_consumer_timeout_ms;

	if (channel->deadlock_timer != NULL) {
		if (channel->pending_timeout_reason == reason) {
			return;
		}
		channel_disarm_deadlock_timer(channel);
	}

	channel_arm_deadlock_timer(channel, reason, timeout);
}

///////////////////////////////////////////////////////////////////////////////
// Wake operations
///////////////////////////////////////////////////////////////////////////////

/* Forward declarations */
static bool channel_wake_sender(async_channel_t *channel);

/**
 * Gives one free value to the oldest receiver without a reservation. Returns
 * false when every value is already promised, or when nobody waits for one.
 *
 * A coroutine waiter stays in the queue on purpose: a wakeup only queues the
 * coroutine, and until it runs reserved_receivers is what keeps the value out
 * of everyone else's reach. A future has no coroutine to run later, so it is
 * served here and needs no reservation.
 */
static bool channel_wake_receiver(async_channel_t *channel)
{
	if (!channel_has_free_value(channel)) {
		return false;
	}

	channel_waiter_t *waiter = channel_queue_first_unreserved(&channel->waiting_receivers);
	if (waiter == NULL) {
		return false;
	}

	if (waiter->future != NULL) {
		channel_queue_remove(&channel->waiting_receivers, waiter);
		channel->channel.event.del_callback(&channel->channel.event, &waiter->callback.base);

		zval value;
		channel_take_value(channel, &value);
		ZEND_FUTURE_COMPLETE(waiter->future, &value);
		zval_ptr_dtor(&value);
		channel_wake_sender(channel);
		channel_refresh_deadlock_timer(channel);
		ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
		return true;
	}

	/* Off the event, still in the queue: a later close() notifies the event with
	 * its exception, and this receiver already has a value of its own. The queue
	 * entry is what channel_wait_for() removes when the receiver runs. */
	channel->channel.event.del_callback(&channel->channel.event, &waiter->callback.base);
	waiter->reserved = true;
	channel->reserved_receivers++;
	waiter->callback.base.callback(&channel->channel.event, &waiter->callback.base, NULL, NULL);
	channel_refresh_deadlock_timer(channel);
	return true;
}

/**
 * Wakes the rendezvous sender whose value has just been taken. It is owed an
 * acknowledgement, not a slot, so this costs no reservation; the slot its value
 * left behind goes to the next sender when this one runs its cleanup.
 *
 * It leaves the queue here, unlike a reserved waiter: its send() has succeeded
 * the moment the value was taken, and a close() arriving before it runs would
 * otherwise fail a sender whose message the receiver already holds. Leaving
 * also keeps at most one delivering waiter queued, which is what makes
 * channel_queue_delivering() unambiguous.
 */
static bool channel_wake_delivered_sender(async_channel_t *channel)
{
	if (channel_is_buffered(channel) || channel->rendezvous_has_value) {
		return false;
	}

	channel_waiter_t *waiter = channel_queue_delivering(&channel->waiting_senders);
	if (waiter == NULL) {
		return false;
	}

	channel_queue_remove(&channel->waiting_senders, waiter);
	channel->channel.event.del_callback(&channel->channel.event, &waiter->callback.base);
	waiter->callback.base.callback(&channel->channel.event, &waiter->callback.base, NULL, NULL);
	channel_refresh_deadlock_timer(channel);
	ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
	return true;
}

/* Wakes one sender: the one whose value has just been taken if there is such a
 * sender, otherwise the oldest one waiting for a free slot. Returns false when
 * every slot is already promised, or when nobody waits for one.
 *
 * The second case is channel_wake_receiver() read over free slots instead of
 * values. Senders are always coroutine waiters, so a slot handed out there is
 * always reserved; the first case costs no reservation. */
static bool channel_wake_sender(async_channel_t *channel)
{
	if (channel_wake_delivered_sender(channel)) {
		return true;
	}

	if (!channel_has_free_slot(channel)) {
		return false;
	}

	channel_waiter_t *waiter = channel_queue_first_unreserved(&channel->waiting_senders);
	if (waiter == NULL) {
		return false;
	}

	channel->channel.event.del_callback(&channel->channel.event, &waiter->callback.base);
	waiter->reserved = true;
	channel->reserved_senders++;
	waiter->callback.base.callback(&channel->channel.event, &waiter->callback.base, NULL, NULL);
	channel_refresh_deadlock_timer(channel);
	return true;
}

/**
 * Fails every waiter that is still waiting for something.
 *
 * A receiver holding a reservation is left alone: it was already woken, the
 * value it was promised is still in the channel, and recv() takes what it was
 * given before it looks at the closed flag. Failing it here would drop a value
 * the sender was told had been delivered. Every sender still queued is waiting
 * for something, because a sender whose value was taken has left the queue
 * already, so senders are failed without exception.
 *
 * The queues are walked from the tail: every removal shifts what follows, and
 * going backwards leaves the untouched part of the walk in place.
 */
static void channel_wake_all(async_channel_t *channel, zend_object *exception)
{
	uint32_t index = channel->waiting_receivers.length;
	while (index > 0) {
		index--;
		channel_waiter_t *waiter = (channel_waiter_t *) channel->waiting_receivers.data[index];
		if (waiter->reserved) {
			continue;
		}

		channel_queue_remove_at(&channel->waiting_receivers, index);
		channel->channel.event.del_callback(&channel->channel.event, &waiter->callback.base);
		if (waiter->future != NULL) {
			ZEND_FUTURE_REJECT(waiter->future, exception);
		} else {
			waiter->callback.base.callback(&channel->channel.event, &waiter->callback.base, NULL, exception);
		}
		ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
	}

	index = channel->waiting_senders.length;
	while (index > 0) {
		index--;
		channel_waiter_t *waiter = (channel_waiter_t *) channel->waiting_senders.data[index];

		channel_queue_remove_at(&channel->waiting_senders, index);
		channel->channel.event.del_callback(&channel->channel.event, &waiter->callback.base);
		waiter->callback.base.callback(&channel->channel.event, &waiter->callback.base, NULL, exception);
		ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
	}

	ZEND_ASYNC_CALLBACKS_NOTIFY(&channel->channel.event, NULL, exception);
}

static void channel_close(async_channel_t *channel, channel_close_reason_t reason)
{
	if (ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		return;
	}

	channel->close_reason = reason;
	ZEND_ASYNC_EVENT_SET_CLOSED(&channel->channel.event);
	channel_disarm_deadlock_timer(channel);

	zend_object *ex = make_channel_exception(reason);
	channel_wake_all(channel, ex);
	OBJ_RELEASE(ex);

	/* Roll back an uncommitted rendezvous value: a parked send() that never
	 * matched a receiver was just failed above, so its value must not survive
	 * for a later recv() (split-brain). A committed rendezvous — receiver
	 * already woken — is left for that receiver to complete. */
	if (channel->rendezvous_has_value && !channel->rendezvous_committed) {
		zval_ptr_dtor(&channel->rendezvous_value);
		ZVAL_UNDEF(&channel->rendezvous_value);
		channel->rendezvous_has_value = false;
	}
}

///////////////////////////////////////////////////////////////////////////////
// Owner scope binding
///////////////////////////////////////////////////////////////////////////////

static void channel_scope_close_fire(zend_async_event_t *scope_event,
									 zend_async_event_callback_t *callback,
									 void *result,
									 zend_object *exception)
{
	async_channel_t *channel = (async_channel_t *) ((char *) callback - offsetof(async_channel_t, scope_close_callback));

	channel_close(channel, CHANNEL_CLOSE_SCOPE_DISPOSED);
}

/* Called by the scope's callbacks_free during scope teardown. After this point
 * the scope event is gone — clear the back-pointer so free_obj doesn't try to
 * del_callback on freed memory. */
static void channel_scope_close_dispose(zend_async_event_callback_t *callback, zend_async_event_t *event)
{
	channel_scope_callback_t *cb = (channel_scope_callback_t *) callback;
	cb->scope_event = NULL;
}

static void channel_bind_to_owner_scope(async_channel_t *channel)
{
	zend_async_scope_t *scope = ZEND_ASYNC_CURRENT_SCOPE;
	if (scope == NULL) {
		scope = ZEND_ASYNC_MAIN_SCOPE;
	}
	if (scope == NULL || ZEND_ASYNC_EVENT_IS_CLOSED(&scope->event)) {
		return;
	}

	channel->scope_close_callback.base.ref_count = 1;
	channel->scope_close_callback.base.callback  = channel_scope_close_fire;
	channel->scope_close_callback.base.dispose   = channel_scope_close_dispose;
	channel->scope_close_callback.scope_event    = &scope->event;

	scope->event.add_callback(&scope->event, &channel->scope_close_callback.base);
}

static void channel_unbind_from_owner_scope(async_channel_t *channel)
{
	zend_async_event_t *scope_event = channel->scope_close_callback.scope_event;
	if (scope_event == NULL) {
		return;
	}

	channel->scope_close_callback.scope_event = NULL;
	scope_event->del_callback(scope_event, &channel->scope_close_callback.base);
}

bool async_channel_resolve_deadlocks(void)
{
	HashTable *registry = &ASYNC_G(deadlock_channels);
	if (zend_hash_num_elements(registry) == 0) {
		return false;
	}

	/* Snapshot — closing a channel mutates the registry via disarm. */
	HashTable snapshot;
	zend_hash_init(&snapshot, zend_hash_num_elements(registry), NULL, NULL, 0);

	async_channel_t *channel;
	ZEND_HASH_FOREACH_PTR(registry, channel)
	{
		zend_hash_index_add_ptr(&snapshot, (zend_ulong) (uintptr_t) channel, channel);
	}
	ZEND_HASH_FOREACH_END();

	bool resolved = false;
	ZEND_HASH_FOREACH_PTR(&snapshot, channel)
	{
		if (!ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
			channel_close(channel, CHANNEL_CLOSE_DEADLOCK);
			resolved = true;
		}
	}
	ZEND_HASH_FOREACH_END();

	zend_hash_destroy(&snapshot);
	return resolved;
}

///////////////////////////////////////////////////////////////////////////////
// Wait operations
///////////////////////////////////////////////////////////////////////////////

/**
 * Parks the current coroutine until the channel reserves a value (receivers) or
 * a free slot (senders) for it. With delivering set, the caller is a rendezvous
 * sender whose value already sits in the slot: it is parked until a receiver
 * takes that value, and nothing is reserved for it.
 *
 * Returns true when the caller came back holding that reservation and has to
 * spend it on the retry that follows. False covers three cases: it was woken
 * without one (a rendezvous sender waiting for delivery), it was failed by a
 * cancellation or a close, or its reservation has just been handed to the next
 * waiter — in none of them may the caller take anything.
 */
static bool channel_wait_for(async_channel_t *channel,
							 zend_async_callbacks_vector_t *queue,
							 zend_object *cancellation_token,
							 bool delivering)
{
	if (cancellation_token != NULL && UNEXPECTED(async_resolve_cancel_token(cancellation_token))) {
		return false;
	}

	channel_waiter_t *waiter = ecalloc(1, sizeof(channel_waiter_t));
	waiter->callback.base.callback = zend_async_waker_callback_resolve;
	waiter->callback.base.ref_count = 1;
	waiter->callback.coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	waiter->callback.event = &channel->channel.event;
	waiter->future = NULL;
	waiter->delivering = delivering;

	channel_queue_push(queue, waiter);
	channel_refresh_deadlock_timer(channel);

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

	const bool is_receiver = (queue == &channel->waiting_receivers);
	const bool had_reservation = waiter->reserved;

	/* Cleanup after waking up */
	if (channel_queue_remove(queue, waiter)) {
		/* Still queued unless close() drained it or the delivery wakeup took it
		 * out - release the queue's ref */
		ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
	}

	if (had_reservation) {
		/* Spent by the caller's retry after this returns, or handed on right here. */
		if (is_receiver) {
			ZEND_ASSERT(channel->reserved_receivers > 0 && "A receiver reservation was released twice");
			channel->reserved_receivers--;
		} else {
			ZEND_ASSERT(channel->reserved_senders > 0 && "A sender reservation was released twice");
			channel->reserved_senders--;
		}
	}

	/* Leaving without spending it would strand the value or the slot: nobody
	 * else is allowed to take it and no further wakeup is coming. A rendezvous
	 * sender holds no reservation, but its value has just left the slot and
	 * only it stands between that slot and the next sender. A closed channel has
	 * nobody left to hand anything to, because close() failed every waiter that
	 * was still waiting, and its remaining values are drained by the fast path of
	 * the next recv(). */
	const bool spent = had_reservation && EXPECTED(EG(exception) == NULL);

	if ((waiter->delivering || (had_reservation && !spent)) &&
		!ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		if (is_receiver) {
			channel_wake_receiver(channel);
		} else {
			channel_wake_sender(channel);
		}
	}

	channel_refresh_deadlock_timer(channel);
	/* Release our initial ref */
	ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);

	return spent;
}

#define CHANNEL_WAIT_FOR_DATA(ch, ct) channel_wait_for((ch), &(ch)->waiting_receivers, (ct), false)

#define CHANNEL_WAIT_FOR_SPACE(ch, ct) channel_wait_for((ch), &(ch)->waiting_senders, (ct), false)

/* Rendezvous only: the value is already in the slot and this waits for a
 * receiver to take it. */
#define CHANNEL_WAIT_FOR_DELIVERY(ch, ct) channel_wait_for((ch), &(ch)->waiting_senders, (ct), true)

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
						   "Channel(capacity=%d, receivers=%u, senders=%u, reserved=%u/%u)",
						   channel->capacity,
						   channel->waiting_receivers.length,
						   channel->waiting_senders.length,
						   channel->reserved_receivers,
						   channel->reserved_senders);
}

static void channel_event_init(async_channel_t *channel)
{
	zend_async_event_t *event = &channel->channel.event;
	memset(event, 0, sizeof(zend_async_event_t));

	event->flags = ZEND_ASYNC_EVENT_F_ZEND_OBJ;
	event->zend_object_offset = offsetof(async_channel_t, std);
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
	channel->rendezvous_committed = false;
	channel->reserved_receivers = 0;
	channel->reserved_senders = 0;

	channel->no_producer_timeout_ms = 0;
	channel->no_consumer_timeout_ms = 0;
	channel->hard_timeouts = false;
	channel->deadlock_timer = NULL;
	channel->pending_timeout_reason = CHANNEL_CLOSE_EXPLICIT;
	channel->close_reason = CHANNEL_CLOSE_EXPLICIT;
	memset(&channel->deadlock_callback, 0, sizeof(channel->deadlock_callback));

	memset(&channel->scope_close_callback, 0, sizeof(channel->scope_close_callback));

	return &channel->std;
}

static void async_channel_dtor_object(zend_object *object)
{
	async_channel_t *channel = ASYNC_CHANNEL_FROM_OBJ(object);

	channel_close(channel, CHANNEL_CLOSE_DISPOSED);

	zend_object_std_dtor(object);
}

static void async_channel_free_object(zend_object *object)
{
	async_channel_t *channel = ASYNC_CHANNEL_FROM_OBJ(object);

	channel_unbind_from_owner_scope(channel);
	channel_disarm_deadlock_timer(channel);
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

	bool has_reservation = false;

retry:
	if (has_reservation || channel_has_free_value(channel)) {
		channel_take_value(channel, &iterator->current);
		channel_wake_sender(channel);
		iterator->valid = true;
		return;
	}

	if (ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		iterator->valid = false;
		return;
	}

	has_reservation = CHANNEL_WAIT_FOR_DATA(channel, 0);

	if (EG(exception)) {
		// An explicit close() is how iteration ends, not a failure, so the exception the waiter was woken
		// with is consumed here and foreach() finishes cleanly. Anything else -- a deadlock, a disposal, a
		// cancellation -- is left pending and propagates out of the loop, where it belongs.
		if (EG(exception)->ce == async_ce_channel_exception && channel->close_reason == CHANNEL_CLOSE_EXPLICIT) {
			zend_clear_exception();
		}

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

	// foreach() reaches this handler straight from FE_RESET, bypassing send()/recv() and the
	// ENSURE_COROUTINE_CONTEXT they run. Iterating parks the caller on the channel just like recv() does,
	// so the main coroutine has to exist first -- otherwise channel_wait_for() suspends a NULL coroutine.
	if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL)) {
		async_scheduler_launch();

		if (UNEXPECTED(EG(exception) != NULL)) {
			return NULL;
		}
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
	zend_long no_producer_timeout = 0;
	zend_long no_consumer_timeout = 0;
	bool hard_timeouts = false;

	ZEND_PARSE_PARAMETERS_START(0, 4)
	Z_PARAM_OPTIONAL
	Z_PARAM_LONG(capacity)
	Z_PARAM_LONG(no_producer_timeout)
	Z_PARAM_LONG(no_consumer_timeout)
	Z_PARAM_BOOL(hard_timeouts)
	ZEND_PARSE_PARAMETERS_END();

	if (capacity < 0 || capacity > INT32_MAX) {
		zend_argument_value_error(1, "must be between 0 and %d", INT32_MAX);
		RETURN_THROWS();
	}
	if (no_producer_timeout < 0 || no_producer_timeout > INT32_MAX) {
		zend_argument_value_error(2, "must be between 0 and %d", INT32_MAX);
		RETURN_THROWS();
	}
	if (no_consumer_timeout < 0 || no_consumer_timeout > INT32_MAX) {
		zend_argument_value_error(3, "must be between 0 and %d", INT32_MAX);
		RETURN_THROWS();
	}

	async_channel_t *channel = THIS_CHANNEL;
	channel->capacity = (int32_t) capacity;
	channel->no_producer_timeout_ms = (int32_t) no_producer_timeout;
	channel->no_consumer_timeout_ms = (int32_t) no_consumer_timeout;
	channel->hard_timeouts = hard_timeouts;

	if (capacity > 0) {
		/* circular_buffer uses one slot as sentinel, so allocate capacity+1 */
		if (circular_buffer_ctor(&channel->buffer, capacity + 1, sizeof(zval), &zend_std_persistent_allocator) ==
			FAILURE) {
			zend_throw_error(NULL, "Failed to allocate channel buffer");
			RETURN_THROWS();
		}
	}

	channel_bind_to_owner_scope(channel);
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

	/* Set when the last wakeup kept a free slot for this sender alone. */
	bool has_reservation = false;

retry:
	THROW_IF_CLOSED(channel)

	if (has_reservation || channel_has_free_slot(channel)) {
		if (channel_is_buffered(channel)) {
			zval_circular_buffer_push(&channel->buffer, value, false);
			channel_wake_receiver(channel);
			return;
		}

		ZEND_ASSERT(!channel->rendezvous_has_value && "A reserved rendezvous slot was filled by someone else");
		ZVAL_COPY(&channel->rendezvous_value, value);
		channel->rendezvous_has_value = true;
		channel->rendezvous_committed = false;

		if (channel_wake_receiver(channel)) {
			/* A receiver was matched & woken: the rendezvous is committed
			 * even though the value is still in the slot until recv() runs. */
			channel->rendezvous_committed = channel->rendezvous_has_value;
			return;
		}

		/* The value is in the slot and belongs to the channel; this waits for a
		 * receiver to take it. The freed slot goes to the next sender from the
		 * cleanup inside the wait, so nothing is left to do here. */
		CHANNEL_WAIT_FOR_DELIVERY(channel, cancellation_token);

		if (UNEXPECTED(EG(exception))) {
			RETURN_THROWS();
		}

		return;
	}

	has_reservation = CHANNEL_WAIT_FOR_SPACE(channel, cancellation_token);

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

	/* Same rule as the blocking path: a slot kept for a woken sender is not
	 * free for this one. */
	if (!channel_has_free_slot(channel)) {
		RETURN_FALSE;
	}

	if (channel_is_buffered(channel)) {
		zval_circular_buffer_push(&channel->buffer, value, false);
		channel_wake_receiver(channel);
		RETURN_TRUE;
	}

	ZVAL_COPY(&channel->rendezvous_value, value);
	channel->rendezvous_has_value = true;
	channel->rendezvous_committed = false;
	if (channel_wake_receiver(channel)) {
		channel->rendezvous_committed = channel->rendezvous_has_value;
	}
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

	/* Set when the last wakeup kept a value for this receiver alone. */
	bool has_reservation = false;

retry:
	if (has_reservation || channel_has_free_value(channel)) {
		ZEND_ASSERT(!channel_is_empty(channel) && "A reservation outlived the value it was made for");
		channel_take_value(channel, return_value);
		channel_wake_sender(channel);
		return;
	}

	THROW_IF_CLOSED(channel)

	has_reservation = CHANNEL_WAIT_FOR_DATA(channel, cancellation_token);

	if (EG(exception)) {
		RETURN_THROWS();
	}

	goto retry;
}

METHOD(recvAsync)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_channel_t *channel = THIS_CHANNEL;

	/* Reserve extra space so the future can clean up its queued waiter from
	 * its own dispose path — see channel_recv_future_dispose() above. */
	zend_future_t *future = ZEND_ASYNC_NEW_FUTURE_EX(false, sizeof(channel_recv_future_extra_t));
	if (future == NULL) {
		RETURN_THROWS();
	}

	channel_recv_future_extra_t *future_extra = ASYNC_CHANNEL_RECV_FUTURE_EXTRA(future);
	future_extra->channel = channel;
	future_extra->waiter = NULL;
	future_extra->prev_dispose = future->event.dispose;
	future->event.dispose = channel_recv_future_dispose;

	/* A value kept for a woken receiver is not free for this future either. */
	if (channel_has_free_value(channel)) {
		zval result;
		channel_take_value(channel, &result);
		channel_wake_sender(channel);
		ZEND_FUTURE_COMPLETE(future, &result);
		zval_ptr_dtor(&result);
	} else if (ZEND_ASYNC_EVENT_IS_CLOSED(&channel->channel.event)) {
		zend_object *ex = make_channel_exception(channel->close_reason);
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
		future_extra->waiter = waiter; /* arm future-side cleanup */
		ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base); /* Release our ref, queue holds one */
	}

	RETURN_OBJ(ZEND_ASYNC_NEW_FUTURE_OBJ(future));
}

METHOD(close)
{
	ZEND_PARSE_PARAMETERS_NONE();

	channel_close(THIS_CHANNEL, CHANNEL_CLOSE_EXPLICIT);
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
	async_ce_channel_close_reason = register_class_Async_ChannelCloseReason();
	async_ce_channel_exception    = register_class_Async_ChannelException(async_ce_async_exception);
	async_ce_channel              = register_class_Async_Channel(async_ce_awaitable, zend_ce_aggregate, zend_ce_countable);

	async_ce_channel->create_object = async_channel_create_object;
	async_ce_channel->get_iterator  = channel_get_iterator;

	memcpy(&async_channel_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	async_channel_handlers.offset    = offsetof(async_channel_t, std);
	async_channel_handlers.get_gc    = async_channel_get_gc;
	async_channel_handlers.dtor_obj  = async_channel_dtor_object;
	async_channel_handlers.free_obj  = async_channel_free_object;
	async_channel_handlers.clone_obj = NULL;

}
