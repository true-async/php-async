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

#include "context.h"
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

zend_coroutine_t *spawn(zend_async_scope_t *scope, zend_object * scope_provider, int32_t priority)
{
	if (UNEXPECTED(ZEND_ASYNC_IS_OFF)) {
		async_throw_error("Cannot spawn a coroutine when async is disabled");
		return NULL;
	} else if (UNEXPECTED(ZEND_ASYNC_IS_READY)) {
		async_scheduler_launch();
		if (UNEXPECTED(EG(exception) != NULL)) {
			return NULL;
		}
	}

	if (scope == NULL && scope_provider != NULL) {
		scope = async_provide_scope(scope_provider);

		if (UNEXPECTED(EG(exception) != NULL)) {
			return NULL;
		}
	}

	if (scope == NULL) {

		if (UNEXPECTED(ZEND_ASYNC_CURRENT_SCOPE == NULL && ZEND_ASYNC_MAIN_SCOPE == NULL)) {
			ZEND_ASYNC_MAIN_SCOPE = ZEND_ASYNC_NEW_SCOPE(NULL);

			if (UNEXPECTED(EG(exception))) {
				return NULL;
			}
		}

		if (EXPECTED(ZEND_ASYNC_CURRENT_SCOPE != NULL)) {
			scope = ZEND_ASYNC_CURRENT_SCOPE;
		} else {
			scope = ZEND_ASYNC_MAIN_SCOPE;
		}
	}

	if (UNEXPECTED(scope == NULL)) {
		async_throw_error("Cannot spawn a coroutine without a scope");
		return NULL;
	}

	if (UNEXPECTED(ZEND_ASYNC_SCOPE_IS_CLOSED(scope))) {
		async_throw_error("Cannot spawn a coroutine in a closed scope");
		return NULL;
	}

	async_coroutine_t *coroutine = (async_coroutine_t *) async_new_coroutine(scope);
	if (UNEXPECTED(EG(exception))) {
		return NULL;
	}

	zend_apply_current_filename_and_line(&coroutine->coroutine.filename, &coroutine->coroutine.lineno);

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

	// Use priority to determine enqueue method
	zend_result enqueue_result;
	if (priority > 0) {
		// High priority: add to front of queue
		enqueue_result = circular_buffer_push_front(&ASYNC_G(coroutine_queue), &coroutine, true);
	} else {
		// Normal or low priority: add to back of queue
		enqueue_result = circular_buffer_push(&ASYNC_G(coroutine_queue), &coroutine, true);
	}
	
	if (UNEXPECTED(enqueue_result == FAILURE)) {
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

static void engine_shutdown(void)
{
	ZEND_ASYNC_REACTOR_SHUTDOWN();

	circular_buffer_dtor(&ASYNC_G(microtasks));
	circular_buffer_dtor(&ASYNC_G(coroutine_queue));
	zend_hash_destroy(&ASYNC_G(coroutines));

	//async_host_name_list_dtor();
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

	if (UNEXPECTED(circular_buffer_push(&ASYNC_G(microtasks), &microtask, true) == FAILURE)) {
		async_throw_error("Failed to enqueue microtask");
		return;
	}

	microtask->ref_count++;
}

zend_array *get_awaiting_info(zend_coroutine_t *coroutine)
{
	/* @todo: implement get_awaiting_info */
	return NULL;
}

static zend_class_entry* async_get_class_ce(zend_async_class type)
{
	switch (type) {
		case ZEND_ASYNC_CLASS_COROUTINE:
			return async_ce_coroutine;
		case ZEND_ASYNC_CLASS_SCOPE:
			return async_ce_scope;
		case ZEND_ASYNC_CLASS_TIMEOUT:
			return async_ce_timeout;
		case ZEND_ASYNC_EXCEPTION_DEFAULT:
			return async_ce_async_exception;
		case ZEND_ASYNC_EXCEPTION_CANCELLATION:
			return async_ce_cancellation_exception;
		case ZEND_ASYNC_EXCEPTION_TIMEOUT:
			return async_ce_timeout_exception;
		case ZEND_ASYNC_EXCEPTION_INPUT_OUTPUT:
			return async_ce_input_output_exception;
		case ZEND_ASYNC_EXCEPTION_POLL:
			return async_ce_poll_exception;
		case ZEND_ASYNC_EXCEPTION_DNS:
			return async_ce_dns_exception;
		default:
			return NULL;
	}
}

////////////////////////////////////////////////////////////////////
/// async_await_futures
////////////////////////////////////////////////////////////////////

#define AWAIT_ALL(await_context) ((await_context)->waiting_count == 0 || (await_context)->waiting_count == (await_context)->total)
#define AWAIT_ITERATOR_IS_FINISHED(await_context) \
	((await_context->waiting_count > 0 \
		&& (await_context->ignore_errors ? await_context->success_count : await_context->resolved_count) \
			>= await_context->waiting_count) || \
	(await_context->total != 0 && await_context->resolved_count >= await_context->total) \
	)

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

/**
 * The function is called to release resources for the callback structure.
 *
 * @param callback
 * @param event
 */
static void async_waiting_callback_dispose(zend_async_event_callback_t *callback, zend_async_event_t * event)
{
	async_await_callback_t * await_callback = (async_await_callback_t *) callback;
	async_await_context_t * await_context = await_callback->await_context;

	await_callback->await_context = NULL;

	if (await_context != NULL) {
		await_context->dtor(await_context);
	}

	await_callback->prev_dispose(callback, event);
}

/**
 * This callback is used for awaiting futures.
 * It is called when the future is resolved or rejected.
 * It updates the await context and resumes the coroutine if necessary.
 *
 * @param event
 * @param callback
 * @param result
 * @param exception
 */
static void async_waiting_callback(
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
	ZEND_ASYNC_EVENT_CALLBACK_ADD_REF(callback);
	event->del_callback(event, callback);
	ZEND_ASYNC_EVENT_CALLBACK_DEC_REF(callback);

	if (exception != NULL) {
		ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(event);
	}

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

		callback->dispose(callback, NULL);
		return;
	}

	// If the exception exists, and we are ignoring errors, we do not resume the coroutine.
	if (exception != NULL && await_context->ignore_errors) {
		// But if there's no one left to wait for, stop waiting.
		if (await_context->total != 0 && await_context->resolved_count >= await_context->total) {
			ZEND_ASYNC_RESUME(await_callback->callback.coroutine);
		}

		callback->dispose(callback, NULL);
		return;
	}

	if (exception == NULL && await_context->results != NULL && ZEND_ASYNC_EVENT_WILL_ZVAL_RESULT(event) && result != NULL) {

		const zval *success = NULL;
		await_context->success_count++;

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
			Z_TRY_ADDREF_P(result);
		}
	}

	if (UNEXPECTED(AWAIT_ITERATOR_IS_FINISHED(await_context))) {
		ZEND_ASYNC_RESUME(await_callback->callback.coroutine);
	}

	callback->dispose(callback, NULL);
}

