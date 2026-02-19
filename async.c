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
#include "future.h"
#include "channel.h"
#include "pool.h"
#include "task_group.h"
#include "iterator.h"
#include "async_API.h"
#include "zend_enum.h"
#include "async_arginfo.h"
#include "zend_interfaces.h"
#include "libuv_reactor.h"

zend_class_entry *async_ce_awaitable = NULL;
zend_class_entry *async_ce_completable = NULL;
zend_class_entry *async_ce_timeout = NULL;
zend_class_entry *async_ce_circuit_breaker_state = NULL;
zend_class_entry *async_ce_circuit_breaker = NULL;
zend_class_entry *async_ce_filesystem_event = NULL;
zend_class_entry *async_ce_circuit_breaker_strategy = NULL;

///////////////////////////////////////////////////////////////
/// Module functions
///////////////////////////////////////////////////////////////

static zend_object *async_timeout_create(zend_ulong ms, bool is_periodic);

#define THROW_IF_SCHEDULER_CONTEXT \
	if (UNEXPECTED(ZEND_ASYNC_IS_SCHEDULER_CONTEXT)) { \
		async_throw_error("The operation cannot be executed in the scheduler context"); \
		RETURN_THROWS(); \
	}

#define THROW_IF_ASYNC_OFF \
	if (UNEXPECTED(ZEND_ASYNC_OFF)) { \
		async_throw_error("The operation cannot be executed while async is off"); \
		RETURN_THROWS(); \
	}

#define SCHEDULER_LAUNCH \
	if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL)) { \
		if (!async_scheduler_launch()) { \
			RETURN_THROWS(); \
		} \
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

	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_SPAWN();

	if (UNEXPECTED(coroutine == NULL)) {
		return;
	}

	ZEND_ASYNC_FCALL_DEFINE(fcall, fci, fcc, args, args_count, named_args);

	coroutine->coroutine.fcall = fcall;

	RETURN_OBJ_COPY(&coroutine->std);
}

PHP_FUNCTION(Async_spawn_with)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	zval *args = NULL;
	int args_count = 0;
	HashTable *named_args = NULL;

	zend_async_scope_t *scope = NULL;
	zend_object *scope_provider = NULL;
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(2, -1)
	Z_PARAM_OBJ_OF_CLASS(scope_provider, async_ce_scope_provider)
	Z_PARAM_FUNC(fci, fcc);
	Z_PARAM_VARIADIC_WITH_NAMED(args, args_count, named_args);
	ZEND_PARSE_PARAMETERS_END();

	// If scope_provider is an instance of async_ce_scope
	if (instanceof_function(scope_provider->ce, async_ce_scope)) {
		scope = &((async_scope_object_t *) scope_provider)->scope->scope;
	}

	async_coroutine_t *coroutine =
			(async_coroutine_t *) (scope_provider ? ZEND_ASYNC_SPAWN_WITH_PROVIDER(scope_provider)
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
	zend_async_waker_clean(ZEND_ASYNC_CURRENT_COROUTINE);
}

PHP_FUNCTION(Async_protect)
{
	zend_object *closure;

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_OBJ_OF_CLASS(closure, zend_ce_closure)
	ZEND_PARSE_PARAMETERS_END();

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	bool do_bailout = false;

	zend_try
	{
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
	}
	zend_catch
	{
		do_bailout = true;
	}
	zend_end_try();

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
	zend_object *awaitable = NULL;
	zend_object *cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
	Z_PARAM_OBJ_OF_CLASS(awaitable, async_ce_completable);
	Z_PARAM_OPTIONAL
	Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_completable);
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		RETURN_NULL();
	}

	zend_async_event_t *awaitable_event = ZEND_ASYNC_OBJECT_TO_EVENT(awaitable);
	zend_async_event_t *cancellation_event = cancellation != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation) : NULL;

	// Mark that result will be used and exception will be caught
	ZEND_ASYNC_EVENT_SET_RESULT_USED(awaitable_event);
	ZEND_ASYNC_EVENT_SET_EXC_CAUGHT(awaitable_event);

	// If the awaitable is the same as the cancellation event, we can skip the cancellation check.
	if (awaitable_event == cancellation_event) {
		cancellation_event = NULL;
	}

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
		if (ZEND_ASYNC_EVENT_EXTRACT_RESULT(cancellation_event, return_value)) {
			return;
		}

		async_throw_cancellation("Operation has been cancelled");
		RETURN_THROWS();
	}

	zend_async_waker_new(coroutine);

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	zend_async_resume_when(coroutine, awaitable_event, false, zend_async_waker_callback_resolve, NULL);

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	if (cancellation_event != NULL) {
		zend_async_resume_when(coroutine, cancellation_event, false, zend_async_waker_callback_cancel, NULL);

		if (UNEXPECTED(EG(exception) != NULL)) {
			RETURN_THROWS();
		}
	}

	if (!ZEND_ASYNC_SUSPEND()) {
		zend_async_waker_clean(coroutine);
		RETURN_THROWS();
	}

	ZEND_ASSERT(coroutine->waker != NULL && "coroutine->waker must not be NULL");

	if (Z_TYPE(coroutine->waker->result) == IS_UNDEF) {
		ZVAL_NULL(return_value);
	} else {
		ZVAL_COPY(return_value, &coroutine->waker->result);
	}

	zend_async_waker_clean(coroutine);
}

