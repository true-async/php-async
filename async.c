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

#include "zend_exceptions.h"
#include "zend_closures.h"
#ifdef HAVE_CONFIG_H
#include <config.h>
#endif

#include "php_async.h"
#include "ext/standard/info.h"
#include "scheduler.h"
#include "exceptions.h"
#include "scope.h"
#include "context.h"
#include "async_API.h"
#include "async_arginfo.h"
#include "zend_interfaces.h"
#ifdef PHP_ASYNC_LIBUV
#include "libuv_reactor.h"
#endif

zend_class_entry * async_ce_awaitable = NULL;
zend_class_entry * async_ce_timeout = NULL;

///////////////////////////////////////////////////////////////
/// Module functions
///////////////////////////////////////////////////////////////

static zend_object *async_timeout_create(zend_ulong ms, bool is_periodic);

#define THROW_IF_SCHEDULER_CONTEXT if (UNEXPECTED(ZEND_ASYNC_IS_SCHEDULER_CONTEXT)) {		\
		async_throw_error("The operation cannot be executed in the scheduler context");		\
		RETURN_THROWS();																	\
	}

#define THROW_IF_ASYNC_OFF if (UNEXPECTED(ZEND_ASYNC_OFF)) {								\
		async_throw_error("The operation cannot be executed while async is off");			\
		RETURN_THROWS();																	\
	}

#define SCHEDULER_LAUNCH if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL)) {		\
		async_scheduler_launch();														\
		if (UNEXPECTED(EG(exception) != NULL)) {										\
			RETURN_THROWS();															\
		}																				\
	}

PHP_FUNCTION(Async_spawn)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	zval *args = NULL;
	int args_count = 0;
	HashTable *named_args = NULL;

	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(1, -1)
		Z_PARAM_FUNC(fci, fcc);
		Z_PARAM_VARIADIC_WITH_NAMED(args, args_count, named_args);
	ZEND_PARSE_PARAMETERS_END();

	async_coroutine_t * coroutine = (async_coroutine_t *) ZEND_ASYNC_SPAWN();

	if (UNEXPECTED(EG(exception))) {
		return;
	}

	ZEND_ASYNC_FCALL_DEFINE(fcall, fci, fcc, args, args_count, named_args);

	coroutine->coroutine.fcall = fcall;

	RETURN_OBJ_COPY(&coroutine->std);
}

PHP_FUNCTION(Async_spawnWith)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	zval *args = NULL;
	int args_count = 0;
	HashTable *named_args = NULL;

	zend_async_scope_t * scope = NULL;
	zend_object * scope_provider = NULL;
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(2, -1)
		Z_PARAM_OBJ_OF_CLASS(scope_provider, async_ce_scope_provider)
		Z_PARAM_FUNC(fci, fcc);
		Z_PARAM_VARIADIC_WITH_NAMED(args, args_count, named_args);
	ZEND_PARSE_PARAMETERS_END();

	// If scope_provider is an instance of async_ce_scope
	if (instanceof_function(scope_provider->ce, async_ce_scope)) {
		scope = &((async_scope_object_t *)scope_provider)->scope->scope;
	}

	async_coroutine_t * coroutine = (async_coroutine_t *)(scope_provider ? ZEND_ASYNC_SPAWN_WITH_PROVIDER(scope_provider)
									: ZEND_ASYNC_SPAWN_WITH(scope));

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	ZEND_ASYNC_FCALL_DEFINE(fcall, fci, fcc, args, args_count, named_args);

	coroutine->coroutine.fcall = fcall;

	RETURN_OBJ_COPY(&coroutine->std);
}

PHP_FUNCTION(Async_suspend)
{
	ZEND_PARSE_PARAMETERS_NONE();

	if (UNEXPECTED(ZEND_ASYNC_IS_OFF)) {
		return;
	}

	THROW_IF_SCHEDULER_CONTEXT;
	ZEND_ASYNC_ENQUEUE_COROUTINE(ZEND_ASYNC_CURRENT_COROUTINE);
	ZEND_ASYNC_SUSPEND();
}

