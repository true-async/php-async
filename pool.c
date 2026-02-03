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

#include "pool.h"
#include "php_async.h"
#include "exceptions.h"
#include "scheduler.h"
#include "coroutine.h"
#include "pool_arginfo.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "internal/zval_circular_buffer.h"

/**
 * Pool - Resource pool with automatic lifecycle management.
 *
 * Architecture (similar to Channel):
 * - Waiting queue for coroutines waiting for resources
 * - zend_async_resume_when registers with waker (so SUSPEND works)
 * - We control wake order via our queue
 * - On wake, coroutine retries and takes resource from pool
 */

#define METHOD(name) PHP_METHOD(Async_Pool, name)
#define THIS_POOL_OBJ ASYNC_POOL_FROM_OBJ(Z_OBJ_P(ZEND_THIS))
#define THIS_POOL (THIS_POOL_OBJ->pool)

#define THROW_IF_CLOSED(pool) \
	if (UNEXPECTED(ZEND_ASYNC_POOL_IS_CLOSED(pool))) { \
		zend_throw_exception(async_ce_pool_exception, "Pool is closed", 0); \
		RETURN_THROWS(); \
	}

#define ENSURE_COROUTINE_CONTEXT \
	if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL)) { \
		async_scheduler_launch(); \
		if (UNEXPECTED(EG(exception) != NULL)) { \
			RETURN_THROWS(); \
		} \
	}

zend_class_entry *async_ce_pool = NULL;
zend_class_entry *async_ce_pool_exception = NULL;
static zend_object_handlers async_pool_handlers;

///////////////////////////////////////////////////////////////////////////////
// Helper to release zend_fcall_t
///////////////////////////////////////////////////////////////////////////////

static void pool_fcall_release(zend_fcall_t *fcall)
{
	if (fcall == NULL) {
		return;
	}

	if (fcall->fci.param_count) {
		for (uint32_t i = 0; i < fcall->fci.param_count; i++) {
			zval_ptr_dtor(&fcall->fci.params[i]);
		}
		efree(fcall->fci.params);
	}

	if (fcall->fci.named_params) {
		GC_DELREF(fcall->fci.named_params);
	}

	/* Release the function_name only if it's refcounted */
	if (Z_REFCOUNTED(fcall->fci.function_name)) {
		zval_ptr_dtor(&fcall->fci.function_name);
	}
	efree(fcall);
}

///////////////////////////////////////////////////////////////////////////////
// Queue operations (copied from channel.c)
///////////////////////////////////////////////////////////////////////////////

static void pool_queue_init(zend_async_callbacks_vector_t *queue)
{
	queue->data = NULL;
	queue->length = 0;
	queue->capacity = 0;
}

static void pool_queue_free(zend_async_callbacks_vector_t *queue)
{
	if (queue->data) {
		efree(queue->data);
		queue->data = NULL;
	}
}

static void pool_queue_push(zend_async_callbacks_vector_t *queue, zend_async_pool_waiter_t *waiter)
{
	if (queue->length >= queue->capacity) {
		uint32_t new_capacity = queue->capacity ? queue->capacity * 2 : 4;
		queue->data = erealloc(queue->data, new_capacity * sizeof(void *));
		queue->capacity = new_capacity;
	}
	waiter->callback.base.ref_count++;
	queue->data[queue->length++] = (zend_async_event_callback_t *)waiter;
}

static zend_async_pool_waiter_t *pool_queue_pop(zend_async_callbacks_vector_t *queue)
{
	if (queue->length == 0) {
		return NULL;
	}
	zend_async_pool_waiter_t *waiter = (zend_async_pool_waiter_t *)queue->data[0];
	queue->length--;
	/* Swap with last element - O(1) */
	queue->data[0] = queue->data[queue->length];
	return waiter;
}

static bool pool_queue_remove(zend_async_callbacks_vector_t *queue, zend_async_pool_waiter_t *waiter)
{
	for (uint32_t i = 0; i < queue->length; i++) {
		if (queue->data[i] == (zend_async_event_callback_t *)waiter) {
			queue->length--;
			queue->data[i] = queue->data[queue->length];
			return true;
		}
	}
	return false;
}