PHP_FUNCTION(Async_await_any_or_fail)
{
	zval *futures;
	zend_object *cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
	Z_PARAM_ZVAL(futures);
	Z_PARAM_OPTIONAL
	Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	HashTable *results = zend_new_array(8);

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
						false);

	if (EG(exception)) {
		zend_array_release(results);
		RETURN_THROWS();
	}

	if (zend_hash_num_elements(results) == 0) {
		zend_array_release(results);
		RETURN_NULL();
	}

	zval result;
	ZEND_HASH_FOREACH_VAL(results, zval * item)
	{
		ZVAL_COPY(&result, item);
		break;
	}
	ZEND_HASH_FOREACH_END();

	zend_array_release(results);

	RETURN_ZVAL(&result, 0, 0);
}

PHP_FUNCTION(Async_await_first_success)
{
	zval *futures;
	zend_object *cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
	Z_PARAM_ZVAL(futures);
	Z_PARAM_OPTIONAL
	Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	HashTable *results = zend_new_array(8);
	HashTable *errors = zend_new_array(8);

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
						true);

	if (EG(exception)) {
		zend_array_release(results);
		zend_array_release(errors);
		RETURN_THROWS();
	}

	HashTable *return_array = zend_new_array(2);

	zval val;
	ZVAL_NULL(&val);

	ZEND_HASH_FOREACH_VAL(results, zval * item)
	{
		ZVAL_COPY(&val, item);
		break;
	}
	ZEND_HASH_FOREACH_END();

	zend_hash_next_index_insert_new(return_array, &val);

	ZVAL_ARR(&val, errors);
	zend_hash_next_index_insert_new(return_array, &val);

	zend_array_release(results);

	RETURN_ARR(return_array);
}

PHP_FUNCTION(Async_await_all_or_fail)
{
	zval *futures;
	zend_object *cancellation = NULL;
	bool preserve_key_order = true;

	ZEND_PARSE_PARAMETERS_START(1, 3)
	Z_PARAM_ZVAL(futures);
	Z_PARAM_OPTIONAL
	Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	Z_PARAM_BOOL(preserve_key_order);
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	HashTable *results = zend_new_array(8);

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
						true);

	if (EG(exception)) {
		zend_array_release(results);
		RETURN_THROWS();
	}

	RETURN_ARR(results);
}

PHP_FUNCTION(Async_await_all)
{
	zval *futures;
	zend_object *cancellation = NULL;
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

	HashTable *results = zend_new_array(8);
	HashTable *errors = zend_new_array(8);

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
						true);

	if (EG(exception)) {
		zend_array_release(results);
		zend_array_release(errors);
		RETURN_THROWS();
	}

	HashTable *return_array = zend_new_array(2);

	zval val;
	ZVAL_ARR(&val, results);
	zend_hash_next_index_insert_new(return_array, &val);

	ZVAL_ARR(&val, errors);
	zend_hash_next_index_insert_new(return_array, &val);

	RETURN_ARR(return_array);
}