/**
 * This callback is used only for awaiting cancelled coroutines.
 * It resumes the target coroutine only after
 * all coroutines awaiting cancellation have fully completed.
 *
 * @param event
 * @param callback
 * @param result
 * @param exception
 */
static void async_waiting_cancellation_callback(
	zend_async_event_t *event,
	zend_async_event_callback_t *callback,
	void *result,
	zend_object *exception
) {
	async_await_callback_t * await_callback = (async_await_callback_t *) callback;
	async_await_context_t * await_context = await_callback->await_context;

	await_context->resolved_count++;
	ZEND_ASYNC_EVENT_CALLBACK_ADD_REF(callback);
	event->del_callback(event, callback);
	ZEND_ASYNC_EVENT_CALLBACK_DEC_REF(callback);

	if (exception != NULL) {
		ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(event);
	}

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

	if (await_context->total != 0 && await_context->resolved_count >= await_context->total) {
		ZEND_ASYNC_RESUME(await_callback->callback.coroutine);
	}

	callback->dispose(callback, NULL);
}

/**
 * A function that is called to process a single iteration element.
 *
 * @param iterator
 * @param current
 * @param key
 * @return
 */
static zend_result await_iterator_handler(async_iterator_t *iterator, zval *current, zval *key)
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
	async_await_context_t * await_context = await_iterator->await_context;
	callback->await_context = await_context;

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
	if (await_context->results != NULL && await_context->fill_missing_with_null) {
		if (Z_TYPE(callback->key) == IS_STRING) {
			zend_hash_add_empty_element(await_context->results, Z_STR_P(key));
		} else if (Z_TYPE(callback->key) == IS_LONG) {
			zend_hash_index_add_empty_element(await_context->results, Z_LVAL_P(key));
		}
	} else if (await_context->results != NULL) {
		zval undef_val;
		// The PRT NULL type is used to fill the array with empty elements that will later be removed.
		ZVAL_PTR(&undef_val, NULL);
		if (Z_TYPE(callback->key) == IS_STRING) {
			zend_hash_add(await_context->results, Z_STR_P(key), &undef_val);
		} else if (Z_TYPE(callback->key) == IS_LONG) {
			zend_hash_index_add(await_context->results, Z_LVAL_P(key), &undef_val);
		}
	}

	zend_async_resume_when(await_iterator->waiting_coroutine, awaitable, false, NULL, &callback->callback);
	if (UNEXPECTED(EG(exception))) {
		return FAILURE;
	}

	await_context->futures_count++;

	return SUCCESS;
}