///////////////////////////////////////////////////////////////////////////////
// Resource creation/destruction
///////////////////////////////////////////////////////////////////////////////

static bool pool_create_resource(zend_async_pool_t *pool, zval *result)
{
	if (pool->factory == NULL) {
		return false;
	}

	zval retval;
	ZVAL_UNDEF(&retval);

	pool->factory->fci.retval = &retval;

	if (zend_call_function(&pool->factory->fci, &pool->factory->fci_cache) == FAILURE) {
		return false;
	}

	if (EG(exception)) {
		zval_ptr_dtor(&retval);
		return false;
	}

	ZVAL_COPY_VALUE(result, &retval);
	return true;
}

static void pool_destroy_resource(zend_async_pool_t *pool, zval *resource)
{
	if (pool->destructor != NULL) {
		zval retval;
		ZVAL_UNDEF(&retval);

		zval args[1];
		ZVAL_COPY(&args[0], resource);

		/* Use local copy of fci to avoid corrupting the stored structure */
		zend_fcall_info fci = pool->destructor->fci;
		fci.retval = &retval;
		fci.param_count = 1;
		fci.params = args;

		zend_call_function(&fci, &pool->destructor->fci_cache);

		zval_ptr_dtor(&args[0]);
		zval_ptr_dtor(&retval);

		/* Ignore exceptions from destructor */
		if (EG(exception)) {
			zend_clear_exception();
		}
	}

	zval_ptr_dtor(resource);
}

///////////////////////////////////////////////////////////////////////////////
// Callback helpers
///////////////////////////////////////////////////////////////////////////////

/* Call beforeAcquire callback, returns true if resource is OK */
static bool pool_call_before_acquire(zend_async_pool_t *pool, zval *resource)
{
	if (pool->before_acquire == NULL) {
		return true;
	}

	zval retval;
	ZVAL_UNDEF(&retval);

	zval args[1];
	ZVAL_COPY(&args[0], resource);

	/* Use local copy of fci to avoid corrupting the stored structure */
	zend_fcall_info fci = pool->before_acquire->fci;
	fci.retval = &retval;
	fci.param_count = 1;
	fci.params = args;

	zend_call_function(&fci, &pool->before_acquire->fci_cache);

	zval_ptr_dtor(&args[0]);

	bool result = true;
	if (EG(exception)) {
		result = false;
		zend_clear_exception();
	} else if (Z_TYPE(retval) == IS_FALSE) {
		result = false;
	}

	zval_ptr_dtor(&retval);
	return result;
}

/* Call beforeRelease callback, returns true if resource should be returned to pool */
static bool pool_call_before_release(zend_async_pool_t *pool, zval *resource)
{
	if (pool->before_release == NULL) {
		return true;
	}

	zval retval;
	ZVAL_UNDEF(&retval);

	zval args[1];
	ZVAL_COPY(&args[0], resource);

	/* Use local copy of fci to avoid corrupting the stored structure */
	zend_fcall_info fci = pool->before_release->fci;
	fci.retval = &retval;
	fci.param_count = 1;
	fci.params = args;

	zend_call_function(&fci, &pool->before_release->fci_cache);

	zval_ptr_dtor(&args[0]);

	bool result = true;
	if (EG(exception)) {
		result = false;
		zend_clear_exception();
	} else if (Z_TYPE(retval) == IS_FALSE) {
		result = false;
	}

	zval_ptr_dtor(&retval);
	return result;
}

///////////////////////////////////////////////////////////////////////////////
// Wait/Wake operations (like channel)
///////////////////////////////////////////////////////////////////////////////

static void pool_wait_for_resource(zend_async_pool_t *pool, zend_long timeout_ms)
{
	zend_async_pool_waiter_t *waiter = ecalloc(1, sizeof(zend_async_pool_waiter_t));
	waiter->callback.base.callback = zend_async_waker_callback_resolve;
	waiter->callback.base.ref_count = 1;
	waiter->callback.coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	waiter->callback.event = &pool->event;

	pool_queue_push(&pool->waiters, waiter);

	zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE, &pool->event,
		false, zend_async_waker_callback_resolve, &waiter->callback);

	if (timeout_ms > 0) {
		zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE,
			&ZEND_ASYNC_NEW_TIMER_EVENT(timeout_ms, false)->base,
			true, zend_async_waker_callback_timeout, NULL);
	}

	ZEND_ASYNC_SUSPEND();

	/* Cleanup after waking up */
	if (pool_queue_remove(&pool->waiters, waiter)) {
		/* Was still in queue (timeout/close case) - release queue's ref */
		ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
	}
	/* Release our initial ref */
	ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
}