PHP_FUNCTION(Async_protect)
{
	zend_object *closure;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJ_OF_CLASS(closure, zend_ce_closure)
	ZEND_PARSE_PARAMETERS_END();

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	bool do_bailout = false;

	zend_try {
		if (coroutine != NULL) {
			ZEND_COROUTINE_SET_PROTECTED(coroutine);
		}

		ZVAL_UNDEF(return_value);

		zval closure_zval;
		ZVAL_OBJ(&closure_zval, closure);

		if (UNEXPECTED(call_user_function(NULL, NULL, &closure_zval, return_value, 0, NULL) == FAILURE)) {
			zend_throw_error(NULL, "Failed to call finally handler in finished coroutine");
			zval_ptr_dtor(return_value);
		}

		if (Z_TYPE_P(return_value) == IS_UNDEF) {
			// If the closure did not return a value, we return NULL.
			ZVAL_NULL(return_value);
		}

	} zend_catch {
		do_bailout = true;
	} zend_end_try();

	if (coroutine != NULL) {
		ZEND_COROUTINE_CLR_PROTECTED(coroutine);
	}

	if (UNEXPECTED(do_bailout)) {
		zend_bailout();
	}

	if (UNEXPECTED(coroutine == NULL)) {
		return;
	}

	async_coroutine_t *async_coroutine = (async_coroutine_t *) coroutine;

	if (async_coroutine->deferred_cancellation) {
		ZEND_COROUTINE_SET_CANCELLED(coroutine);
		async_rethrow_exception(async_coroutine->deferred_cancellation);
		async_coroutine->deferred_cancellation = NULL;
	}
}

PHP_FUNCTION(Async_await)
{
	zend_object * awaitable = NULL;
	zend_object * cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_OBJ_OF_CLASS(awaitable, async_ce_awaitable);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		RETURN_NULL();
	}

	zend_async_event_t *awaitable_event = ZEND_ASYNC_OBJECT_TO_EVENT(awaitable);
	zend_async_event_t *cancellation_event = cancellation != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation) : NULL;

	// If the awaitable is already resolved, we can return the result immediately.
	if (ZEND_ASYNC_EVENT_IS_CLOSED(awaitable_event)) {

		if (UNEXPECTED(awaitable_event->replay == NULL)) {
			zend_error(E_CORE_WARNING, "Cannot await a closed event which cannot be replayed");
			RETURN_NULL();
		}

		if (ZEND_ASYNC_EVENT_EXTRACT_RESULT(awaitable_event, return_value)) {
			return;
		}

		RETURN_NULL();
	}

	// If the cancellation event is already resolved, we can return exception immediately.
	if (cancellation_event != NULL && ZEND_ASYNC_EVENT_IS_CLOSED(cancellation_event)) {
		if (ZEND_ASYNC_EVENT_EXTRACT_RESULT(awaitable_event, return_value)) {
			return;
		}

		async_throw_cancellation("Operation has been cancelled");
		RETURN_THROWS();
	}

	zend_async_waker_new(coroutine);

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	zend_async_resume_when(
		coroutine,
		awaitable_event,
		false,
		zend_async_waker_callback_resolve,
		NULL
	);

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	if (cancellation_event != NULL) {
		zend_async_resume_when(
			coroutine,
			cancellation_event,
			false,
			zend_async_waker_callback_cancel,
			NULL
		);

		if (UNEXPECTED(EG(exception) != NULL)) {
			RETURN_THROWS();
		}
	}

	ZEND_ASYNC_SUSPEND();

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	ZEND_ASSERT(coroutine->waker != NULL && "coroutine->waker must not be NULL");

	if (Z_TYPE(coroutine->waker->result) == IS_UNDEF) {
		ZVAL_NULL(return_value);
	} else {
		ZVAL_COPY(return_value, &coroutine->waker->result);
	}

	zend_async_waker_destroy(coroutine);
}

PHP_FUNCTION(Async_awaitAnyOrFail)
{
	zval * futures;
	zend_object * cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_ZVAL(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	HashTable * results = zend_new_array(8);

	async_await_futures(futures,
		1,
		false,
		cancellation != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation) : NULL,
		0,
		0,
		results,
		NULL,
		false,
		false,
		false
	);

	if (EG(exception)) {
		zend_array_release(results);
		RETURN_THROWS();
	}

	if (zend_hash_num_elements(results) == 0) {
		zend_array_release(results);
		RETURN_NULL();
	}

	zval result;
	ZEND_HASH_FOREACH_VAL(results, zval *item) {
		ZVAL_COPY(&result, item);
		break;
	} ZEND_HASH_FOREACH_END();

	zend_array_release(results);

	RETURN_ZVAL(&result, 0, 0);
}