/**
 * This function is called when the await_iterator is disposed.
 * It cleans up the internal state and releases resources.
 *
 * @param iterator
 */
static void await_iterator_dispose(async_await_iterator_t * iterator)
{
	if (iterator->zend_iterator != NULL) {
		zend_object_iterator *zend_iterator = iterator->zend_iterator;
		iterator->zend_iterator = NULL;

		// When the iterator has finished, it’s now possible to specify the exact number of elements since it’s known.
		iterator->await_context->total = iterator->await_context->futures_count;

		if (zend_iterator->funcs->invalidate_current) {
			zend_iterator->funcs->invalidate_current(zend_iterator);
		}
		zend_iterator_dtor(zend_iterator);
	}

	efree(iterator);
}

/**
 * This function is called when the internal concurrent iterator is finished.
 * It disposes of the await_iterator and cleans up the internal state.
 *
 * @param internal_iterator
 */
static void await_iterator_finish_callback(zend_async_iterator_t *internal_iterator)
{
	async_await_iterator_iterator_t * iterator = (async_await_iterator_iterator_t *) internal_iterator;

	async_await_iterator_t * await_iterator = iterator->await_iterator;
	iterator->await_iterator = NULL;

	await_iterator_dispose(await_iterator);
}

/**
 * This function is called when the iterator coroutine is first entered.
 * It initializes the await_iterator and starts the iteration process.
 *
 * @return void
 */
static void iterator_coroutine_first_entry(void)
{
	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		async_throw_error("Cannot run iterator coroutine");
		return;
	}

	async_await_iterator_t * await_iterator = coroutine->extended_data;
	coroutine->extended_data = NULL;

	ZEND_ASSERT(await_iterator != NULL && "The async_await_iterator_t should not be NULL");
	if (UNEXPECTED(await_iterator == NULL)) {
		async_throw_error("Cannot run concurrent iterator coroutine");
		return;
	}

	async_await_context_t * await_context = await_iterator->await_context;

	if (UNEXPECTED(await_context == NULL)) {
		await_iterator_dispose(await_iterator);
		return;
	}

	 async_await_iterator_iterator_t * iterator = (async_await_iterator_iterator_t *) async_iterator_new(
		NULL,
		await_iterator->zend_iterator,
		NULL,
		await_iterator_handler,
		ZEND_ASYNC_CURRENT_SCOPE,
		await_context->concurrency,
		ZEND_COROUTINE_NORMAL,
		sizeof(async_await_iterator_iterator_t)
	);

	iterator->await_iterator = await_iterator;
	iterator->iterator.extended_dtor = await_iterator_finish_callback;

	if (UNEXPECTED(iterator == NULL)) {
		await_iterator_dispose(await_iterator);
		return;
	}

	async_iterator_run(&iterator->iterator);
	iterator->iterator.microtask.dtor(&iterator->iterator.microtask);
}

/**
 * This callback is triggered when the main iteration coroutine finishes.
 * It’s needed in case the coroutine gets cancelled.
 * In that scenario, extended_data will contain the async_await_iterator_t structure.
 *
 * @param event
 * @param callback
 * @param result
 * @param exception
 */
static void iterator_coroutine_finish_callback(
	zend_async_event_t *event,
	zend_async_event_callback_t *callback,
	void * result,
	zend_object *exception
)
{
	async_await_iterator_t * iterator = (async_await_iterator_t *) ((zend_coroutine_t*) event)->extended_data;

	if (iterator == NULL) {
		return;
	}

	if (exception != NULL) {
		// Resume the waiting coroutine with the exception
		ZEND_ASYNC_RESUME_WITH_ERROR(
			iterator->waiting_coroutine,
			exception,
			false
		);
	} else if (AWAIT_ITERATOR_IS_FINISHED(iterator->await_context)) {
		// If iteration is finished, resume the waiting coroutine
		ZEND_ASYNC_RESUME(iterator->waiting_coroutine);
	}
}

