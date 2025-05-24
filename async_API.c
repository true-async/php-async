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
#include "async_API.h"

#include "exceptions.h"
#include "iterator.h"
#include "php_async.h"
#include "scheduler.h"
#include "scope.h"
#include "zend_common.h"

zend_async_scope_t * async_provide_scope(zend_object *scope_provider)
{
	zval retval;

	if (zend_call_method_with_0_params(scope_provider, scope_provider->ce, NULL, "provideScope", &retval) == NULL) {
		return NULL;
	}

	if (Z_TYPE(retval) == IS_OBJECT && instanceof_function(Z_OBJCE(retval), async_ce_scope)) {
		zend_async_scope_t *scope = &((async_scope_object_t *)Z_OBJ(retval))->scope->scope;
		zval_ptr_dtor(&retval);
		return scope;
	}

	zval_ptr_dtor(&retval);

	zend_async_throw(
		ZEND_ASYNC_EXCEPTION_DEFAULT,
		"Scope provider must return an instance of Async\\Scope"
	);

	return NULL;
}

zend_coroutine_t *spawn(zend_async_scope_t *scope, zend_object * scope_provider)
{
	if (UNEXPECTED(ZEND_ASYNC_OFF)) {
		async_throw_error("Cannot spawn a coroutine when async is disabled");
		return NULL;
	}

	if (scope == NULL && scope_provider != NULL) {
		scope = async_provide_scope(scope_provider);

		if (UNEXPECTED(EG(exception) != NULL)) {
			return NULL;
		}
	}

	if (scope == NULL) {

		if (UNEXPECTED(ZEND_ASYNC_CURRENT_SCOPE == NULL)) {
			ZEND_ASYNC_CURRENT_SCOPE = async_new_scope(NULL);

			if (UNEXPECTED(EG(exception))) {
				return NULL;
			}
		}

		scope = ZEND_ASYNC_CURRENT_SCOPE;
	}

	if (UNEXPECTED(scope == NULL)) {
		async_throw_error("Cannot spawn a coroutine without a scope");
		return NULL;
	}

	if (UNEXPECTED(ZEND_ASYNC_SCOPE_IS_CLOSED(scope))) {
		async_throw_error("Cannot spawn a coroutine in a closed scope");
		return NULL;
	}

	async_coroutine_t *coroutine = (async_coroutine_t *) new_coroutine(scope);
	if (UNEXPECTED(EG(exception))) {
		return NULL;
	}

	zval options;
	ZVAL_UNDEF(&options);
	scope->before_coroutine_enqueue(&coroutine->coroutine, scope, &options);
	zval_dtor(&options);

	if (UNEXPECTED(EG(exception))) {
		coroutine->coroutine.event.dispose(&coroutine->coroutine.event);
		return NULL;
	}

	// call SpawnStrategy::beforeCoroutineEnqueue
	if (scope_provider != NULL) {
		zval coroutine_zval, scope_zval;
		ZVAL_OBJ(&coroutine_zval, &coroutine->std);
		ZVAL_OBJ(&scope_zval, scope->scope_object);

		if (zend_call_method_with_2_params(
			scope_provider,
			scope_provider->ce,
			NULL,
			"beforeCoroutineEnqueue",
			&options,
			&coroutine_zval,
			&scope_zval) == NULL) {

			coroutine->coroutine.event.dispose(&coroutine->coroutine.event);
			return NULL;
		}

		zval_dtor(&options);
	}

	zend_async_waker_t *waker = zend_async_waker_new(&coroutine->coroutine);
	if (UNEXPECTED(EG(exception))) {
		coroutine->coroutine.event.dispose(&coroutine->coroutine.event);
		return NULL;
	}

	waker->status = ZEND_ASYNC_WAKER_QUEUED;

	if (UNEXPECTED(circular_buffer_push(&ASYNC_G(coroutine_queue), &coroutine, true)) == FAILURE) {
		coroutine->coroutine.event.dispose(&coroutine->coroutine.event);
		async_throw_error("Failed to enqueue coroutine");
		return NULL;
	}

	scope->after_coroutine_enqueue(&coroutine->coroutine, scope);
	if (UNEXPECTED(EG(exception))) {
		waker->status = ZEND_ASYNC_WAKER_IGNORED;
		return NULL;
	}

	// call SpawnStrategy::afterCoroutineEnqueue
	if (scope_provider != NULL) {
		zval coroutine_zval, scope_zval;
		ZVAL_OBJ(&coroutine_zval, &coroutine->std);
		ZVAL_OBJ(&scope_zval, scope->scope_object);

		if (zend_call_method_with_2_params(
			scope_provider,
			scope_provider->ce,
			NULL,
			"afterCoroutineEnqueue",
			&options,
			&coroutine_zval,
			&scope_zval) == NULL) {

				waker->status = ZEND_ASYNC_WAKER_IGNORED;
				return NULL;
			}

		zval_dtor(&options);
	}

	if (UNEXPECTED(zend_hash_index_add_ptr(&ASYNC_G(coroutines), coroutine->std.handle, coroutine) == NULL)) {
		waker->status = ZEND_ASYNC_WAKER_IGNORED;
		async_throw_error("Failed to add coroutine to the list");
		return NULL;
	}

	ZEND_ASYNC_INCREASE_COROUTINE_COUNT;

	return &coroutine->coroutine;
}