PHP_FUNCTION(Async_await_any_of_or_fail)
{
	zval *futures;
	zend_object *cancellation = NULL;
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

	HashTable *results = zend_new_array(8);

	if (count == 0) {
		RETURN_ARR(results);
	}

	async_await_futures(futures,
						(int) count,
						false,
						cancellation != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation) : NULL,
						0,
						0,
						results,
						NULL,
						false,
						preserve_key_order,
						false);

	if (EG(exception)) {
		zend_array_release(results);
		RETURN_THROWS();
	}

	RETURN_ARR(results);
}

PHP_FUNCTION(Async_await_any_of)
{
	zval *futures;
	zend_object *cancellation = NULL;
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

	HashTable *results = zend_new_array(8);
	HashTable *errors = zend_new_array(8);

	SCHEDULER_LAUNCH;

	async_await_futures(futures,
						(int) count,
						true,
						cancellation != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation) : NULL,
						0,
						0,
						results,
						errors,
						fill_null,
						preserve_key_order,
						true);

	if (EG(exception)) {
		zend_array_release(results);
		zend_array_release(errors);
		RETURN_THROWS();
	}

	HashTable *return_array = zend_new_array(2);

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

	zend_async_waker_clean(coroutine);
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

	zend_object *zend_object = async_timeout_create(ms, false);

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	RETURN_OBJ(zend_object);
}

PHP_FUNCTION(Async_current_context)
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

PHP_FUNCTION(Async_coroutine_context)
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

PHP_FUNCTION(Async_current_coroutine)
{
	ZEND_PARSE_PARAMETERS_NONE();

	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		zend_async_throw(ZEND_ASYNC_EXCEPTION_DEFAULT, "The current coroutine is not defined");

		RETURN_THROWS();
	}

	RETURN_OBJ_COPY(&coroutine->std);
}

PHP_FUNCTION(Async_root_context)
{
	ZEND_PARSE_PARAMETERS_NONE();

	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	if (ASYNC_G(root_context) == NULL) {
		ASYNC_G(root_context) = (zend_async_context_t *) async_context_new();
	}

	async_context_t *context = (async_context_t *) ASYNC_G(root_context);
	RETURN_OBJ_COPY(&context->std);
}

PHP_FUNCTION(Async_get_coroutines)
{
	ZEND_PARSE_PARAMETERS_NONE();

	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	array_init(return_value);

	async_coroutine_t *coroutine;
	ZEND_HASH_FOREACH_PTR(&ASYNC_G(coroutines), coroutine)
	{
		add_next_index_object(return_value, &coroutine->std);
		GC_ADDREF(&coroutine->std);
	}
	ZEND_HASH_FOREACH_END();
}

PHP_FUNCTION(Async_graceful_shutdown)
{
	zend_object *cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
	Z_PARAM_OPTIONAL
	Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_cancellation_exception)
	ZEND_PARSE_PARAMETERS_END();

	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	ZEND_ASYNC_SHUTDOWN();
}