PHP_FUNCTION(Async_awaitFirstSuccess)
{
	zval * futures;
	zend_object * cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_ZVAL(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	HashTable * results = zend_new_array(8);
	HashTable * errors = zend_new_array(8);

	async_await_futures(futures,
		1,
		true,
		cancellation != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation) : NULL,
		0,
		0,
		results,
		errors,
		false,
		false,
		true
	);

	if (EG(exception)) {
		zend_array_release(results);
		zend_array_release(errors);
		RETURN_THROWS();
	}

	HashTable * return_array = zend_new_array(2);

	zval val;
	ZVAL_NULL(&val);

	ZEND_HASH_FOREACH_VAL(results, zval *item) {
		ZVAL_COPY(&val, item);
		break;
	} ZEND_HASH_FOREACH_END();

	zend_hash_next_index_insert_new(return_array, &val);

	ZVAL_ARR(&val, errors);
	zend_hash_next_index_insert_new(return_array, &val);

	zend_array_release(results);

	RETURN_ARR(return_array);
}

PHP_FUNCTION(Async_awaitAllOrFail)
{
	zval * futures;
	zend_object * cancellation = NULL;
	bool preserve_key_order = true;

	ZEND_PARSE_PARAMETERS_START(1, 3)
		Z_PARAM_ZVAL(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
		Z_PARAM_BOOL(preserve_key_order);
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	HashTable * results = zend_new_array(8);

	async_await_futures(futures,
		0,
		false,
		cancellation != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation) : NULL,
		0,
		0,
		results,
		NULL,
		// For awaitAll, it’s always necessary to fill the result with NULL,
		// because the order of keys matters.
		true,
		preserve_key_order,
		true
		);

	if (EG(exception)) {
		zend_array_release(results);
		RETURN_THROWS();
	}

	RETURN_ARR(results);
}

PHP_FUNCTION(Async_awaitAll)
{
	zval * futures;
	zend_object * cancellation = NULL;
	bool preserve_key_order = true;
	bool fill_null = false;

	ZEND_PARSE_PARAMETERS_START(1, 4)
		Z_PARAM_ZVAL(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
		Z_PARAM_BOOL(preserve_key_order);
		Z_PARAM_BOOL(fill_null);
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	HashTable * results = zend_new_array(8);
	HashTable * errors = zend_new_array(8);

	async_await_futures(futures,
		0,
		true,
		cancellation != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation) : NULL,
		0,
		0,
		results,
		errors,
		fill_null,
		preserve_key_order,
		true
		);

	if (EG(exception)) {
		zend_array_release(results);
		zend_array_release(errors);
		RETURN_THROWS();
	}

	HashTable * return_array = zend_new_array(2);

	zval val;
	ZVAL_ARR(&val, results);
	zend_hash_next_index_insert_new(return_array, &val);

	ZVAL_ARR(&val, errors);
	zend_hash_next_index_insert_new(return_array, &val);

	RETURN_ARR(return_array);
}

PHP_FUNCTION(Async_awaitAnyOfOrFail)
{
	zval * futures;
	zend_object * cancellation = NULL;
	zend_long count = 0;
	bool preserve_key_order = true;

	ZEND_PARSE_PARAMETERS_START(2, 4)
		Z_PARAM_LONG(count)
		Z_PARAM_ITERABLE(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
		Z_PARAM_BOOL(preserve_key_order);
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	HashTable * results = zend_new_array(8);

	if (count == 0) {
		RETURN_ARR(results);
	}

	async_await_futures(futures,
		(int)count,
		false,
		cancellation != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation) : NULL,
		0,
		0,
		results,
		NULL,
		false,
		preserve_key_order,
		false
		);

	if (EG(exception)) {
		zend_array_release(results);
		RETURN_THROWS();
	}

	RETURN_ARR(results);
}