void suspend(const bool from_main)
{
	if (UNEXPECTED(from_main)) {
		// If the Scheduler was never used, it means no coroutines were created,
		// so execution can be finished without doing anything.
		if (circular_buffer_is_empty(&ASYNC_G(microtasks)) && zend_hash_num_elements(&ASYNC_G(coroutines)) == 0) {
			return;
		}

		async_scheduler_main_coroutine_suspend();
		return;
	}

	async_scheduler_coroutine_suspend(NULL);
}

void resume(zend_coroutine_t *coroutine, zend_object * error, const bool transfer_error)
{
	if (UNEXPECTED(coroutine->waker == NULL)) {
		async_throw_error("Cannot resume a coroutine that has not been suspended");
		return;
	}

	if (error != NULL) {
		if (coroutine->waker->error != NULL) {
			zend_exception_set_previous(error, coroutine->waker->error);
			OBJ_RELEASE(coroutine->waker->error);
			coroutine->waker->error = error;
		}

		if (false == transfer_error) {
			GC_ADDREF(error);
		}
	}

	if (UNEXPECTED(coroutine->waker->status == ZEND_ASYNC_WAKER_QUEUED)) {
		return;
	}

	if (UNEXPECTED(circular_buffer_push(&ASYNC_G(coroutine_queue), &coroutine, true)) == FAILURE) {
		async_throw_error("Failed to enqueue coroutine");
		return;
	}

	coroutine->waker->status = ZEND_ASYNC_WAKER_QUEUED;
}

void cancel(zend_coroutine_t *zend_coroutine, zend_object *error, const bool transfer_error, const bool is_safely)
{
	// If the coroutine hasn't even started, do nothing.
	if (false == ZEND_COROUTINE_IS_STARTED(zend_coroutine) || ZEND_COROUTINE_IS_FINISHED(zend_coroutine)) {
		if (transfer_error && error != NULL) {
			OBJ_RELEASE(error);
		}

		return;
	}

	if (zend_coroutine->waker == NULL) {
		zend_async_waker_new(zend_coroutine);
	}

	if (UNEXPECTED(zend_coroutine->waker == NULL)) {
		async_throw_error("Waker is not initialized");

		if (transfer_error) {
			OBJ_RELEASE(error);
		}

		return;
	}

	ZEND_COROUTINE_SET_CANCELLED(zend_coroutine);

	// In safely mode, we don't forcibly terminate the coroutine,
	// but we do mark it as a Zombie.
	if (is_safely && error == NULL) {
		ZEND_COROUTINE_SET_ZOMBIE(zend_coroutine);
		ZEND_ASYNC_DECREASE_COROUTINE_COUNT
		return;
	}

	const bool is_error_null = (error == NULL);

	if (is_error_null) {
		error = async_new_exception(async_ce_cancellation_exception, "Coroutine cancelled");
		if (UNEXPECTED(EG(exception))) {
			return;
		}
	}

	if (zend_coroutine->waker->error != NULL) {
		zend_exception_set_previous(error, zend_coroutine->waker->error);
		OBJ_RELEASE(zend_coroutine->waker->error);
	}

	zend_coroutine->waker->error = error;

	if (false == transfer_error && false == is_error_null) {
		GC_ADDREF(error);
	}
}