PHP_FUNCTION(Async_iterate)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	zval *iterable;
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;
	zend_long concurrency = 0;
	bool cancel_pending = true;

	ZEND_PARSE_PARAMETERS_START(2, 4)
		Z_PARAM_ZVAL(iterable)
		Z_PARAM_FUNC(fci, fcc)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(concurrency)
		Z_PARAM_BOOL(cancel_pending)
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	zend_object_iterator *zend_iterator = NULL;
	zval *array = NULL;

	if (Z_TYPE_P(iterable) == IS_ARRAY) {
		array = iterable;
	} else if (Z_TYPE_P(iterable) == IS_OBJECT && Z_OBJCE_P(iterable)->get_iterator) {
		zend_iterator = Z_OBJCE_P(iterable)->get_iterator(Z_OBJCE_P(iterable), iterable, 0);

		if (UNEXPECTED(EG(exception) == NULL && zend_iterator == NULL)) {
			async_throw_error("Failed to create iterator");
		}
	} else {
		zend_argument_type_error(1, "must be of type array|Traversable, %s given", zend_zval_type_name(iterable));
		RETURN_THROWS();
	}

	if (UNEXPECTED(EG(exception))) {
		RETURN_THROWS();
	}

	// Create a child scope for the iterator to isolate it from the current scope.
	// This prevents exceptions in iterator coroutines from cancelling unrelated coroutines.
	zend_async_scope_t *iterator_scope = ZEND_ASYNC_NEW_SCOPE(ZEND_ASYNC_CURRENT_SCOPE);

	if (UNEXPECTED(iterator_scope == NULL)) {
		// free zend_iterator if it was created before returning
		if (zend_iterator != NULL) {
			zend_iterator_dtor(zend_iterator);
		}

		RETURN_THROWS();
	}

	ZEND_ASYNC_SCOPE_CLR_DISPOSE_SAFELY(iterator_scope);

	/* Create fcall with 2 parameter slots for (value, key) */
	zend_fcall_t *fcall = ecalloc(1, sizeof(zend_fcall_t));
	fcall->fci = fci;
	fcall->fci_cache = fcc;
	fcall->fci.param_count = 2;
	fcall->fci.params = safe_emalloc(2, sizeof(zval), 0);
	ZVAL_UNDEF(&fcall->fci.params[0]);
	ZVAL_UNDEF(&fcall->fci.params[1]);
	Z_TRY_ADDREF(fcall->fci.function_name);

	zend_async_iterator_t *iterator = ZEND_ASYNC_NEW_ITERATOR_SCOPE(
		array, zend_iterator, fcall, NULL, iterator_scope, (unsigned int) concurrency, ZEND_COROUTINE_NORMAL);

	if (UNEXPECTED(iterator == NULL || EG(exception))) {
		iterator_scope->try_to_dispose(iterator_scope);
		efree(fcall->fci.params);
		efree(fcall);

		if (zend_iterator != NULL) {
			zend_iterator_dtor(zend_iterator);
		}

		RETURN_THROWS();
	}

	async_iterator_t *async_iter = (async_iterator_t *) iterator;

	if (Z_TYPE(async_iter->array) != IS_UNDEF) {
		SEPARATE_ARRAY(&async_iter->array);
	}

	// Run the iterator in a separate coroutine within the child scope
	async_iterator_run_in_coroutine(async_iter, ZEND_COROUTINE_NORMAL, false);

	if (UNEXPECTED(EG(exception))) {
		iterator_scope->try_to_dispose(iterator_scope);
		efree(fcall->fci.params);
		efree(fcall);

		if (zend_iterator != NULL) {
			zend_iterator_dtor(zend_iterator);
		}

		RETURN_THROWS();
	}

	// Increment ref count of the scope event to ensure it is not freed while we are waiting for it.
	iterator_scope->event.ref_count++;
	ZEND_ASYNC_MICROTASK_ADD_REF(&iterator->microtask);

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	zend_object *exception = NULL;

	// Wait for the iterator completion event
	iterator->completion_event = async_iterator_completion_event_create();
	zend_async_waker_new(coroutine);
	zend_async_resume_when(coroutine,
		iterator->completion_event, false, zend_async_waker_callback_resolve, NULL);
	ZEND_ASYNC_SUSPEND();

	if (UNEXPECTED(EG(exception))) {
		exception = EG(exception);
		GC_ADDREF(exception);
		zend_clear_exception();
	}

	// Handle pending coroutines spawned inside the iterator scope.
	if (false == ZEND_ASYNC_SCOPE_IS_COMPLETED(iterator_scope)) {

		if (cancel_pending) {
			// Cancel all pending coroutines in the iterator scope
			ZEND_ASYNC_SCOPE_CANCEL(
				iterator_scope,
				async_new_exception(async_ce_cancellation_exception,
					"Cancellation of pending coroutines after iterator completion"),
				true,
				ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(iterator_scope));
		}

		if (UNEXPECTED(EG(exception))) {
			if (exception != NULL) {
				zend_exception_set_previous(EG(exception), exception);
			}

			exception = EG(exception);
			GC_ADDREF(exception);
			zend_clear_exception();
		} else {
			// Wait for the child scope to fully complete
			zend_async_waker_new(coroutine);
			zend_async_resume_when(coroutine, &iterator_scope->event, false, zend_async_waker_callback_resolve, NULL);

			if (EXPECTED(EG(exception) == NULL)) {
				ZEND_ASYNC_SUSPEND();
			}
		}

		if (UNEXPECTED(EG(exception))) {
			if (exception != NULL) {
				zend_exception_set_previous(EG(exception), exception);
			}

			exception = EG(exception);
			GC_ADDREF(exception);
			zend_clear_exception();
		}
	}

	// Dispose the child scope
	iterator_scope->try_to_dispose(iterator_scope);

	if (async_iter->zend_iterator != NULL) {
		zend_iterator_dtor(async_iter->zend_iterator);
		async_iter->zend_iterator = NULL;
	}

	if (async_iter->fcall != NULL) {
		zend_fcall_release(async_iter->fcall);
		async_iter->fcall = NULL;
	}

	/* Merge exceptions: iterator exception takes priority */
	if (async_iter->exception != NULL) {

		// Proper handling of iterator exceptions:
		// 1. We can receive the same exception that the iterator threw.
		// 2. If not, then we correctly combine the different exceptions.
		if (exception == async_iter->exception) {
			GC_DELREF(exception);
			async_iter->exception = NULL;
		} else if (exception != NULL) {
			zend_exception_set_previous(async_iter->exception, exception);
			exception = async_iter->exception;
			async_iter->exception = NULL;
		} else {
			exception = async_iter->exception;
			async_iter->exception = NULL;
		}
	}

	iterator->microtask.dtor(&iterator->microtask);

	if (UNEXPECTED(exception != NULL)) {
		zend_throw_exception_internal(exception);
		RETURN_THROWS();
	}
}