PHP_FUNCTION(Async_awaitAnyOf)
{
	zval * futures;
	zend_object * cancellation = NULL;
	zend_long count = 0;
	bool preserve_key_order = true;
	bool fill_null = false;

	ZEND_PARSE_PARAMETERS_START(2, 5)
		Z_PARAM_LONG(count)
		Z_PARAM_ZVAL(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
		Z_PARAM_BOOL(preserve_key_order);
		Z_PARAM_BOOL(fill_null);
	ZEND_PARSE_PARAMETERS_END();

	HashTable * results = zend_new_array(8);
	HashTable * errors = zend_new_array(8);

	SCHEDULER_LAUNCH;

	async_await_futures(futures,
		(int)count,
		true,
		cancellation != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation) : NULL,
		0,
		0,
		results,
		errors,
		fill_null,
		preserve_key_order,
		true
		);

	if (EG(exception)) {
		zend_array_release(results);
		zend_array_release(errors);
		RETURN_THROWS();
	}

	HashTable * return_array = zend_new_array(2);

	zval val;
	ZVAL_ARR(&val, results);
	zend_hash_next_index_insert_new(return_array, &val);

	ZVAL_ARR(&val, errors);
	zend_hash_next_index_insert_new(return_array, &val);

	RETURN_ARR(return_array);
}

PHP_FUNCTION(Async_delay)
{
	zend_long ms = 0;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(ms)
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		return;
	}

	if (UNEXPECTED(ms == 0)) {
		ZEND_ASYNC_ENQUEUE_COROUTINE(ZEND_ASYNC_CURRENT_COROUTINE);
	} else {
		zend_async_waker_new_with_timeout(coroutine, ms, NULL);

		if (UNEXPECTED(EG(exception) != NULL)) {
			RETURN_THROWS();
		}
	}

	ZEND_ASYNC_SUSPEND();

	zend_async_waker_destroy(coroutine);
}

PHP_FUNCTION(Async_timeout)
{
	zend_long ms = 0;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(ms)
	ZEND_PARSE_PARAMETERS_END();

	if (ms <= 0) {
		zend_value_error("Timeout value must be greater than 0");
		RETURN_THROWS();
	}

	zend_object * zend_object = async_timeout_create(ms, false);

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	RETURN_OBJ(zend_object);
}

PHP_FUNCTION(Async_currentContext)
{
	ZEND_PARSE_PARAMETERS_NONE();

	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	zend_async_scope_t *scope = ZEND_ASYNC_CURRENT_SCOPE;

	if (scope == NULL) {
		// No current scope - return new independent context
		async_context_t *context = async_context_new();
		RETURN_OBJ(&context->std);
	}

	if (scope->context == NULL) {
		// Scope exists but no context - create new context and link it to scope
		async_context_t *context = async_context_new();
		context->scope = scope;
		scope->context = &context->base;
		RETURN_OBJ_COPY(&context->std);
	}

	// Return the existing context from scope
	async_context_t *context = (async_context_t *) scope->context;
	RETURN_OBJ_COPY(&context->std);
}

PHP_FUNCTION(Async_coroutineContext)
{
	ZEND_PARSE_PARAMETERS_NONE();

	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_CURRENT_COROUTINE;

	if (coroutine == NULL) {
		// No current coroutine - return new context
		async_context_t *context = async_context_new();
		RETURN_OBJ(&context->std);
	}

	if (coroutine->coroutine.context == NULL) {
		// Coroutine exists but no context - create and assign to coroutine
		async_context_t *context = async_context_new();
		coroutine->coroutine.context = &context->base;
		RETURN_OBJ_COPY(&context->std);
	}

	// Return the existing context from coroutine
	async_context_t *context = (async_context_t *) coroutine->coroutine.context;
	RETURN_OBJ_COPY(&context->std);
}

PHP_FUNCTION(Async_currentCoroutine)
{
	ZEND_PARSE_PARAMETERS_NONE();

	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		zend_async_throw(
			ZEND_ASYNC_EXCEPTION_DEFAULT,
			"The current coroutine is not defined"
		);

		RETURN_THROWS();
	}

	RETURN_OBJ_COPY(&coroutine->std);
}

PHP_FUNCTION(Async_rootContext)
{
	ZEND_PARSE_PARAMETERS_NONE();

	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	/* TODO: Implement root context access */
	/* For now, return a new context */
	async_context_t *context = async_context_new();
	RETURN_OBJ(&context->std);
}