static void graceful_shutdown(void)
{
	start_graceful_shutdown();
}

zend_array * get_coroutines(void)
{
	return &ASYNC_G(coroutines);
}

void add_microtask(zend_async_microtask_t *microtask)
{
	if (microtask->is_cancelled) {
		return;
	}

	if (UNEXPECTED(circular_buffer_push(&ASYNC_G(microtasks), microtask, true) == FAILURE)) {
		async_throw_error("Failed to enqueue microtask");
		return;
	}
}

zend_array *get_awaiting_info(zend_coroutine_t *coroutine)
{
	/* @todo: implement get_awaiting_info */
	return NULL;
}

static zend_class_entry* async_get_exception_ce(zend_async_exception_type type)
{
	switch (type) {
		case ZEND_ASYNC_EXCEPTION_CANCELLATION:
			return async_ce_cancellation_exception;
		case ZEND_ASYNC_EXCEPTION_TIMEOUT:
			return async_ce_timeout_exception;
		case ZEND_ASYNC_EXCEPTION_POLL:
			return async_ce_poll_exception;
		default:
			return async_ce_async_exception;
	}
}

////////////////////////////////////////////////////////////////////
/// async_await_futures
////////////////////////////////////////////////////////////////////

#define AWAIT_ALL(await_context) ((await_context)->waiting_count == 0 || (await_context)->waiting_count == (await_context)->total)

static zend_always_inline zend_async_event_t * zval_to_event(const zval * current)
{
	// An array element can be either an object implementing
	// the Awaitable interface
	// or an internal structure zend_async_event_t.

	if (Z_TYPE_P(current) == IS_OBJECT
		&& instanceof_function(Z_OBJCE_P(current), async_ce_awaitable)) {
		return ZEND_ASYNC_OBJECT_TO_EVENT(Z_OBJ_P(current));
	} else if (Z_TYPE_P(current) == IS_PTR) {
		return (zend_async_event_t *) Z_PTR_P(current);
	} else if (Z_TYPE_P(current) == IS_NULL || Z_TYPE_P(current) == IS_UNDEF) {
		return NULL;
	} else {
		async_throw_error("Expected item to be an Async\\Awaitable object");
		return NULL;
	}
}

void async_waiting_callback(
	zend_async_event_t *event,
	zend_async_event_callback_t *callback,
	void *result,
	zend_object *exception
)
{
	async_await_callback_t * await_callback = (async_await_callback_t *) callback;
	async_await_context_t * await_context = await_callback->await_context;

	await_context->resolved_count++;

	// remove the callback from the event
	// We remove the callback because we treat all events
	// as FUTURE-type objects, where the trigger can be activated only once.
	event->del_callback(event, callback);

	if (await_context->errors != NULL && exception != NULL) {
		const zval *success = NULL;
		zval exception_obj;
		ZVAL_OBJ(&exception_obj, exception);

		if (Z_TYPE(await_callback->key) == IS_STRING) {
			success = zend_hash_update(await_context->errors, Z_STR(await_callback->key), &exception_obj);
		} else if (Z_TYPE(await_callback->key) == IS_LONG) {
			success = zend_hash_index_update(await_context->errors, Z_LVAL(await_callback->key), &exception_obj);
		} else if (Z_TYPE(await_callback->key) == IS_NULL || Z_TYPE(await_callback->key) == IS_UNDEF) {
			success = zend_hash_next_index_insert_new(await_context->errors, &exception_obj);
			ZVAL_LONG(&await_callback->key, await_context->errors->nNextFreeElement - 1);
		} else {
			ZEND_ASSERT("Invalid key type: must be string, long or null");
		}

		if (success != NULL) {
			GC_ADDREF(exception);
		}
	}

	if (exception != NULL && false == await_context->ignore_errors) {
		ZEND_ASYNC_RESUME_WITH_ERROR(
			await_callback->callback.coroutine,
			exception,
			false
		);

		return;
	}

	if (await_context->results != NULL && ZEND_ASYNC_EVENT_WILL_ZVAL_RESULT(event) && result != NULL) {

		const zval *success = NULL;

		if (Z_TYPE(await_callback->key) == IS_STRING) {
			success = zend_hash_update(await_context->results, Z_STR(await_callback->key), result);
		} else if (Z_TYPE(await_callback->key) == IS_LONG) {
			success = zend_hash_index_update(await_context->results, Z_LVAL(await_callback->key), result);
		} else if (Z_TYPE(await_callback->key) == IS_NULL || Z_TYPE(await_callback->key) == IS_UNDEF) {
			success = zend_hash_next_index_insert_new(await_context->results, result);
			ZVAL_LONG(&await_callback->key, await_context->results->nNextFreeElement - 1);
		} else {
			ZEND_ASSERT("Invalid key type: must be string, long or null");
		}

		if (success != NULL) {
			zval_add_ref(result);
		}
	}

	if (await_context->resolved_count >= await_context->waiting_count) {
		ZEND_ASYNC_RESUME(await_callback->callback.coroutine);
	}
}