static void async_await_iterator_coroutine_dispose(zend_coroutine_t *coroutine)
{
	if (coroutine == NULL || coroutine->extended_data == NULL) {
		return;
	}

	async_await_iterator_t * await_iterator = (async_await_iterator_t *) coroutine->extended_data;
	coroutine->extended_data = NULL;

	await_iterator_dispose(await_iterator);
}

static void await_context_dtor(async_await_context_t *context)
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

static void async_cancel_awaited_futures(async_await_context_t * await_context, HashTable *futures)
{
	zend_coroutine_t *this_coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(zend_async_waker_new(this_coroutine) == NULL)) {
		return;
	}

	if (futures == NULL) {
		// TODO: Write code to await the coroutine Scope.
		return;
	}

	zend_ulong index;
	zend_string *key;
	zval * current;

	bool not_need_wait = true;

	ZEND_HASH_FOREACH_KEY_VAL(futures, index, key, current) {

		// Handle only the Coroutine objects
		if (Z_TYPE_P(current) != IS_OBJECT
			|| false == instanceof_function(Z_OBJCE_P(current), async_ce_coroutine)) {
			continue;
		}

		zend_async_event_t* awaitable = zval_to_event(current);

		if (UNEXPECTED(EG(exception))) {
			await_context->dtor(await_context);
			return;
		}

		if (awaitable == NULL || ZEND_ASYNC_EVENT_IS_CLOSED(awaitable)) {
			continue;
		}

		async_await_callback_t * callback = ecalloc(1, sizeof(async_await_callback_t));
		callback->callback.base.callback = async_waiting_cancellation_callback;
		callback->await_context = await_context;

		ZEND_ASYNC_EVENT_SET_RESULT_USED(awaitable);
		ZEND_ASYNC_EVENT_SET_EXC_CAUGHT(awaitable);

		if (key != NULL) {
			ZVAL_STR(&callback->key, key);
			zval_add_ref(&callback->key);
		} else {
			ZVAL_LONG(&callback->key, index);
		}

		zend_async_resume_when(this_coroutine, awaitable, false, NULL, &callback->callback);

		if (UNEXPECTED(EG(exception))) {
			await_context->dtor(await_context);
			return;
		}

		not_need_wait = false;

		callback->prev_dispose = callback->callback.base.dispose;
		callback->callback.base.dispose = async_waiting_callback_dispose;
		await_context->ref_count++;

	} ZEND_HASH_FOREACH_END();

	if (not_need_wait) {
		return;
	}

	ZEND_ASYNC_SUSPEND();
}

/**
 * This function is used to await multiple futures concurrently.
 * It takes an iterable of futures, a count of futures to wait for,
 * and various options for handling results and errors.
 *
 * @param iterable The iterable containing futures (array or Traversable object).
 * @param count The number of futures to wait for (0 means all).
 * @param ignore_errors Whether to ignore errors in the futures.
 * @param cancellation Optional cancellation event.
 * @param timeout Timeout for awaiting futures.
 * @param concurrency Maximum number of concurrent futures to await.
 * @param results HashTable to store results.
 * @param errors HashTable to store errors.
 * @param fill_missing_with_null Whether to fill missing results with null.
 * @param cancel_on_exit Whether to cancel awaiting on exit.
 */