/*
PHP_FUNCTION(Async_exec)
{

}
*/

///////////////////////////////////////////////////////////////
/// watch_filesystem
///////////////////////////////////////////////////////////////

typedef struct {
	zend_async_event_callback_t base;
	zend_future_t *future;
	zend_async_filesystem_event_t *fs_event;
	zend_async_event_callback_t *cancel_cb;
} watch_fs_callback_t;

typedef struct {
	zend_async_event_callback_t base;
	zend_future_t *future;
	zend_async_filesystem_event_t *fs_event;
	watch_fs_callback_t *fs_cb;
	zend_object *cancellation;
} watch_fs_cancel_callback_t;

static void watch_fs_callback_dispose(zend_async_event_callback_t *callback, zend_async_event_t *event)
{
	efree(callback);
}

static void watch_fs_cancel_callback_dispose(zend_async_event_callback_t *callback, zend_async_event_t *event)
{
	watch_fs_cancel_callback_t *cb = (watch_fs_cancel_callback_t *)callback;
	zend_object *cancellation = cb->cancellation;
	cb->cancellation = NULL;
	if (cancellation) {
		OBJ_RELEASE(cancellation);
	}
	efree(cb);
}

static void watch_fs_cleanup(zend_async_filesystem_event_t *fs_event)
{
	if (EXPECTED(!ZEND_ASYNC_EVENT_IS_CLOSED(&fs_event->base))) {
		fs_event->base.stop(&fs_event->base);
	}
	fs_event->base.dispose(&fs_event->base);
}

static void watch_fs_on_cancel(
	zend_async_event_t *event, zend_async_event_callback_t *callback, void *result, zend_object *exception)
{
	const watch_fs_cancel_callback_t *cb = (watch_fs_cancel_callback_t *)callback;
	zend_future_t *future = cb->future;
	zend_async_filesystem_event_t *fs_event = cb->fs_event;

	/* Detach cross-reference */
	if (cb->fs_cb != NULL) {
		cb->fs_cb->cancel_cb = NULL;
	}

	if (ZEND_ASYNC_EVENT_IS_CLOSED(&future->event)) {
		return;
	}

	if (exception != NULL) {
		ZEND_FUTURE_REJECT(future, exception);
	} else {
		zend_object *cancel_ex = async_new_exception(
			async_ce_cancellation_exception, "Filesystem watch cancelled");
		ZEND_FUTURE_REJECT(future, cancel_ex);
		zend_object_release(cancel_ex);
	}

	watch_fs_cleanup(fs_event);
}