zend_result await_iterator_handler(async_iterator_t *iterator, zval *current, zval *key)
{
	async_await_iterator_t * await_iterator = ((async_await_iterator_iterator_t *) iterator)->await_iterator;

	// An array element can be either an object implementing
	// the Awaitable interface
	// or an internal structure zend_async_event_t.

	zend_async_event_t* awaitable = zval_to_event(current);

	if (UNEXPECTED(EG(exception))) {
		return FAILURE;
	}

	if (awaitable == NULL || ZEND_ASYNC_EVENT_IS_CLOSED(awaitable)) {
		return SUCCESS;
	}

	async_await_callback_t * callback = ecalloc(1, sizeof(async_await_callback_t));
	callback->callback.base.callback = async_waiting_callback;
	callback->await_context = await_iterator->await_context;

	ZVAL_COPY(&callback->key, key);

	// If the futures array is defined, we collect all new objects into it.
	if (await_iterator->futures != NULL) {
		if (Z_TYPE_P(key) == IS_STRING) {
			zend_hash_update(await_iterator->futures, Z_STR_P(key), current);
		} else if (Z_TYPE_P(key) == IS_LONG) {
			zend_hash_index_update(await_iterator->futures, Z_LVAL_P(key), current);
		} else if (Z_TYPE_P(key) == IS_NULL || Z_TYPE_P(key) == IS_UNDEF) {
			// If the key is NULL, we use the next index
			if (zend_hash_next_index_insert_new(await_iterator->futures, current) != NULL) {
				ZVAL_LONG(&callback->key, await_iterator->futures->nNextFreeElement - 1);
			}
		}
	}

	// Add the empty element to the results array if all elements are awaited
	if (await_iterator->await_context->results != NULL && AWAIT_ALL(await_iterator->await_context)) {
		if (Z_TYPE(callback->key) == IS_STRING) {
			zend_hash_add_empty_element(await_iterator->await_context->results, Z_STR_P(key));
		} else if (Z_TYPE(callback->key) == IS_LONG) {
			zend_hash_index_add_empty_element(await_iterator->await_context->results, Z_LVAL_P(key));
		}
	} else if (await_iterator->await_context->results != NULL && await_iterator->await_context->fill_missing_with_null) {
		if (Z_TYPE(callback->key) == IS_STRING) {
			zend_hash_add(await_iterator->await_context->results, Z_STR_P(key), &EG(uninitialized_zval));
		} else if (Z_TYPE(callback->key) == IS_LONG) {
			zend_hash_index_add(await_iterator->await_context->results, Z_LVAL_P(key), &EG(uninitialized_zval));
		}
	}

	zend_async_resume_when(await_iterator->waiting_coroutine, awaitable, false, NULL, &callback->callback);

	return SUCCESS;
}

void iterator_coroutine_entry(void)
{
	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		async_throw_error("Cannot run iterator coroutine");
		return;
	}

	async_await_iterator_t * await_iterator = coroutine->extended_data;

	ZEND_ASSERT(await_iterator != NULL && "The async_await_iterator_t should not be NULL");
	if (UNEXPECTED(await_iterator == NULL)) {
		async_throw_error("Cannot run concurrent iterator coroutine");
		return;
	}

	async_await_context_t * await_context = await_iterator->await_context;

	if (UNEXPECTED(await_context == NULL)) {
		return;
	}

	 async_await_iterator_iterator_t * iterator = (async_await_iterator_iterator_t *) async_new_iterator(
		NULL,
		await_iterator->zend_iterator,
		NULL,
		await_iterator_handler,
		await_context->concurrency,
		sizeof(async_await_iterator_iterator_t)
	);

	if (UNEXPECTED(iterator == NULL)) {
		return;
	}

	async_run_iterator(&iterator->iterator);
	efree(iterator);
}