static bool pool_wake_waiter(zend_async_pool_t *pool)
{
	zend_async_pool_waiter_t *waiter = pool_queue_pop(&pool->waiters);
	if (waiter == NULL) {
		return false;
	}

	pool->event.del_callback(&pool->event, &waiter->callback.base);
	waiter->callback.base.callback(&pool->event, &waiter->callback.base, NULL, NULL);
	ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);

	return true;
}

static void pool_wake_all_with_exception(zend_async_pool_t *pool, zend_object *exception)
{
	zend_async_pool_waiter_t *waiter;
	while ((waiter = pool_queue_pop(&pool->waiters)) != NULL) {
		pool->event.del_callback(&pool->event, &waiter->callback.base);
		waiter->callback.base.callback(&pool->event, &waiter->callback.base, NULL, exception);
		ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&waiter->callback.base);
	}
}

///////////////////////////////////////////////////////////////////////////////
// Event handlers
///////////////////////////////////////////////////////////////////////////////

static bool pool_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_push(event, callback);
}

static bool pool_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_remove(event, callback);
}

static bool pool_dispose(zend_async_event_t *event)
{
	return true;
}

static bool pool_start(zend_async_event_t *event)
{
	return true;
}

static bool pool_stop(zend_async_event_t *event)
{
	return true;
}

static void pool_event_init(zend_async_pool_t *pool)
{
	zend_async_event_t *event = &pool->event;
	memset(event, 0, sizeof(zend_async_event_t));

	event->add_callback = pool_add_callback;
	event->del_callback = pool_del_callback;
	event->start = pool_start;
	event->stop = pool_stop;
	event->dispose = pool_dispose;
}

///////////////////////////////////////////////////////////////////////////////
// Healthcheck timer
///////////////////////////////////////////////////////////////////////////////

static void pool_healthcheck_timer_callback(
	zend_async_event_t *timer_event,
	zend_async_event_callback_t *callback,
	void *result,
	zend_object *exception
) {
	zend_async_pool_t *pool = (zend_async_pool_t *)callback;

	if (pool->healthcheck == NULL || ZEND_ASYNC_POOL_IS_CLOSED(pool)) {
		return;
	}

	/* Process all idle resources */
	size_t count = circular_buffer_count(&pool->idle);

	for (size_t i = 0; i < count; i++) {
		zval resource;
		if (zval_circular_buffer_pop(&pool->idle, &resource) != SUCCESS) {
			break;
		}

		/* Call healthcheck callback */
		zval retval;
		ZVAL_UNDEF(&retval);

		zval args[1];
		ZVAL_COPY(&args[0], &resource);

		/* Use local copy of fci to avoid corrupting the stored structure */
		zend_fcall_info fci = pool->healthcheck->fci;
		fci.retval = &retval;
		fci.param_count = 1;
		fci.params = args;

		zend_call_function(&fci, &pool->healthcheck->fci_cache);

		zval_ptr_dtor(&args[0]);

		bool is_healthy = true;
		if (EG(exception)) {
			is_healthy = false;
			zend_clear_exception();
		} else if (Z_TYPE(retval) == IS_FALSE) {
			is_healthy = false;
		}
		zval_ptr_dtor(&retval);

		if (is_healthy) {
			/* Resource is healthy - return to buffer */
			zval_circular_buffer_push(&pool->idle, &resource, false);
			zval_ptr_dtor(&resource);
		} else {
			/* Resource is dead - destroy it */
			pool_destroy_resource(pool, &resource);

			/* Create new one to maintain min_size */
			uint32_t total = ZEND_ASYNC_POOL_TOTAL(pool);
			if (total < pool->min_size) {
				zval new_resource;
				if (pool_create_resource(pool, &new_resource)) {
					zval_circular_buffer_push(&pool->idle, &new_resource, false);
					zval_ptr_dtor(&new_resource);
				}
			}
		}
	}
}