static void watch_fs_on_event(
	zend_async_event_t *event, zend_async_event_callback_t *callback, void *result, zend_object *exception)
{
	const watch_fs_callback_t *cb = (watch_fs_callback_t *)callback;
	zend_future_t *future = cb->future;
	zend_async_filesystem_event_t *fs_event = cb->fs_event;

	/* Remove cancellation callback if registered */
	if (cb->cancel_cb != NULL) {
		watch_fs_cancel_callback_t *cancel = (watch_fs_cancel_callback_t *)cb->cancel_cb;
		cancel->fs_cb = NULL;
	}

	if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(&future->event))) {
		goto cleanup;
	}

	if (UNEXPECTED(exception != NULL)) {
		ZEND_FUTURE_REJECT(future, exception);
		goto cleanup;
	}

	/* Create FileSystemEvent object and set readonly properties via property slots */
	zval event_obj;
	object_init_ex(&event_obj, async_ce_filesystem_event);
	zend_object *obj = Z_OBJ(event_obj);

	/* Property slot 0: path */
	ZVAL_STR_COPY(OBJ_PROP_NUM(obj, 0), fs_event->path);

	/* Property slot 1: filename */
	if (fs_event->triggered_filename != NULL) {
		ZVAL_STR_COPY(OBJ_PROP_NUM(obj, 1), fs_event->triggered_filename);
	} else {
		ZVAL_NULL(OBJ_PROP_NUM(obj, 1));
	}

	/* Property slot 2: renamed */
	ZVAL_BOOL(OBJ_PROP_NUM(obj, 2), (fs_event->triggered_events & UV_RENAME) != 0);

	/* Property slot 3: changed */
	ZVAL_BOOL(OBJ_PROP_NUM(obj, 3), (fs_event->triggered_events & UV_CHANGE) != 0);

	ZEND_FUTURE_COMPLETE(future, &event_obj);
	zval_ptr_dtor(&event_obj);

cleanup:
	watch_fs_cleanup(fs_event);
}

PHP_FUNCTION(Async_watch_filesystem)
{
	zend_string *path = NULL;
	bool recursive = false;
	zend_object *cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 3)
		Z_PARAM_STR(path)
		Z_PARAM_OPTIONAL
		Z_PARAM_BOOL(recursive)
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_completable)
	ZEND_PARSE_PARAMETERS_END();

	SCHEDULER_LAUNCH;

	const unsigned int flags = recursive ? UV_FS_EVENT_RECURSIVE : 0;

	zend_async_filesystem_event_t *fs_event = ZEND_ASYNC_NEW_FILESYSTEM_EVENT(path, flags);

	if (UNEXPECTED(fs_event == NULL)) {
		RETURN_THROWS();
	}

	/* Create the future */
	zend_future_t *future = ZEND_ASYNC_NEW_FUTURE(false);

	if (UNEXPECTED(future == NULL)) {
		fs_event->base.dispose(&fs_event->base);
		RETURN_THROWS();
	}

	/* Create and register fs event callback */
	watch_fs_callback_t *cb = ecalloc(1, sizeof(watch_fs_callback_t));
	cb->base.ref_count = 0;
	cb->base.callback = watch_fs_on_event;
	cb->base.dispose = watch_fs_callback_dispose;
	cb->future = future;
	cb->fs_event = fs_event;
	cb->cancel_cb = NULL;

	fs_event->base.add_callback(&fs_event->base, &cb->base);

	/* Register cancellation callback */
	if (cancellation != NULL) {
		zend_async_event_t *cancel_event = ZEND_ASYNC_OBJECT_TO_EVENT(cancellation);

		if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(cancel_event))) {
			/* Already cancelled — reject immediately */
			zend_object *cancel_ex = async_new_exception(
				async_ce_cancellation_exception, "Filesystem watch cancelled");
			ZEND_FUTURE_REJECT(future, cancel_ex);
			zend_object_release(cancel_ex);
			fs_event->base.dispose(&fs_event->base);
			RETURN_OBJ(ZEND_ASYNC_NEW_FUTURE_OBJ(future));
		}

		watch_fs_cancel_callback_t *cancel_cb = ecalloc(1, sizeof(watch_fs_cancel_callback_t));
		cancel_cb->base.ref_count = 0;
		cancel_cb->base.callback = watch_fs_on_cancel;
		cancel_cb->base.dispose = watch_fs_cancel_callback_dispose;
		cancel_cb->future = future;
		cancel_cb->fs_event = fs_event;
		cancel_cb->fs_cb = cb;
		cancel_cb->cancellation = cancellation;
		GC_ADDREF(cancellation);

		cb->cancel_cb = &cancel_cb->base;

		cancel_event->add_callback(cancel_event, &cancel_cb->base);
	}

	/* Start watching */
	if (UNEXPECTED(!fs_event->base.start(&fs_event->base))) {
		fs_event->base.dispose(&fs_event->base);
		ZEND_FUTURE_SET_USED(future);
		future->event.dispose(&future->event);
		RETURN_THROWS();
	}

	if (cancellation != NULL) {
		zend_async_event_t *cancel_event = ZEND_ASYNC_OBJECT_TO_EVENT(cancellation);

		if (UNEXPECTED(false == cancel_event->start(cancel_event))) {
			fs_event->base.dispose(&fs_event->base);
			ZEND_FUTURE_SET_USED(future);
			future->event.dispose(&future->event);
			RETURN_THROWS();
		}
	}

	RETURN_OBJ(ZEND_ASYNC_NEW_FUTURE_OBJ(future));
}