void iterator_coroutine_finish_callback(
	zend_async_event_t *event,
	zend_async_event_callback_t *callback,
	void * result,
	zend_object *exception
)
{
	async_await_iterator_t * iterator = (async_await_iterator_t *)
			((zend_coroutine_event_callback_t*) callback)->coroutine->extended_data;

	if (exception != NULL) {
		// Resume the waiting coroutine with the exception
		ZEND_ASYNC_RESUME_WITH_ERROR(
			iterator->waiting_coroutine,
			exception,
			false
		);
	} else if (iterator->await_context->resolved_count >= iterator->await_context->waiting_count) {
		// If iteration is finished, resume the waiting coroutine
		ZEND_ASYNC_RESUME(iterator->waiting_coroutine);
	}
}

void async_await_iterator_coroutine_dispose(zend_coroutine_t *coroutine)
{
	if (coroutine == NULL || coroutine->extended_data == NULL) {
		return;
	}

	async_await_iterator_t * iterator = (async_await_iterator_t *) coroutine->extended_data;
	coroutine->extended_data = NULL;

	efree(iterator);
}

void await_context_dtor(async_await_context_t *context)
{
	if (context == NULL) {
		return;
	}

	if (context->ref_count > 1) {
		context->ref_count--;
		return;
	}

	efree(context);
}