PHP_FUNCTION(Async_getCoroutines)
{
	ZEND_PARSE_PARAMETERS_NONE();

	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	array_init(return_value);

	async_coroutine_t *coroutine;
	ZEND_HASH_FOREACH_PTR(&ASYNC_G(coroutines), coroutine) {
		add_next_index_object(return_value, &coroutine->std);
		GC_ADDREF(&coroutine->std);
	} ZEND_HASH_FOREACH_END();
}

PHP_FUNCTION(Async_gracefulShutdown)
{
	zend_object *cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_cancellation_exception)
	ZEND_PARSE_PARAMETERS_END();

	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	/* TODO: Implement graceful shutdown */
}

/*
PHP_FUNCTION(Async_exec)
{

}
*/

PHP_METHOD(Async_Timeout, __construct)
{
	async_throw_error("Timeout cannot be constructed directly");
}

///////////////////////////////////////////////////////////////
/// Register Async Module
///////////////////////////////////////////////////////////////

ZEND_DECLARE_MODULE_GLOBALS(async)

void async_register_awaitable_ce(void)
{
	async_ce_awaitable = register_class_Async_Awaitable();
}

static zend_object_handlers async_timeout_handlers;

static void async_timeout_destroy_object(zend_object *object)
{
	async_timeout_object_t * timeout = ASYNC_TIMEOUT_FROM_OBJ(object);

	if (timeout->event != NULL) {
		zend_async_timer_event_t * timer_event = timeout->event;
		async_timeout_ext_t *timeout_ext = ASYNC_TIMEOUT_FROM_EVENT(&timer_event->base);
		timeout_ext->std = NULL;
		timeout->event = NULL;

		timer_event->base.dispose(&timer_event->base);
	}
}

static void async_timeout_event_dispose(zend_async_event_t *event)
{
	async_timeout_ext_t *timeout = ASYNC_TIMEOUT_FROM_EVENT(event);

	if (timeout->std) {
		zend_object *object = timeout->std;
		async_timeout_object_t *timeout_object = ASYNC_TIMEOUT_FROM_OBJ(object);
		ZEND_ASSERT((timeout_object->event == NULL || timeout_object->event == (zend_async_timer_event_t *) event)
			&& "Event object mismatch");
		timeout_object->event = NULL;
		timeout->std = NULL;
		OBJ_RELEASE(object);
	}

	if (timeout->prev_dispose) {
		timeout->prev_dispose(event);
	}
}

static void timeout_before_notify_handler(zend_async_event_t *event, void *result, zend_object *exception)
{
	if (UNEXPECTED(exception != NULL)) {
		ZEND_ASYNC_CALLBACKS_NOTIFY_FROM_HANDLER(event, result, exception);
		return;
	}

	// Here we override the exception value with a timeout exception.
	zend_object * timeout_exception = async_new_exception(
		async_ce_timeout_exception,
		"Timeout occurred after %lu milliseconds",
		((zend_async_timer_event_t * )event)->timeout
	);

	ZEND_ASYNC_CALLBACKS_NOTIFY_FROM_HANDLER(event, result, timeout_exception);
	OBJ_RELEASE(timeout_exception);
}

static zend_object *async_timeout_create(const zend_ulong ms, const bool is_periodic)
{
	async_timeout_object_t *object = zend_object_alloc(sizeof(async_timeout_object_t), async_ce_timeout);

	zend_object_std_init(&object->std, async_ce_timeout);
	object_properties_init(&object->std, async_ce_timeout);

	if (UNEXPECTED(EG(exception) != NULL)) {
		efree(object);
		return NULL;
	}

	object->std.handlers = &async_timeout_handlers;

	zend_async_event_t *event = (zend_async_event_t *) ZEND_ASYNC_NEW_TIMER_EVENT_EX(
		ms, is_periodic, sizeof(async_timeout_ext_t)
	);

	if (UNEXPECTED(event == NULL)) {
		efree(object);
		return NULL;
	}

	ZEND_ASYNC_EVENT_REF_SET(object, XtOffsetOf(async_timeout_object_t, std), (zend_async_timer_event_t *)event);
	// A special flag is set to indicate that the event will contain a reference to a Zend object.
	ZEND_ASYNC_EVENT_WITH_OBJECT_REF(event);

	// Cast the event to the extended type.
	async_timeout_ext_t *timeout = ASYNC_TIMEOUT_FROM_EVENT(event);
	// Store the event in the object.
	timeout->std = &object->std;
	// Define own dispose handler for the event.
	timeout->prev_dispose = event->dispose;

	event->notify_handler = timeout_before_notify_handler;
	event->dispose = async_timeout_event_dispose;

	return &object->std;
}