PHP_METHOD(Async_Timeout, __construct)
{
	async_throw_error("Timeout cannot be constructed directly");
}

PHP_METHOD(Async_Timeout, cancel)
{
	zend_object *cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
	Z_PARAM_OPTIONAL
	Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_cancellation_exception);
	ZEND_PARSE_PARAMETERS_END();

	async_timeout_object_t *timeout = ASYNC_TIMEOUT_FROM_OBJ(Z_OBJ_P(ZEND_THIS));

	if (timeout->event != NULL) {
		zend_async_timer_event_t *timer_event = timeout->event;
		timeout->event = NULL;
		timer_event->base.dispose(&timer_event->base);
	}
}

PHP_METHOD(Async_Timeout, isCompleted)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_timeout_object_t *timeout = ASYNC_TIMEOUT_FROM_OBJ(Z_OBJ_P(ZEND_THIS));

	RETURN_BOOL(timeout->event == NULL || ZEND_ASYNC_EVENT_IS_CLOSED(&timeout->event->base));
}

PHP_METHOD(Async_Timeout, isCancelled)
{
	ZEND_PARSE_PARAMETERS_NONE();

	/* Timeout does not track cancellation separately; it is either active or completed */
	async_timeout_object_t *timeout = ASYNC_TIMEOUT_FROM_OBJ(Z_OBJ_P(ZEND_THIS));

	RETURN_BOOL(timeout->event == NULL);
}

///////////////////////////////////////////////////////////////
/// Register Async Module
///////////////////////////////////////////////////////////////

ZEND_DECLARE_MODULE_GLOBALS(async)

void async_register_awaitable_ce(void)
{
	async_ce_awaitable = register_class_Async_Awaitable();
	async_ce_completable = register_class_Async_Completable(async_ce_awaitable);
}

void async_register_filesystem_event_ce(void)
{
	async_ce_filesystem_event = register_class_Async_FileSystemEvent();
}

void async_register_circuit_breaker_ce(void)
{
	async_ce_circuit_breaker_state = register_class_Async_CircuitBreakerState();
	async_ce_circuit_breaker = register_class_Async_CircuitBreaker();
	async_ce_circuit_breaker_strategy = register_class_Async_CircuitBreakerStrategy();
}

static zend_object_handlers async_timeout_handlers;

static void async_timeout_destroy_object(zend_object *object)
{
	async_timeout_object_t *timeout = ASYNC_TIMEOUT_FROM_OBJ(object);

	if (timeout->event != NULL) {
		zend_async_timer_event_t *timer_event = timeout->event;
		async_timeout_ext_t *timeout_ext = ASYNC_TIMEOUT_FROM_EVENT(&timer_event->base);
		timeout_ext->std = NULL;
		timeout->event = NULL;

		timer_event->base.dispose(&timer_event->base);
	}
}

static bool async_timeout_event_dispose(zend_async_event_t *event)
{
	async_timeout_ext_t *timeout = ASYNC_TIMEOUT_FROM_EVENT(event);

	if (timeout->std) {
		zend_object *object = timeout->std;
		async_timeout_object_t *timeout_object = ASYNC_TIMEOUT_FROM_OBJ(object);
		ZEND_ASSERT((timeout_object->event == NULL || timeout_object->event == (zend_async_timer_event_t *) event) &&
					"Event object mismatch");
		timeout_object->event = NULL;
		timeout->std = NULL;
		OBJ_RELEASE(object);
	}

	if (timeout->prev_dispose) {
		timeout->prev_dispose(event);
	}

	return true;
}