void async_await_futures(
	zval *iterable,
	int count,
	bool ignore_errors,
	zend_async_event_t *cancellation,
	zend_ulong timeout,
	unsigned int concurrency,
	HashTable *results,
	HashTable *errors,
	bool fill_missing_with_null
)
{
	HashTable *futures = NULL;
	zend_object_iterator *zend_iterator = NULL;
	HashTable *tmp_results = NULL;

	if (Z_TYPE_P(iterable) == IS_ARRAY) {
		futures = Z_ARR_P(iterable);
	} else if (Z_TYPE_P(iterable) == IS_OBJECT && Z_OBJCE_P(iterable)->get_iterator) {
		zend_iterator = Z_OBJCE_P(iterable)->get_iterator(Z_OBJCE_P(iterable), iterable, 0);

		if (EG(exception) == NULL && zend_iterator == NULL) {
			async_throw_error("Failed to create iterator");
		}

    } else {
	    async_throw_error("Expected parameter 'iterable' to be an array or an object implementing Traversable");
    }

	if (UNEXPECTED(EG(exception))) {
		return;
	}

	zend_ulong index;
	zend_string *key;
	zval * current;

	async_await_context_t *await_context = NULL;

	if (UNEXPECTED(futures != NULL && zend_hash_num_elements(futures) == 0)) {
		return;
	}

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		async_throw_error("Cannot await futures outside of a coroutine");
		return;
	}

	if (UNEXPECTED(zend_async_waker_new_with_timeout(coroutine, timeout, cancellation) == NULL)) {
		return;
	}

	await_context = ecalloc(1, sizeof(async_await_context_t));
	await_context->total = futures != NULL ? (int) zend_hash_num_elements(futures) : 0;
	await_context->waiting_count = count > 0 ? count : await_context->total;
	await_context->resolved_count = 0;
	await_context->ignore_errors = ignore_errors;
	await_context->concurrency = concurrency;
	await_context->fill_missing_with_null = fill_missing_with_null;

	if (AWAIT_ALL(await_context)) {
		tmp_results = zend_new_array(await_context->total);
		await_context->results = tmp_results;
	} else {
		await_context->results = results;
	}

	await_context->errors = errors;
	await_context->dtor = await_context_dtor;
	await_context->ref_count = 1;

	if (futures != NULL)
	{
		ZEND_HASH_FOREACH_KEY_VAL(futures, index, key, current) {

			// An array element can be either an object implementing
			// the Awaitable interface
			// or an internal structure zend_async_event_t.

			zend_async_event_t* awaitable = zval_to_event(current);

			if (UNEXPECTED(EG(exception))) {
				await_context->dtor(await_context);
				return;
			}

			if (awaitable == NULL || ZEND_ASYNC_EVENT_IS_CLOSED(awaitable)) {
				continue;
			}

			async_await_callback_t * callback = ecalloc(1, sizeof(async_await_callback_t));
			callback->callback.base.callback = async_waiting_callback;
			callback->await_context = await_context;

			ZEND_ASYNC_EVENT_SET_RESULT_USED(awaitable);
			ZEND_ASYNC_EVENT_SET_EXC_CAUGHT(awaitable);

			if (key != NULL) {
				ZVAL_STR(&callback->key, key);
				zval_add_ref(&callback->key);

				if (await_context->results != NULL && AWAIT_ALL(await_context)) {
					zend_hash_add_empty_element(await_context->results, key);
				} else if (await_context->results != NULL && await_context->fill_missing_with_null) {
					zend_hash_add(await_context->results, key, &EG(uninitialized_zval));
				}

			} else {
				ZVAL_LONG(&callback->key, index);

				if (await_context->results != NULL && AWAIT_ALL(await_context)) {
					zend_hash_index_add_empty_element(await_context->results, index);
				} else if (await_context->results != NULL && await_context->fill_missing_with_null) {
					zend_hash_index_add_new(await_context->results, index, &EG(uninitialized_zval));
				}
			}

			zend_async_resume_when(coroutine, awaitable, false, NULL, &callback->callback);

			if (UNEXPECTED(EG(exception))) {
				await_context->dtor(await_context);
				return;
			}

			await_context->ref_count++;

		} ZEND_HASH_FOREACH_END();
	} else {

		// To launch the concurrent iterator,
		// we need a separate coroutine because we're needed to suspend the current one.

		// Coroutines associated with concurrent iteration are created in a child Scope,
		// which ensures that all child tasks are stopped if the main task is cancelled.
		zend_async_scope_t * scope = ZEND_ASYNC_NEW_SCOPE(ZEND_ASYNC_CURRENT_SCOPE);

		if (UNEXPECTED(scope == NULL || EG(exception))) {
			await_context->dtor(await_context);
			return;
		}

		zend_coroutine_t * iterator_coroutine = ZEND_ASYNC_SPAWN_WITH(scope);

		if (UNEXPECTED(iterator_coroutine == NULL || EG(exception))) {
			await_context->dtor(await_context);
			return;
		}

		await_context->scope = scope;
		iterator_coroutine->internal_entry = iterator_coroutine_entry;

		async_await_iterator_t * iterator = ecalloc(1, sizeof(async_await_iterator_t));
		iterator->zend_iterator = zend_iterator;
		iterator->waiting_coroutine = coroutine;
		iterator->iterator_coroutine = iterator_coroutine;

		iterator_coroutine->extended_data = iterator;
		iterator_coroutine->extended_dispose = async_await_iterator_coroutine_dispose;

		zend_async_resume_when(
			coroutine, &iterator_coroutine->event, false, iterator_coroutine_finish_callback, NULL
		);
	}

	ZEND_ASYNC_SUSPEND();

	// Free the coroutine scope if it was created for the iterator.
	if (await_context->scope != NULL) {
		await_context->scope->dispose(await_context->scope);
		await_context->scope = NULL;
	}

	// Remove all undefined buckets from the results array.
	if (tmp_results != NULL) {

		// foreach results as key => value
		// if value is UNDEFINED then continue
		ZEND_HASH_FOREACH_KEY_VAL(tmp_results, index, key, current) {
			if (Z_TYPE_P(current) == IS_UNDEF) {
				continue;
			}

			if (key != NULL) {
				zend_hash_update(results, key, current);
			} else {
				zend_hash_index_update(results, index, current);
			}
		} ZEND_HASH_FOREACH_END();

		await_context->results = NULL;
		zend_array_release(tmp_results);
	}

	await_context->dtor(await_context);

	if (futures != NULL) {
		zend_array_release(futures);
	}
}

void async_api_register(void)
{
	zend_string *module = zend_string_init(PHP_ASYNC_NAME_VERSION, sizeof(PHP_ASYNC_NAME_VERSION) - 1, 0);

	zend_async_scheduler_register(
		module,
		false,
		new_coroutine,
		async_new_scope,
		spawn,
		suspend,
		resume,
		cancel,
		graceful_shutdown,
		get_coroutines,
		add_microtask,
		get_awaiting_info,
		async_get_exception_ce
	);

	zend_string_release(module);
}