static void pool_start_healthcheck_timer(zend_async_pool_t *pool)
{
	if (pool->healthcheck == NULL || pool->healthcheck_interval_ms == 0) {
		return;
	}

	pool->healthcheck_timer = ZEND_ASYNC_NEW_TIMER_EVENT(pool->healthcheck_interval_ms, true);

	if (pool->healthcheck_timer == NULL) {
		return;
	}

	/* Use pool pointer as callback data */
	zend_async_event_callback_t *callback = (zend_async_event_callback_t *)pool;
	callback->callback = pool_healthcheck_timer_callback;
	callback->ref_count = 1;

	pool->healthcheck_timer->base.add_callback(&pool->healthcheck_timer->base, callback);
	pool->healthcheck_timer->base.start(&pool->healthcheck_timer->base);
}

static void pool_stop_healthcheck_timer(zend_async_pool_t *pool)
{
	if (pool->healthcheck_timer != NULL) {
		pool->healthcheck_timer->base.stop(&pool->healthcheck_timer->base);
		ZEND_ASYNC_EVENT_RELEASE(&pool->healthcheck_timer->base);
		pool->healthcheck_timer = NULL;
	}
}

///////////////////////////////////////////////////////////////////////////////
// C API implementation
///////////////////////////////////////////////////////////////////////////////

zend_async_pool_t *zend_async_pool_create(
	zend_fcall_t *factory,
	zend_fcall_t *destructor,
	zend_fcall_t *healthcheck,
	zend_fcall_t *before_acquire,
	zend_fcall_t *before_release,
	uint32_t min_size,
	uint32_t max_size,
	uint32_t healthcheck_interval_ms
) {
	zend_async_pool_t *pool = ecalloc(1, sizeof(zend_async_pool_t));

	/* Event init */
	pool_event_init(pool);

	/* Callbacks */
	pool->factory = factory;
	pool->destructor = destructor;
	pool->healthcheck = healthcheck;
	pool->before_acquire = before_acquire;
	pool->before_release = before_release;

	/* Config */
	pool->min_size = min_size;
	pool->max_size = max_size;
	pool->healthcheck_interval_ms = healthcheck_interval_ms;
	pool->active_count = 0;

	/* Init idle buffer */
	circular_buffer_ctor(&pool->idle, 8, sizeof(zval), &zend_std_persistent_allocator);

	/* Init waiters queue */
	pool_queue_init(&pool->waiters);

	/* Pre-warm: create min_size resources */
	for (uint32_t i = 0; i < min_size; i++) {
		zval resource;
		if (pool_create_resource(pool, &resource)) {
			zval_circular_buffer_push(&pool->idle, &resource, false);
			zval_ptr_dtor(&resource);
		} else if (EG(exception)) {
			/* Stop on first error */
			break;
		}
	}

	/* Start healthcheck timer */
	pool_start_healthcheck_timer(pool);

	return pool;
}

bool zend_async_pool_acquire(zend_async_pool_t *pool, zval *result, zend_long timeout_ms)
{
retry:
	/* 1. Closed? */
	if (ZEND_ASYNC_POOL_IS_CLOSED(pool)) {
		zend_throw_exception(async_ce_pool_exception, "Pool is closed", 0);
		return false;
	}

	/* 2. Have idle resource? */
	if (!circular_buffer_is_empty(&pool->idle)) {
		zval resource;
		zval_circular_buffer_pop(&pool->idle, &resource);

		/* beforeAcquire check (if set) */
		if (!pool_call_before_acquire(pool, &resource)) {
			/* Check failed - destroy and try next */
			pool_destroy_resource(pool, &resource);
			goto retry;
		}

		ZVAL_COPY_VALUE(result, &resource);
		pool->active_count++;
		return true;
	}

	/* 3. Can create new? */
	uint32_t total = ZEND_ASYNC_POOL_TOTAL(pool);
	if (total < pool->max_size) {
		if (pool_create_resource(pool, result)) {
			pool->active_count++;
			return true;
		}
		/* Factory failed - fall through to wait */
	}

	/* 4. Wait - like channel */
	pool_wait_for_resource(pool, timeout_ms);

	if (EG(exception)) {
		return false;
	}

	goto retry;
}