static void timeout_before_notify_handler(zend_async_event_t *event, void *result, zend_object *exception)
{
	if (UNEXPECTED(exception != NULL)) {
		ZEND_ASYNC_CALLBACKS_NOTIFY_FROM_HANDLER(event, result, exception);
		return;
	}

	// Here we override the exception value with a timeout exception.
	zend_object *timeout_exception = async_new_exception(async_ce_timeout_exception,
														 "Timeout occurred after %lu milliseconds",
														 ((zend_async_timer_event_t *) event)->timeout);

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

	zend_async_event_t *event =
			(zend_async_event_t *) ZEND_ASYNC_NEW_TIMER_EVENT_EX(ms, is_periodic, sizeof(async_timeout_ext_t));

	if (UNEXPECTED(event == NULL)) {
		efree(object);
		return NULL;
	}

	ZEND_ASYNC_EVENT_REF_SET(object, XtOffsetOf(async_timeout_object_t, std), (zend_async_timer_event_t *) event);
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
	async_ce_timeout = register_class_Async_Timeout(async_ce_completable);

	async_ce_timeout->create_object = NULL;

	async_timeout_handlers = std_object_handlers;

	async_timeout_handlers.offset = XtOffsetOf(async_timeout_object_t, std);
	async_timeout_handlers.dtor_obj = async_timeout_destroy_object;
}

static PHP_GINIT_FUNCTION(async)
{
#if defined(COMPILE_DL_ASYNC) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif

	async_globals->reactor_started = false;
	async_globals->signal_handlers = NULL;
	async_globals->signal_events = NULL;
	async_globals->process_events = NULL;
	async_globals->root_context = NULL;
	/* Maximum number of coroutines in the concurrent iterator */
	async_globals->default_concurrency = 32;

	/* Initialize reactor execution optimization */
	async_globals->last_reactor_tick = 0;

#ifdef PHP_WIN32
	async_globals->watcherThread = NULL;
	async_globals->ioCompletionPort = NULL;
	async_globals->countWaitingDescriptors = 0;
	async_globals->isRunning = false;
	async_globals->uvloop_wakeup = NULL;
	async_globals->pid_queue = NULL;
#endif
}

/* {{{ PHP_GSHUTDOWN_FUNCTION */
static PHP_GSHUTDOWN_FUNCTION(async){
#ifdef PHP_WIN32
#endif
} /* }}} */

/* Module registration */

ZEND_MINIT_FUNCTION(async)
{
	async_register_awaitable_ce();
	async_register_timeout_ce();
	async_register_scope_ce();
	async_register_coroutine_ce();
	async_register_context_ce();
	async_register_exceptions_ce();
	async_register_channel_ce();
	async_register_filesystem_event_ce();
	async_register_circuit_breaker_ce();
	async_register_pool_ce();
	async_register_task_group_ce();
	async_register_future_ce();

	async_scheduler_startup();
	async_api_register();
	async_pool_api_register();
	async_libuv_reactor_register();

	return SUCCESS;
}

ZEND_MSHUTDOWN_FUNCTION(async)
{
	// async_scheduler_shutdown();
	// async_libuv_shutdown();

	return SUCCESS;
}

PHP_MINFO_FUNCTION(async)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "Module", PHP_ASYNC_NAME);
	php_info_print_table_row(2, "Version", PHP_ASYNC_VERSION);
	php_info_print_table_row(2, "Support", "Enabled");
	php_info_print_table_row(2, "LibUv Reactor", "Enabled");
	php_info_print_table_end();
}

PHP_RINIT_FUNCTION(async) /* {{{ */
{
	// async_host_name_list_ctor();
	ZEND_ASYNC_INITIALIZE;
	circular_buffer_ctor(&ASYNC_G(microtasks), 64, sizeof(zend_async_microtask_t *), &zend_std_allocator);
	circular_buffer_ctor(&ASYNC_G(coroutine_queue), 128, sizeof(zend_coroutine_t *), &zend_std_allocator);
	circular_buffer_ctor(&ASYNC_G(resumed_coroutines), 64, sizeof(zend_coroutine_t *), &zend_std_allocator);
	zend_hash_init(&ASYNC_G(coroutines), 128, NULL, NULL, 0);

	ASYNC_G(reactor_started) = false;

	return SUCCESS;
} /* }}} */

zend_module_entry async_module_entry = { STANDARD_MODULE_HEADER,
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
										 STANDARD_MODULE_PROPERTIES_EX };

#ifdef COMPILE_DL_ASYNC
ZEND_GET_MODULE(async)
#endif