void async_await_futures(
	zval *iterable,
	int count,
	bool ignore_errors,
	zend_async_event_t *cancellation,
	zend_ulong timeout,
	unsigned int concurrency,
	HashTable *results,
	HashTable *errors,
	bool fill_missing_with_null,
	bool cancel_on_exit
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
		if (zend_iterator != NULL) {
			zend_iterator_dtor(zend_iterator);
		}
		async_throw_error("Cannot await futures outside of a coroutine");
		return;
	}

	if (UNEXPECTED(zend_async_waker_new_with_timeout(coroutine, timeout, cancellation) == NULL)) {
		if (zend_iterator != NULL) {
			zend_iterator_dtor(zend_iterator);
		}
		return;
	}

	await_context = ecalloc(1, sizeof(async_await_context_t));
	await_context->total = futures != NULL ? (int) zend_hash_num_elements(futures) : 0;
	await_context->futures_count = 0;
	await_context->waiting_count = count > 0 ? count : await_context->total;
	await_context->resolved_count = 0;
	await_context->success_count = 0;
	await_context->ignore_errors = ignore_errors;
	await_context->concurrency = concurrency;
	await_context->fill_missing_with_null = fill_missing_with_null;
	await_context->cancel_on_exit = cancel_on_exit;

	if (false == fill_missing_with_null && AWAIT_ALL(await_context)) {
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
		zval undef_val;
		// The PRT NULL type is used to fill the array with empty elements that will later be removed.
		ZVAL_PTR(&undef_val, NULL);

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

				if (await_context->results != NULL && await_context->fill_missing_with_null) {
					zend_hash_add_empty_element(await_context->results, key);
				} else if (await_context->results != NULL) {
					zend_hash_add(await_context->results, key, &undef_val);
				}

			} else {
				ZVAL_LONG(&callback->key, index);

				if (await_context->results != NULL && await_context->fill_missing_with_null) {
					zend_hash_index_add_empty_element(await_context->results, index);
				} else if (await_context->results != NULL) {
					zend_hash_index_add_new(await_context->results, index, &undef_val);
				}
			}

			zend_async_resume_when(coroutine, awaitable, false, NULL, &callback->callback);

			if (UNEXPECTED(EG(exception))) {
				await_context->dtor(await_context);
				return;
			}

			callback->prev_dispose = callback->callback.base.dispose;
			callback->callback.base.dispose = async_waiting_callback_dispose;
			await_context->ref_count++;

		} ZEND_HASH_FOREACH_END();
	} else {

		// To launch the concurrent iterator,
		// we need a separate coroutine because we're needed to suspend the current one.

		// Coroutines associated with concurrent iteration are created in a child Scope,
		// which ensures that all child tasks are stopped if the main task is cancelled.
		zend_async_scope_t * scope = ZEND_ASYNC_NEW_SCOPE(ZEND_ASYNC_CURRENT_SCOPE);

		if (UNEXPECTED(scope == NULL || EG(exception))) {
			zend_iterator_dtor(zend_iterator);
			await_context->dtor(await_context);
			return;
		}

		zend_coroutine_t * iterator_coroutine = ZEND_ASYNC_SPAWN_WITH(scope);

		if (UNEXPECTED(iterator_coroutine == NULL || EG(exception))) {
			zend_iterator_dtor(zend_iterator);
			await_context->dtor(await_context);
			scope->try_to_dispose(scope);
			return;
		}

		await_context->scope = scope;
		iterator_coroutine->internal_entry = iterator_coroutine_first_entry;

		async_await_iterator_t * iterator = ecalloc(1, sizeof(async_await_iterator_t));
		iterator->zend_iterator = zend_iterator;
		iterator->waiting_coroutine = coroutine;
		iterator->iterator_coroutine = iterator_coroutine;
		iterator->await_context = await_context;

		iterator_coroutine->extended_data = iterator;
		iterator_coroutine->extended_dispose = async_await_iterator_coroutine_dispose;

		zend_async_resume_when(
			coroutine, &iterator_coroutine->event, false, iterator_coroutine_finish_callback, NULL
		);

		if (UNEXPECTED(EG(exception))) {
			// At this point, we don’t free the iterator
			// because it now belongs to the coroutine and must be destroyed there.
			return;
		}
	}

	ZEND_ASYNC_SUSPEND();

	// If the await on futures has completed and
	// the automatic cancellation mode for pending coroutines is active.
	if (await_context->cancel_on_exit) {
		async_cancel_awaited_futures(await_context, futures);
	}

	// Remove all undefined buckets from the results array.
	if (tmp_results != NULL) {

		// foreach results as key => value
		// if value is PTR then continue
		ZEND_HASH_FOREACH_KEY_VAL(tmp_results, index, key, current) {
			if (Z_TYPE_P(current) == IS_PTR && Z_PTR_P(current) == NULL) {
				continue;
			}

			if (key != NULL) {
				if (EXPECTED(zend_hash_update(results, key, current) != NULL)) {
					zval_add_ref(current);
				}
			} else {
				if (EXPECTED(zend_hash_index_update(results, index, current) != NULL)) {
					zval_add_ref(current);
				}
			}
		} ZEND_HASH_FOREACH_END();

		await_context->results = NULL;
		zend_array_release(tmp_results);
	}

	await_context->dtor(await_context);
}

void async_api_register(void)
{
	zend_async_scheduler_register(
		PHP_ASYNC_NAME_VERSION,
		false,
		async_new_coroutine,
		async_new_scope,
		(zend_async_new_context_t)async_context_new,
		spawn,
		async_coroutine_suspend,
		async_scheduler_coroutine_enqueue,
		async_coroutine_resume,
		async_coroutine_cancel,
		async_spawn_and_throw,
		start_graceful_shutdown,
		get_coroutines,
		add_microtask,
		get_awaiting_info,
		async_get_class_ce,
		(zend_async_new_iterator_t)async_iterator_new,
		engine_shutdown
	);
}