bool zend_async_pool_try_acquire(zend_async_pool_t *pool, zval *result)
{
retry:
	if (ZEND_ASYNC_POOL_IS_CLOSED(pool)) {
		return false;
	}

	/* Have idle? */
	if (!circular_buffer_is_empty(&pool->idle)) {
		zval resource;
		zval_circular_buffer_pop(&pool->idle, &resource);

		/* beforeAcquire check */
		if (!pool_call_before_acquire(pool, &resource)) {
			pool_destroy_resource(pool, &resource);
			goto retry;
		}

		ZVAL_COPY_VALUE(result, &resource);
		pool->active_count++;
		return true;
	}

	/* Can create new? */
	uint32_t total = ZEND_ASYNC_POOL_TOTAL(pool);
	if (total < pool->max_size) {
		if (pool_create_resource(pool, result)) {
			pool->active_count++;
			return true;
		}
	}

	/* No resource available */
	return false;
}

void zend_async_pool_release(zend_async_pool_t *pool, zval *resource)
{
	pool->active_count--;

	/* beforeRelease callback (if set) */
	/* Returns false = resource is broken, destroy it */
	if (!pool_call_before_release(pool, resource)) {
		pool_destroy_resource(pool, resource);
		return;
	}

	/* Closed? Destroy resource */
	if (ZEND_ASYNC_POOL_IS_CLOSED(pool)) {
		pool_destroy_resource(pool, resource);
		return;
	}

	/* Have waiters? Wake first one - it will take resource in retry */
	if (pool_wake_waiter(pool)) {
		/* Return resource to buffer, waiter will take it */
		zval_circular_buffer_push(&pool->idle, resource, false);
		return;
	}

	/* No waiters - just return to buffer */
	zval_circular_buffer_push(&pool->idle, resource, false);
}

void zend_async_pool_close(zend_async_pool_t *pool)
{
	if (ZEND_ASYNC_POOL_IS_CLOSED(pool)) {
		return;
	}

	ZEND_ASYNC_EVENT_SET_CLOSED(&pool->event);

	/* Stop healthcheck timer */
	pool_stop_healthcheck_timer(pool);

	/* Wake all waiters with exception */
	zend_object *ex = async_new_exception(async_ce_pool_exception, "Pool is closed");
	pool_wake_all_with_exception(pool, ex);
	OBJ_RELEASE(ex);

	/* Destroy all idle resources */
	zval resource;
	while (!circular_buffer_is_empty(&pool->idle)) {
		if (zval_circular_buffer_pop(&pool->idle, &resource) == SUCCESS) {
			pool_destroy_resource(pool, &resource);
		}
	}
}

void zend_async_pool_destroy(zend_async_pool_t *pool)
{
	/* Close if not already closed */
	if (!ZEND_ASYNC_POOL_IS_CLOSED(pool)) {
		zend_async_pool_close(pool);
	}

	/* Free callbacks */
	zend_async_callbacks_free(&pool->event);
	pool_queue_free(&pool->waiters);

	/* Free circular buffer */
	circular_buffer_dtor(&pool->idle);

	/* Free fcall structures */
	if (pool->factory) {
		pool_fcall_release(pool->factory);
	}
	if (pool->destructor) {
		pool_fcall_release(pool->destructor);
	}
	if (pool->healthcheck) {
		pool_fcall_release(pool->healthcheck);
	}
	if (pool->before_acquire) {
		pool_fcall_release(pool->before_acquire);
	}
	if (pool->before_release) {
		pool_fcall_release(pool->before_release);
	}

	efree(pool);
}

uint32_t zend_async_pool_count(zend_async_pool_t *pool)
{
	return ZEND_ASYNC_POOL_TOTAL(pool);
}

uint32_t zend_async_pool_idle_count(zend_async_pool_t *pool)
{
	return circular_buffer_count(&pool->idle);
}

uint32_t zend_async_pool_active_count(zend_async_pool_t *pool)
{
	return pool->active_count;
}

///////////////////////////////////////////////////////////////////////////////
// PHP object handlers
///////////////////////////////////////////////////////////////////////////////

static zend_object *async_pool_create_object(zend_class_entry *ce)
{
	async_pool_t *obj = zend_object_alloc(sizeof(async_pool_t), ce);

	zend_object_std_init(&obj->std, ce);
	obj->std.handlers = &async_pool_handlers;
	obj->pool = NULL;

	return &obj->std;
}