void async_register_timeout_ce(void)
{
	async_ce_timeout = register_class_Async_Timeout(async_ce_awaitable);

	async_ce_timeout->create_object = NULL;

	async_timeout_handlers = std_object_handlers;

	async_timeout_handlers.offset   = XtOffsetOf(async_timeout_object_t, std);
	async_timeout_handlers.dtor_obj = async_timeout_destroy_object;
}

static PHP_GINIT_FUNCTION(async)
{
#if defined(COMPILE_DL_ASYNC) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif

	async_globals->reactor_started = false;

#ifdef PHP_ASYNC_LIBUV
	async_globals->signal_handlers = NULL;
	async_globals->signal_events = NULL;
	async_globals->process_events = NULL;

#ifdef PHP_WIN32
	async_globals->watcherThread = NULL;
	async_globals->ioCompletionPort = NULL;
	async_globals->countWaitingDescriptors = 0;
	async_globals->isRunning = false;
	async_globals->uvloop_wakeup = NULL;
	async_globals->pid_queue = NULL;
#endif
#endif
}

/* {{{ PHP_GSHUTDOWN_FUNCTION */
static PHP_GSHUTDOWN_FUNCTION(async)
{
#ifdef PHP_WIN32
#endif
}
/* }}} */

/* Module registration */

ZEND_MINIT_FUNCTION(async)
{
	async_register_awaitable_ce();
	async_register_timeout_ce();
	async_register_scope_ce();
	async_register_coroutine_ce();
	async_register_context_ce();
	async_register_exceptions_ce();
	//async_register_notifier_ce();
	//async_register_handlers_ce();
	//async_register_channel_ce();
	//async_register_iterator_ce();
	//async_register_future_ce();

	async_scheduler_startup();

	async_api_register();

#ifdef PHP_ASYNC_LIBUV
	async_libuv_reactor_register();
#endif

	return SUCCESS;
}

ZEND_MSHUTDOWN_FUNCTION(async)
{
	//async_scheduler_shutdown();

#ifdef PHP_ASYNC_LIBUV
	//async_libuv_shutdown();
#endif

	return SUCCESS;
}

PHP_MINFO_FUNCTION(async) {
	php_info_print_table_start();
	php_info_print_table_header(2, "Module", PHP_ASYNC_NAME);
	php_info_print_table_row(2, "Version", PHP_ASYNC_VERSION);
	php_info_print_table_row(2, "Support", "Enabled");
#ifdef PHP_ASYNC_LIBUV
	php_info_print_table_row(2, "LibUv Reactor", "Enabled");
#else
	php_info_print_table_row(2, "LibUv Reactor", "Disabled");
#endif
	php_info_print_table_end();
}

PHP_RINIT_FUNCTION(async) /* {{{ */
{
	//async_host_name_list_ctor();
	ZEND_ASYNC_INITIALIZE;
	circular_buffer_ctor(&ASYNC_G(microtasks), 64, sizeof(zend_async_microtask_t *), &zend_std_allocator);
	circular_buffer_ctor(&ASYNC_G(coroutine_queue), 128, sizeof(zend_coroutine_t *), &zend_std_allocator);
	zend_hash_init(&ASYNC_G(coroutines), 128, NULL, NULL, 0);

	ASYNC_G(reactor_started) = false;

	return SUCCESS;
} /* }}} */

zend_module_entry async_module_entry = {
	STANDARD_MODULE_HEADER,
	PHP_ASYNC_NAME,
	ext_functions,
	PHP_MINIT(async),
	PHP_MSHUTDOWN(async),
	PHP_RINIT(async),
	NULL,
	PHP_MINFO(async),
	PHP_ASYNC_VERSION,
	PHP_MODULE_GLOBALS(async),
	PHP_GINIT(async),
	PHP_GSHUTDOWN(async),
	NULL,
	STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_ASYNC
ZEND_GET_MODULE(async)
#endif