static void async_pool_free_object(zend_object *object)
{
	async_pool_t *obj = ASYNC_POOL_FROM_OBJ(object);

	if (obj->pool != NULL) {
		zend_async_pool_destroy(obj->pool);
		obj->pool = NULL;
	}

	zend_object_std_dtor(object);
}

static HashTable *async_pool_get_gc(zend_object *object, zval **table, int *num)
{
	async_pool_t *obj = ASYNC_POOL_FROM_OBJ(object);

	if (obj->pool == NULL) {
		*table = NULL;
		*num = 0;
		return NULL;
	}

	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();

	/* GC for idle resources */
	circular_buffer_t *cb = &obj->pool->idle;
	size_t idx = cb->tail;
	while (idx != cb->head) {
		zval *val = (zval *)((char *)cb->data + idx * cb->item_size);
		zend_get_gc_buffer_add_zval(buf, val);
		idx = (idx + 1) & (cb->capacity - 1);
	}

	zend_get_gc_buffer_use(buf, table, num);
	return NULL;
}

///////////////////////////////////////////////////////////////////////////////
// PHP Methods
///////////////////////////////////////////////////////////////////////////////

METHOD(__construct)
{
	zend_fcall_info fci_factory = {0}, fci_destructor = {0}, fci_healthcheck = {0};
	zend_fcall_info fci_before_acquire = {0}, fci_before_release = {0};
	zend_fcall_info_cache fcc_factory = {0}, fcc_destructor = {0}, fcc_healthcheck = {0};
	zend_fcall_info_cache fcc_before_acquire = {0}, fcc_before_release = {0};
	zend_long min = 0;
	zend_long max = 10;
	zend_long healthcheck_interval = 0;

	ZEND_PARSE_PARAMETERS_START(1, 8)
		Z_PARAM_FUNC(fci_factory, fcc_factory)
		Z_PARAM_OPTIONAL
		Z_PARAM_FUNC_OR_NULL(fci_destructor, fcc_destructor)
		Z_PARAM_FUNC_OR_NULL(fci_healthcheck, fcc_healthcheck)
		Z_PARAM_FUNC_OR_NULL(fci_before_acquire, fcc_before_acquire)
		Z_PARAM_FUNC_OR_NULL(fci_before_release, fcc_before_release)
		Z_PARAM_LONG(min)
		Z_PARAM_LONG(max)
		Z_PARAM_LONG(healthcheck_interval)
	ZEND_PARSE_PARAMETERS_END();

	if (min < 0) {
		zend_argument_value_error(6, "must be >= 0");
		RETURN_THROWS();
	}
	if (max < 1) {
		zend_argument_value_error(7, "must be >= 1");
		RETURN_THROWS();
	}
	if (min > max) {
		zend_argument_value_error(6, "must be <= max");
		RETURN_THROWS();
	}
	if (healthcheck_interval < 0) {
		zend_argument_value_error(8, "must be >= 0");
		RETURN_THROWS();
	}

	/* Copy fcalls */
	zend_fcall_t *factory = NULL;
	zend_fcall_t *destructor = NULL;
	zend_fcall_t *healthcheck = NULL;
	zend_fcall_t *before_acquire = NULL;
	zend_fcall_t *before_release = NULL;

	factory = ecalloc(1, sizeof(zend_fcall_t));
	factory->fci = fci_factory;
	factory->fci_cache = fcc_factory;
	ZVAL_COPY(&factory->fci.function_name, &fci_factory.function_name);

	if (ZEND_FCI_INITIALIZED(fci_destructor)) {
		destructor = ecalloc(1, sizeof(zend_fcall_t));
		destructor->fci = fci_destructor;
		destructor->fci_cache = fcc_destructor;
		ZVAL_COPY(&destructor->fci.function_name, &fci_destructor.function_name);
	}

	if (ZEND_FCI_INITIALIZED(fci_healthcheck)) {
		healthcheck = ecalloc(1, sizeof(zend_fcall_t));
		healthcheck->fci = fci_healthcheck;
		healthcheck->fci_cache = fcc_healthcheck;
		ZVAL_COPY(&healthcheck->fci.function_name, &fci_healthcheck.function_name);
	}

	if (ZEND_FCI_INITIALIZED(fci_before_acquire)) {
		before_acquire = ecalloc(1, sizeof(zend_fcall_t));
		before_acquire->fci = fci_before_acquire;
		before_acquire->fci_cache = fcc_before_acquire;
		ZVAL_COPY(&before_acquire->fci.function_name, &fci_before_acquire.function_name);
	}

	if (ZEND_FCI_INITIALIZED(fci_before_release)) {
		before_release = ecalloc(1, sizeof(zend_fcall_t));
		before_release->fci = fci_before_release;
		before_release->fci_cache = fcc_before_release;
		ZVAL_COPY(&before_release->fci.function_name, &fci_before_release.function_name);
	}

	async_pool_t *obj = THIS_POOL_OBJ;
	obj->pool = zend_async_pool_create(
		factory,
		destructor,
		healthcheck,
		before_acquire,
		before_release,
		(uint32_t)min,
		(uint32_t)max,
		(uint32_t)healthcheck_interval
	);

	if (EG(exception)) {
		RETURN_THROWS();
	}
}

METHOD(acquire)
{
	zend_long timeout = 0;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(timeout)
	ZEND_PARSE_PARAMETERS_END();

	ENSURE_COROUTINE_CONTEXT

	async_pool_t *obj = THIS_POOL_OBJ;

	if (obj->pool == NULL) {
		zend_throw_exception(async_ce_pool_exception, "Pool is not initialized", 0);
		RETURN_THROWS();
	}

	if (!zend_async_pool_acquire(obj->pool, return_value, timeout)) {
		RETURN_THROWS();
	}
}

METHOD(tryAcquire)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_pool_t *obj = THIS_POOL_OBJ;

	if (obj->pool == NULL) {
		RETURN_NULL();
	}

	if (!zend_async_pool_try_acquire(obj->pool, return_value)) {
		RETURN_NULL();
	}
}

METHOD(release)
{
	zval *resource;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(resource)
	ZEND_PARSE_PARAMETERS_END();

	async_pool_t *obj = THIS_POOL_OBJ;

	if (obj->pool == NULL) {
		zend_throw_exception(async_ce_pool_exception, "Pool is not initialized", 0);
		RETURN_THROWS();
	}

	zend_async_pool_release(obj->pool, resource);
}

METHOD(close)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_pool_t *obj = THIS_POOL_OBJ;

	if (obj->pool != NULL) {
		zend_async_pool_close(obj->pool);
	}
}

METHOD(isClosed)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_pool_t *obj = THIS_POOL_OBJ;

	if (obj->pool == NULL) {
		RETURN_TRUE;
	}

	RETURN_BOOL(ZEND_ASYNC_POOL_IS_CLOSED(obj->pool));
}

METHOD(count)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_pool_t *obj = THIS_POOL_OBJ;

	if (obj->pool == NULL) {
		RETURN_LONG(0);
	}

	RETURN_LONG(zend_async_pool_count(obj->pool));
}

METHOD(idleCount)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_pool_t *obj = THIS_POOL_OBJ;

	if (obj->pool == NULL) {
		RETURN_LONG(0);
	}

	RETURN_LONG(zend_async_pool_idle_count(obj->pool));
}

METHOD(activeCount)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_pool_t *obj = THIS_POOL_OBJ;

	if (obj->pool == NULL) {
		RETURN_LONG(0);
	}

	RETURN_LONG(zend_async_pool_active_count(obj->pool));
}

///////////////////////////////////////////////////////////////////////////////
// Registration
///////////////////////////////////////////////////////////////////////////////

void async_register_pool_ce(void)
{
	async_ce_pool_exception = register_class_Async_PoolException(async_ce_async_exception);

	async_ce_pool = register_class_Async_Pool(zend_ce_countable);
	async_ce_pool->create_object = async_pool_create_object;

	memcpy(&async_pool_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	async_pool_handlers.offset = XtOffsetOf(async_pool_t, std);
	async_pool_handlers.get_gc = async_pool_get_gc;
	async_pool_handlers.free_obj = async_pool_free_object;
	async_pool_handlers.clone_obj = NULL;
}
