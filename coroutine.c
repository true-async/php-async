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

///////////////////////////////////////////////////////////
/// 1. Headers, Constants, and Declarations
///////////////////////////////////////////////////////////

#include "coroutine.h"

#include "context.h"
#include "coroutine_arginfo.h"
#include "exceptions.h"
#include "iterator.h"
#include "php_async.h"

#include "scheduler.h"
#include "scope.h"
#include "zend_common.h"
#include "zend_exceptions.h"
#include "zend_generators.h"
#include "zend_ini.h"

#define METHOD(name) PHP_METHOD(Async_Coroutine, name)
#define THIS_COROUTINE ((async_coroutine_t *) ZEND_ASYNC_OBJECT_TO_EVENT(Z_OBJ_P(ZEND_THIS)))

zend_class_entry *async_ce_coroutine = NULL;

static zend_object_handlers coroutine_handlers;

// Forward declarations for internal functions
static void coroutine_call_finally_handlers(async_coroutine_t *coroutine);
static void finally_context_dtor(finally_handlers_context_t *context);

// Forward declarations for event system
static void coroutine_event_start(zend_async_event_t *event);
static void coroutine_event_stop(zend_async_event_t *event);
static void coroutine_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback);
static void coroutine_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback);
static bool coroutine_replay(zend_async_event_t *event,
							 zend_async_event_callback_t *callback,
							 zval *result,
							 zend_object **exception);
static zend_string *coroutine_info(zend_async_event_t *event);
static void coroutine_dispose(zend_async_event_t *event);

///////////////////////////////////////////////////////////
/// 2. Object Lifecycle Management
///////////////////////////////////////////////////////////

static zend_object *coroutine_object_create(zend_class_entry *class_entry)
{
	async_coroutine_t *coroutine = zend_object_alloc(sizeof(async_coroutine_t), class_entry);

	ZVAL_UNDEF(&coroutine->coroutine.result);

	ZEND_ASYNC_EVENT_SET_ZEND_OBJ(&coroutine->coroutine.event);
	ZEND_ASYNC_EVENT_SET_NO_FREE_MEMORY(&coroutine->coroutine.event);
	ZEND_ASYNC_EVENT_SET_ZEND_OBJ_OFFSET(&coroutine->coroutine.event, XtOffsetOf(async_coroutine_t, std));

	/* Initialize embedded waker */
	coroutine->coroutine.waker = &coroutine->waker;

	/* Initialize waker contents (memory is already zeroed by zend_object_alloc) */
	zend_async_waker_init(&coroutine->waker);

	/* Initialize switch handlers */
	coroutine->coroutine.switch_handlers = NULL;

	zend_async_event_t *event = &coroutine->coroutine.event;

	event->start = coroutine_event_start;
	event->stop = coroutine_event_stop;
	event->add_callback = coroutine_add_callback;
	event->del_callback = coroutine_del_callback;
	event->replay = coroutine_replay;
	event->info = coroutine_info;
	event->dispose = coroutine_dispose;

	coroutine->coroutine.extended_data = NULL;
	coroutine->finally_handlers = NULL;

	zend_object_std_init(&coroutine->std, class_entry);
	object_properties_init(&coroutine->std, class_entry);

	return &coroutine->std;
}

static void coroutine_object_destroy(zend_object *object)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_OBJECT_TO_EVENT(object);

	ZEND_ASSERT(ZEND_ASYNC_WAKER_NOT_IN_QUEUE(&coroutine->waker) &&
				"Coroutine waker must be dequeued before destruction");

	if (coroutine->coroutine.scope != NULL) {
		async_scope_notify_coroutine_finished(coroutine);
		coroutine->coroutine.scope = NULL;
	}

	if (coroutine->coroutine.fcall) {

		zend_fcall_t *fcall = coroutine->coroutine.fcall;
		coroutine->coroutine.fcall = NULL;

		if (fcall->fci.param_count) {
			for (uint32_t i = 0; i < fcall->fci.param_count; i++) {
				zval_ptr_dtor(&fcall->fci.params[i]);
			}

			efree(fcall->fci.params);
		}

		if (fcall->fci.named_params) {
			GC_DELREF(fcall->fci.named_params);
			fcall->fci.named_params = NULL;
		}

		zval_ptr_dtor(&fcall->fci.function_name);
		efree(fcall);
	}

	if (coroutine->coroutine.context != NULL) {
		// If the coroutine has a context, we need to release it.
		async_context_t *context = (async_context_t *) coroutine->coroutine.context;
		coroutine->coroutine.context = NULL;
		async_context_dispose(context);
	}

	if (coroutine->coroutine.filename) {
		zend_string_release_ex(coroutine->coroutine.filename, 0);
		coroutine->coroutine.filename = NULL;
	}

	// Cleanup embedded waker contents
	zend_async_waker_destroy(&coroutine->coroutine);
	coroutine->coroutine.waker = NULL;

	if (coroutine->coroutine.internal_context != NULL) {
		zend_async_coroutine_internal_context_dispose(&coroutine->coroutine);
	}

	zval_ptr_dtor(&coroutine->coroutine.result);
	ZVAL_UNDEF(&coroutine->coroutine.result);

	if (coroutine->coroutine.exception != NULL) {
		// If the coroutine has an exception, we need to release it.

		zend_object *exception = coroutine->coroutine.exception;
		coroutine->coroutine.exception = NULL;
		OBJ_RELEASE(exception);
	}

	if (coroutine->deferred_cancellation != NULL) {
		zend_object *deferred_cancellation = coroutine->deferred_cancellation;
		coroutine->deferred_cancellation = NULL;
		OBJ_RELEASE(deferred_cancellation);
	}

	if (coroutine->finally_handlers) {
		zend_array_destroy(coroutine->finally_handlers);
		coroutine->finally_handlers = NULL;
	}
}

static void coroutine_free(zend_object *object)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_OBJECT_TO_EVENT(object);

	zend_async_callbacks_free(&coroutine->coroutine.event);
	zend_object_std_dtor(object);
}

static HashTable *async_coroutine_object_gc(zend_object *object, zval **table, int *num)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_OBJECT_TO_EVENT(object);
	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();

	/* Always add basic ZVALs from coroutine structure */
	zend_get_gc_buffer_add_zval(buf, &coroutine->coroutine.result);

	/* Add objects that may be present */
	if (coroutine->coroutine.exception) {
		zend_get_gc_buffer_add_obj(buf, coroutine->coroutine.exception);
	}

	if (coroutine->deferred_cancellation) {
		zend_get_gc_buffer_add_obj(buf, coroutine->deferred_cancellation);
	}

	/* Add finally handlers if present */
	if (coroutine->finally_handlers) {
		zval *val;
		ZEND_HASH_FOREACH_VAL(coroutine->finally_handlers, val)
		{
			zend_get_gc_buffer_add_zval(buf, val);
		}
		ZEND_HASH_FOREACH_END();
	}

	/* Add internal context HashTable if present */
	if (coroutine->coroutine.internal_context) {
		zval *val;
		ZEND_HASH_FOREACH_VAL(coroutine->coroutine.internal_context, val)
		{
			zend_get_gc_buffer_add_zval(buf, val);
		}
		ZEND_HASH_FOREACH_END();
	}

	/* Add fcall function name and parameters if present */
	if (coroutine->coroutine.fcall) {
		zend_get_gc_buffer_add_zval(buf, &coroutine->coroutine.fcall->fci.function_name);

		/* Add function parameters */
		if (coroutine->coroutine.fcall->fci.param_count > 0 && coroutine->coroutine.fcall->fci.params) {
			for (uint32_t i = 0; i < coroutine->coroutine.fcall->fci.param_count; i++) {
				zend_get_gc_buffer_add_zval(buf, &coroutine->coroutine.fcall->fci.params[i]);
			}
		}
	}

	/* Add waker-related ZVALs if present */
	if (coroutine->coroutine.waker) {
		zend_get_gc_buffer_add_zval(buf, &coroutine->waker.result);

		if (coroutine->waker.error) {
			zend_get_gc_buffer_add_obj(buf, coroutine->waker.error);
		}

		/* Add events HashTable contents */
		zend_async_event_t *event;
		zval zval_object;
		ZEND_HASH_FOREACH_PTR(&coroutine->waker.events, event)
		{
			if (ZEND_ASYNC_EVENT_IS_REFERENCE(event) || ZEND_ASYNC_EVENT_IS_ZEND_OBJ(event)) {
				ZVAL_OBJ(&zval_object, ZEND_ASYNC_EVENT_TO_OBJECT(event));
				zend_get_gc_buffer_add_zval(buf, &zval_object);
			}
		}
		ZEND_HASH_FOREACH_END();

		zval *event_val;
		/* Add triggered events if present */
		if (coroutine->waker.triggered_events) {
			ZEND_HASH_FOREACH_VAL(coroutine->waker.triggered_events, event_val)
			{
				zend_get_gc_buffer_add_zval(buf, event_val);
			}
			ZEND_HASH_FOREACH_END();
		}
	}

	/* Add context ZVALs if present */
	if (coroutine->coroutine.context) {
		/* Cast to actual context implementation to access HashTables */
		async_context_t *context = (async_context_t *) coroutine->coroutine.context;

		/* Add all values from context->values HashTable */
		zval *val;
		ZEND_HASH_FOREACH_VAL(&context->values, val)
		{
			zend_get_gc_buffer_add_zval(buf, val);
		}
		ZEND_HASH_FOREACH_END();

		/* Add all object keys from context->keys HashTable */
		ZEND_HASH_FOREACH_VAL(&context->keys, val)
		{
			zend_get_gc_buffer_add_zval(buf, val);
		}
		ZEND_HASH_FOREACH_END();
	}

	async_fiber_context_t *fiber_context = coroutine->fiber_context;

	/* Check if we should traverse execution stack (similar to fibers) */
	if (fiber_context == NULL ||
		(fiber_context->context.status != ZEND_FIBER_STATUS_SUSPENDED || !fiber_context->execute_data)) {
		zend_get_gc_buffer_use(buf, table, num);
		return NULL;
	}

	/* Traverse execution stack for suspended coroutines */
	HashTable *lastSymTable = NULL;
	zend_execute_data *ex = fiber_context->execute_data;
	for (; ex; ex = ex->prev_execute_data) {
		HashTable *symTable;
		if (ZEND_CALL_INFO(ex) & ZEND_CALL_GENERATOR) {
			zend_generator *generator = (zend_generator *) ex->return_value;
			if (!(generator->flags & ZEND_GENERATOR_CURRENTLY_RUNNING)) {
				continue;
			}
			symTable = zend_generator_frame_gc(buf, generator);
		} else {
			symTable = zend_unfinished_execution_gc_ex(
					ex, ex->func && ZEND_USER_CODE(ex->func->type) ? ex->call : NULL, buf, false);
		}
		if (symTable) {
			if (lastSymTable) {
				zval *val;
				ZEND_HASH_FOREACH_VAL(lastSymTable, val)
				{
					if (EXPECTED(Z_TYPE_P(val) == IS_INDIRECT)) {
						val = Z_INDIRECT_P(val);
					}
					zend_get_gc_buffer_add_zval(buf, val);
				}
				ZEND_HASH_FOREACH_END();
			}
			lastSymTable = symTable;
		}
	}

	zend_get_gc_buffer_use(buf, table, num);
	return lastSymTable;
}

zend_coroutine_t *async_new_coroutine(zend_async_scope_t *scope)
{
	zend_object *object = coroutine_object_create(async_ce_coroutine);

	if (UNEXPECTED(EG(exception))) {
		return NULL;
	}

	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_OBJECT_TO_EVENT(object);
	coroutine->coroutine.scope = scope;

	return &coroutine->coroutine;
}

void async_register_coroutine_ce(void)
{
	async_ce_coroutine = register_class_Async_Coroutine(async_ce_awaitable);

	async_ce_coroutine->create_object = coroutine_object_create;

	async_ce_coroutine->default_object_handlers = &coroutine_handlers;

	coroutine_handlers = std_object_handlers;
	coroutine_handlers.offset = XtOffsetOf(async_coroutine_t, std);
	coroutine_handlers.clone_obj = NULL;
	coroutine_handlers.dtor_obj = coroutine_object_destroy;
	coroutine_handlers.free_obj = coroutine_free;
	coroutine_handlers.get_gc = async_coroutine_object_gc;
}

///////////////////////////////////////////////////////////
/// 3. Core Coroutine State Management
///////////////////////////////////////////////////////////

ZEND_STACK_ALIGNED void async_coroutine_execute(async_coroutine_t *coroutine)
{
	bool should_start_graceful_shutdown = false;
	bool is_bailout = false;

	zend_async_waker_t *waker = coroutine->coroutine.waker;

	if (UNEXPECTED(waker == NULL || waker->status == ZEND_ASYNC_WAKER_IGNORED)) {

		if (ZEND_COROUTINE_IS_CANCELLED(&coroutine->coroutine)) {
			zend_try
			{
				if (EXPECTED(waker != NULL)) {
					waker->status = ZEND_ASYNC_WAKER_RESULT;
					zend_object *error = waker->error;

					// Transfer error from the Waker to current context if it exists.
					if (UNEXPECTED(error)) {
						waker->error = NULL;
						async_rethrow_exception(error);
					}
				}

				async_coroutine_finalize(coroutine);
			}
			zend_catch
			{
				should_start_graceful_shutdown = true;
			}
			zend_end_try();
		}

		coroutine->coroutine.event.dispose(&coroutine->coroutine.event);

		if (EXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == &coroutine->coroutine)) {
			ZEND_ASYNC_CURRENT_COROUTINE = NULL;
		}

		return;
	}

	if (UNEXPECTED(waker->status == ZEND_ASYNC_WAKER_WAITING)) {
		zend_error(E_ERROR, "Attempt to resume a coroutine that has not been resolved");
		coroutine->coroutine.event.dispose(&coroutine->coroutine.event);

		if (EXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == &coroutine->coroutine)) {
			ZEND_ASYNC_CURRENT_COROUTINE = NULL;
		}

		return;
	}

	waker->status = ZEND_ASYNC_WAKER_RESULT;
	zend_object *error = waker->error;

	// The Waker object can be destroyed immediately if the result is an error.
	// It will be delivered to the coroutine as an exception.
	if (UNEXPECTED(error)) {
		waker->error = NULL;
		async_rethrow_exception(error);
	}

	zend_async_waker_clean(&coroutine->coroutine);

	ZEND_COROUTINE_SET_STARTED(&coroutine->coroutine);

	zend_try
	{
		if (EXPECTED(coroutine->coroutine.internal_entry == NULL)) {
			ZEND_ASSERT(coroutine->coroutine.fcall != NULL && "Coroutine function call is not set");
			coroutine->coroutine.fcall->fci.retval = &coroutine->coroutine.result;

			zend_call_function(&coroutine->coroutine.fcall->fci, &coroutine->coroutine.fcall->fci_cache);

			zval_ptr_dtor(&coroutine->coroutine.fcall->fci.function_name);
			ZVAL_UNDEF(&coroutine->coroutine.fcall->fci.function_name);
			coroutine->coroutine.fcall->fci.retval = NULL;
		} else {
			coroutine->coroutine.internal_entry();
		}
	}
	zend_catch
	{
		should_start_graceful_shutdown = true;
		is_bailout = true;
	}
	zend_end_try();

	zend_try
	{
		async_coroutine_finalize(coroutine);
		OBJ_RELEASE(&coroutine->std);
	}
	zend_catch
	{
		should_start_graceful_shutdown = true;
		is_bailout = true;
	}
	zend_end_try();

	if (EXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == &coroutine->coroutine)) {
		ZEND_ASYNC_CURRENT_COROUTINE = NULL;
	}

	if (UNEXPECTED(should_start_graceful_shutdown)) {
		zend_try
		{
			ZEND_ASYNC_SHUTDOWN();
		}
		zend_catch
		{
			zend_error(E_CORE_WARNING,
					   "A critical error was detected during the initiation of the graceful shutdown mode.");
			zend_bailout();
		}
		zend_end_try();
	}

	if (is_bailout) {
		zend_bailout();
	}
}

void async_coroutine_finalize(async_coroutine_t *coroutine)
{
	// Before finalizing the coroutine
	// we check that we're properly finishing the coroutine's execution.
	// The coroutine must not be in the queue!
	if (UNEXPECTED(ZEND_ASYNC_WAKER_IN_QUEUE(coroutine->coroutine.waker))) {
		zend_error(E_CORE_WARNING, "Attempt to finalize a coroutine that is still in the queue");
	}

	ZEND_COROUTINE_SET_FINISHED(&coroutine->coroutine);

	/* Call switch handlers for coroutine finishing */
	if (UNEXPECTED(coroutine->coroutine.switch_handlers)) {
		ZEND_COROUTINE_FINISH(&coroutine->coroutine);
	}

	bool do_bailout = false;
	zend_object **exception_ptr = &EG(exception);
	zend_object **prev_exception_ptr = &EG(prev_exception);

	zend_try
	{

		/* Cleanup switch handlers */
		zend_coroutine_switch_handlers_destroy(&coroutine->coroutine);

		// call coroutines handlers
		zend_object *exception = NULL;

		if (UNEXPECTED(*exception_ptr)) {
			if (*prev_exception_ptr) {
				zend_exception_set_previous(*exception_ptr, *prev_exception_ptr);
				*prev_exception_ptr = NULL;
			}

			exception = *exception_ptr;
			GC_ADDREF(exception);

			zend_clear_exception();

			if (zend_is_graceful_exit(exception) || zend_is_unwind_exit(exception)) {
				OBJ_RELEASE(exception);
				exception = NULL;
			}
		}

		// Hold the exception inside coroutine if it is not NULL.
		if (exception != NULL) {
			if (coroutine->coroutine.exception != NULL) {
				if (false == instanceof_function(exception->ce, ZEND_ASYNC_GET_CE(ZEND_ASYNC_EXCEPTION_CANCELLATION))) {
					zend_exception_set_previous(exception, coroutine->coroutine.exception);
					coroutine->coroutine.exception = exception;
					GC_ADDREF(exception);
				}
			} else {
				coroutine->coroutine.exception = exception;
				GC_ADDREF(exception);
			}
		} else if (coroutine->coroutine.exception != NULL) {
			// If the coroutine has an exception, we keep it.
			exception = coroutine->coroutine.exception;
			GC_ADDREF(exception);
		}

		zend_exception_save_fast(exception_ptr, prev_exception_ptr);
		// Mark second parameter of zend_async_callbacks_notify as ZVAL
		ZEND_ASYNC_EVENT_SET_ZVAL_RESULT(&coroutine->coroutine.event);
		ZEND_COROUTINE_CLR_EXCEPTION_HANDLED(&coroutine->coroutine);
		ZEND_ASYNC_CALLBACKS_NOTIFY(&coroutine->coroutine.event, &coroutine->coroutine.result, exception);
		zend_async_callbacks_free(&coroutine->coroutine.event);

		if (coroutine->coroutine.internal_context != NULL) {
			zend_async_coroutine_internal_context_dispose(&coroutine->coroutine);
		}

		// Call finally handlers if any
		if (coroutine->finally_handlers != NULL && zend_hash_num_elements(coroutine->finally_handlers) > 0) {
			coroutine_call_finally_handlers(coroutine);
		}

		zend_async_waker_destroy(&coroutine->coroutine);

		if (coroutine->coroutine.extended_dispose != NULL) {
			const zend_async_coroutine_dispose dispose = coroutine->coroutine.extended_dispose;
			coroutine->coroutine.extended_dispose = NULL;
			dispose(&coroutine->coroutine);
		}

		zend_exception_restore_fast(exception_ptr, prev_exception_ptr);

		// If the exception was handled by any handler, we do not propagate it further.
		// Cancellation-type exceptions are considered handled in all cases and are not propagated further.
		if (exception != NULL &&
			(ZEND_COROUTINE_IS_EXCEPTION_HANDLED(&coroutine->coroutine) ||
			 instanceof_function(exception->ce, ZEND_ASYNC_GET_CE(ZEND_ASYNC_EXCEPTION_CANCELLATION)))) {
			OBJ_RELEASE(exception);
			exception = NULL;
		}

		// Before the exception leads to graceful termination,
		// we give one last chance to handle it using Scope handlers.
		if (exception != NULL &&
			ZEND_ASYNC_SCOPE_CATCH(coroutine->coroutine.scope,
								   &coroutine->coroutine,
								   NULL,
								   exception,
								   false,
								   ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(coroutine->coroutine.scope))) {
			OBJ_RELEASE(exception);
			exception = NULL;
		}

		// Notify the async scope that the coroutine has finished.
		// For the Scheduler, the coroutine's Scope may be undefined.
		if (EXPECTED(coroutine->coroutine.scope != NULL)) {
			async_scope_notify_coroutine_finished(coroutine);
			coroutine->coroutine.scope = NULL;
		}

		// Otherwise, we rethrow the exception.
		if (exception != NULL) {
			async_rethrow_exception(exception);
		}
	}
	zend_catch
	{
		do_bailout = true;
	}
	zend_end_try();

	if (UNEXPECTED(*exception_ptr && (zend_is_graceful_exit(*exception_ptr) || zend_is_unwind_exit(*exception_ptr)))) {
		zend_clear_exception();
	}

	if (EXPECTED(ZEND_ASYNC_SCHEDULER != &coroutine->coroutine)) {
		// Permanently remove the coroutine from the Scheduler.
		if (UNEXPECTED(zend_hash_index_del(&ASYNC_G(coroutines), coroutine->std.handle) == FAILURE)) {
			zend_error(E_CORE_ERROR, "Failed to remove coroutine from the list");
		}

		// Decrease the active coroutine count if the coroutine is not a zombie.
		if (false == ZEND_COROUTINE_IS_ZOMBIE(&coroutine->coroutine)) {
			ZEND_ASYNC_DECREASE_COROUTINE_COUNT
		}
	}

	coroutine->fiber_context = NULL;

	if (UNEXPECTED(do_bailout)) {
		zend_bailout();
	}
}

/**
 * The function suspends the execution of the coroutine and triggers a switch to another one.
 * After calling this function, you must properly clean up the coroutine waker object
 * (example zend_async_waker_clean).
 *
 * @param from_main For main coroutine
 */
void async_coroutine_suspend(const bool from_main)
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

	async_scheduler_coroutine_suspend();
}

void async_coroutine_resume(zend_coroutine_t *coroutine, zend_object *error, const bool transfer_error)
{
	if (UNEXPECTED(coroutine->waker == NULL || coroutine->waker->status == ZEND_ASYNC_WAKER_NO_STATUS)) {
		async_throw_error("Cannot resume a coroutine that has not been suspended");
		return;
	}

	if (error != NULL) {
		if (coroutine->waker->error != NULL) {

			if (false == instanceof_function(error->ce, ZEND_ASYNC_GET_CE(ZEND_ASYNC_EXCEPTION_CANCELLATION))) {
				zend_exception_set_previous(error, coroutine->waker->error);
				coroutine->waker->error = error;

				if (false == transfer_error) {
					GC_ADDREF(error);
				}
			} else {
				if (transfer_error) {
					OBJ_RELEASE(error);
				}
			}
		} else {
			coroutine->waker->error = error;

			if (false == transfer_error) {
				GC_ADDREF(error);
			}
		}
	}

	if (UNEXPECTED(coroutine->waker->status == ZEND_ASYNC_WAKER_QUEUED)) {
		return;
	}

	if (UNEXPECTED(circular_buffer_push_ptr_with_resize(&ASYNC_G(coroutine_queue), coroutine)) == FAILURE) {
		async_throw_error("Failed to enqueue coroutine");
		return;
	}

	coroutine->waker->status = ZEND_ASYNC_WAKER_QUEUED;

	// Add to resumed_coroutines queue for event cleanup
	if (ZEND_ASYNC_IS_SCHEDULER_CONTEXT) {
		circular_buffer_push_ptr_with_resize(&ASYNC_G(resumed_coroutines), coroutine);
	}
}

void async_coroutine_cancel(zend_coroutine_t *zend_coroutine,
							zend_object *error,
							bool transfer_error,
							const bool is_safely)
{
	// If the coroutine finished, do nothing.
	if (ZEND_COROUTINE_IS_FINISHED(zend_coroutine)) {
		if (transfer_error && error != NULL) {
			OBJ_RELEASE(error);
		}

		return;
	}

	// An attempt to cancel a coroutine that is currently running.
	// In this case, nothing actually happens immediately;
	// however, the coroutine is marked as having been cancelled,
	// and the cancellation exception is stored as its result.
	if (UNEXPECTED(zend_coroutine == ZEND_ASYNC_CURRENT_COROUTINE)) {

		ZEND_COROUTINE_SET_CANCELLED(zend_coroutine);

		if (zend_coroutine->exception == NULL) {
			zend_coroutine->exception = error;

			if (false == transfer_error) {
				GC_ADDREF(error);
			}
		}

		if (zend_coroutine->exception == NULL) {
			zend_coroutine->exception = async_new_exception(async_ce_cancellation_exception, "Coroutine cancelled");
		}

		return;
	}

	zend_async_waker_t *waker = zend_async_waker_define(zend_coroutine);

	const bool is_error_null = (error == NULL);

	if (is_error_null) {
		error = async_new_exception(async_ce_cancellation_exception, "Coroutine cancelled");
		transfer_error = true;
		if (UNEXPECTED(EG(exception))) {
			return;
		}
	}

	// If the coroutine is currently protected from cancellation, defer the cancellation.
	if (ZEND_COROUTINE_IS_PROTECTED(zend_coroutine)) {
		async_coroutine_t *coroutine = (async_coroutine_t *) zend_coroutine;

		if (coroutine->deferred_cancellation == NULL) {
			coroutine->deferred_cancellation = error;

			if (false == transfer_error) {
				GC_ADDREF(error);
			}
		} else if (transfer_error) {
			OBJ_RELEASE(error);
		}

		return;
	}

	bool was_cancelled = ZEND_COROUTINE_IS_CANCELLED(zend_coroutine);
	ZEND_COROUTINE_SET_CANCELLED(zend_coroutine);

	if (false == ZEND_COROUTINE_IS_STARTED(zend_coroutine)) {

		if (false == ZEND_ASYNC_WAKER_IN_QUEUE(waker)) {
			//
			// Situation: the coroutine is not in the queue, but a cancellation is requested.
			// It might seem like we can simply remove the coroutine,
			// but doing so would break the flow of the coroutine's handlers.
			// Therefore, to normalize the flow,
			// we place the coroutine in the queue with a status of ignored,
			// so that the flow is executed correctly.
			//
			async_scheduler_coroutine_enqueue(zend_coroutine);
		}

		waker->status = ZEND_ASYNC_WAKER_IGNORED;

		//
		// Exception override:
		// If the coroutine already has an exception
		// and it's a cancellation exception, then nothing needs to be done.
		// In any other case, the cancellation exception overrides the existing exception.
		//
		ZEND_ASYNC_WAKER_APPLY_CANCELLATION(waker, error, transfer_error);
		async_scheduler_coroutine_enqueue(zend_coroutine);
		return;
	}

	// In safely mode, we don't forcibly terminate the coroutine,
	// but we do mark it as a Zombie.
	if (is_safely) {
		async_scope_mark_coroutine_zombie((async_coroutine_t *) zend_coroutine);
		ZEND_ASYNC_DECREASE_COROUTINE_COUNT
		if (transfer_error && error != NULL) {
			OBJ_RELEASE(error);
		}
		return;
	}

	if (was_cancelled && waker->error != NULL &&
		instanceof_function(waker->error->ce, ZEND_ASYNC_GET_CE(ZEND_ASYNC_EXCEPTION_CANCELLATION))) {
		if (transfer_error) {
			OBJ_RELEASE(error);
		}
	} else {
		ZEND_ASYNC_WAKER_APPLY_CANCELLATION(waker, error, transfer_error);
	}

	async_scheduler_coroutine_enqueue(zend_coroutine);
}

///////////////////////////////////////////////////////////
/// 4. Event System Interface
///////////////////////////////////////////////////////////

static void coroutine_event_start(zend_async_event_t *event)
{
}

static void coroutine_event_stop(zend_async_event_t *event)
{
}

static void coroutine_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	zend_async_callbacks_push(event, callback);
}

static void coroutine_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	zend_async_callbacks_remove(event, callback);
}

/**
 * The method allows you to retrieve the results of a coroutine once it has completed.
 * The method can only be called if the coroutine has completed; otherwise, it does nothing.
 *
 * @param event The coroutine event to replay.
 * @param callback The callback to call with the result and exception (can be NULL).
 * @param result The result to copy into, if not NULL.
 * @param exception The exception to set, if not NULL.
 */
static bool coroutine_replay(zend_async_event_t *event,
							 zend_async_event_callback_t *callback,
							 zval *result,
							 zend_object **exception)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) event;

	if (UNEXPECTED(false == ZEND_COROUTINE_IS_FINISHED(&coroutine->coroutine))) {
		ZEND_ASSERT("Cannot replay coroutine, because the coroutine is not finished");
		return false;
	}

	if (callback != NULL) {
		callback->callback(event, callback, &coroutine->coroutine.result, coroutine->coroutine.exception);
		return true;
	}

	if (result != NULL) {
		ZVAL_COPY(result, &coroutine->coroutine.result);
	}

	if (exception == NULL && coroutine->coroutine.exception != NULL) {
		GC_ADDREF(coroutine->coroutine.exception);
		async_rethrow_exception(coroutine->coroutine.exception);
	} else if (exception != NULL && coroutine->coroutine.exception != NULL) {
		*exception = coroutine->coroutine.exception;
		GC_ADDREF(*exception);
	}

	return coroutine->coroutine.exception != NULL || Z_TYPE(coroutine->coroutine.result) != IS_UNDEF;
}

static zend_string *coroutine_info(zend_async_event_t *event)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) event;

	zend_string *zend_coroutine_name = zend_coroutine_callable_name(&coroutine->coroutine);

	if (ZEND_COROUTINE_SUSPENDED(&coroutine->coroutine)) {
		return zend_strpprintf(0,
							   "Coroutine %d spawned at %s:%d, suspended at %s:%d (%s)",
							   coroutine->std.handle,
							   coroutine->coroutine.filename ? ZSTR_VAL(coroutine->coroutine.filename) : "",
							   coroutine->coroutine.lineno,
							   coroutine->waker.filename ? ZSTR_VAL(coroutine->waker.filename) : "",
							   coroutine->waker.lineno,
							   ZSTR_VAL(zend_coroutine_name));
	} else {
		return zend_strpprintf(0,
							   "Coroutine %d spawned at %s:%d (%s)",
							   coroutine->std.handle,
							   coroutine->coroutine.filename ? ZSTR_VAL(coroutine->coroutine.filename) : "",
							   coroutine->coroutine.lineno,
							   ZSTR_VAL(zend_coroutine_name));
	}
}

static void coroutine_dispose(zend_async_event_t *event)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) event;
	OBJ_RELEASE(&coroutine->std);
}

///////////////////////////////////////////////////////////
/// 5. Context Management API
///////////////////////////////////////////////////////////

bool async_coroutine_context_set(zend_coroutine_t *z_coroutine, zval *key, zval *value)
{
	async_coroutine_t *coroutine =
			(async_coroutine_t *) (z_coroutine != NULL ? z_coroutine : ZEND_ASYNC_CURRENT_COROUTINE);

	if (UNEXPECTED(coroutine == NULL || coroutine->coroutine.context == NULL)) {
		return false;
	}

	coroutine->coroutine.context->set(coroutine->coroutine.context, key, value);
	return true;
}

bool async_coroutine_context_get(zend_coroutine_t *z_coroutine, zval *key, zval *result)
{
	async_coroutine_t *coroutine =
			(async_coroutine_t *) (z_coroutine != NULL ? z_coroutine : ZEND_ASYNC_CURRENT_COROUTINE);

	if (UNEXPECTED(coroutine == NULL || coroutine->coroutine.context == NULL)) {
		if (result != NULL) {
			ZVAL_NULL(result);
		}
		return false;
	}

	return coroutine->coroutine.context->find(coroutine->coroutine.context, key, result, false);
}

bool async_coroutine_context_has(zend_coroutine_t *z_coroutine, zval *key)
{
	async_coroutine_t *coroutine =
			(async_coroutine_t *) (z_coroutine != NULL ? z_coroutine : ZEND_ASYNC_CURRENT_COROUTINE);

	if (UNEXPECTED(coroutine == NULL || coroutine->coroutine.context == NULL)) {
		return false;
	}

	return coroutine->coroutine.context->find(coroutine->coroutine.context, key, NULL, false);
}

bool async_coroutine_context_delete(zend_coroutine_t *z_coroutine, zval *key)
{
	async_coroutine_t *coroutine =
			(async_coroutine_t *) (z_coroutine != NULL ? z_coroutine : ZEND_ASYNC_CURRENT_COROUTINE);

	if (UNEXPECTED(coroutine == NULL || coroutine->coroutine.context == NULL)) {
		return false;
	}

	return coroutine->coroutine.context->unset(coroutine->coroutine.context, key);
}

///////////////////////////////////////////////////////////
/// 6. Finally Handler System
///////////////////////////////////////////////////////////

static zend_result finally_handlers_iterator_handler(async_iterator_t *iterator, zval *current, zval *key)
{
	finally_handlers_context_t *context = (finally_handlers_context_t *) iterator->extended_data;
	zval rv;
	ZVAL_UNDEF(&rv);
	call_user_function(NULL, NULL, current, &rv, context->params_count, context->params);
	zval_ptr_dtor(&rv);
	ZVAL_UNDEF(&rv);

	zend_object **exception_ptr = &EG(exception);

	// Check for exceptions after handler execution
	if (UNEXPECTED(*exception_ptr)) {
		zend_object **prev_exception_ptr = &EG(prev_exception);
		zend_exception_save_fast(exception_ptr, prev_exception_ptr);
		zend_exception_restore_fast(exception_ptr, prev_exception_ptr);
		zend_object *current_exception = *exception_ptr;
		GC_ADDREF(current_exception);
		zend_clear_exception();

		// Check for graceful/unwind exit exceptions
		if (zend_is_graceful_exit(current_exception) || zend_is_unwind_exit(current_exception)) {
			// Release CompositeException if exists
			if (context->composite_exception) {
				OBJ_RELEASE(context->composite_exception);
				context->composite_exception = NULL;
			}
			// Throw graceful/unwind exit and stop iteration
			async_rethrow_exception(current_exception);
			return SUCCESS;
		}

		// Handle regular exceptions
		if (context->composite_exception == NULL) {
			context->composite_exception = current_exception;
		} else if (!instanceof_function(context->composite_exception->ce, async_ce_composite_exception)) {
			// Create CompositeException and add first exception
			zend_object *composite_exception = async_new_composite_exception();
			if (UNEXPECTED(composite_exception == NULL)) {
				// If we can't create CompositeException, throw the current one
				async_rethrow_exception(current_exception);
				return SUCCESS;
			}

			async_composite_exception_add_exception(composite_exception, context->composite_exception, true);
			async_composite_exception_add_exception(composite_exception, current_exception, true);
			context->composite_exception = composite_exception;
		} else {
			// Add exception to existing CompositeException
			async_composite_exception_add_exception(context->composite_exception, current_exception, true);
		}
	}

	return SUCCESS;
}

static void finally_handlers_iterator_dtor(zend_async_iterator_t *zend_iterator)
{
	async_iterator_t *iterator = (async_iterator_t *) zend_iterator;

	if (UNEXPECTED(iterator->extended_data == NULL)) {
		return;
	}

	finally_handlers_context_t *context = iterator->extended_data;
	async_scope_t *scope = (async_scope_t *) context->scope;
	context->scope = NULL;

	// Throw CompositeException if any exceptions were collected
	if (context->composite_exception != NULL) {
		if (ZEND_ASYNC_SCOPE_CATCH(&scope->scope,
								   &context->coroutine->coroutine,
								   NULL,
								   context->composite_exception,
								   false,
								   ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(&scope->scope))) {
			OBJ_RELEASE(context->composite_exception);
			context->composite_exception = NULL;
		}
	}

	zend_object *composite_exception = context->composite_exception;
	context->composite_exception = NULL;

	if (context->dtor != NULL) {
		context->dtor(context);
		context->dtor = NULL;
	}

	// Free the context
	efree(context);
	iterator->extended_data = NULL;

	if (ZEND_ASYNC_EVENT_REFCOUNT(&scope->scope.event) > 0) {
		ZEND_ASYNC_EVENT_DEL_REF(&scope->scope.event);

		if (ZEND_ASYNC_EVENT_REFCOUNT(&scope->scope.event) <= 1) {
			scope->scope.try_to_dispose(&scope->scope);
		}
	}

	if (composite_exception != NULL) {
		async_rethrow_exception(composite_exception);
	}

	//
	// If everything is correct,
	// the Scope will destroy itself as soon as the coroutine created within it completes execution.
	// Therefore, there's no point in taking additional actions to clean up resources.
	//
}

bool async_call_finally_handlers(HashTable *finally_handlers, finally_handlers_context_t *context, int32_t priority)
{
	if (finally_handlers == NULL || zend_hash_num_elements(finally_handlers) == 0) {
		return false;
	}

	// Create a special child scope for finally handlers
	zend_async_scope_t *child_scope = ZEND_ASYNC_NEW_SCOPE(context->scope);
	if (UNEXPECTED(child_scope == NULL)) {
		return false;
	}

	zval handlers;
	ZVAL_ARR(&handlers, finally_handlers);

	async_iterator_t *iterator =
			async_iterator_new(&handlers, NULL, NULL, finally_handlers_iterator_handler, child_scope, 0, priority, 0);

	zval_ptr_dtor(&handlers);
	ZVAL_UNDEF(&handlers);

	if (UNEXPECTED(EG(exception))) {
		return false;
	}

	context->composite_exception = NULL;
	iterator->extended_data = context;
	iterator->extended_dtor = finally_handlers_iterator_dtor;
	async_iterator_run_in_coroutine(iterator, priority);

	//
	// We retain ownership of the Scope in order to be able to handle exceptions from the Finally handlers.
	// example: finally_handlers_iterator_dtor
	// If the onFinally handlers throw an exception, it will end up in the Scope,
	// so it's important that the Scope is not destroyed before that moment.
	//
	ZEND_ASYNC_EVENT_ADD_REF(&context->scope->event);

	if (UNEXPECTED(EG(exception))) {
		return false;
	}

	return true;
}

static void finally_context_dtor(finally_handlers_context_t *context)
{
	if (context->coroutine != NULL) {
		// Release the coroutine reference
		OBJ_RELEASE(&context->coroutine->std);
		context->coroutine = NULL;
	}
}

static zend_always_inline void coroutine_call_finally_handlers(async_coroutine_t *coroutine)
{
	HashTable *finally_handlers = coroutine->finally_handlers;
	coroutine->finally_handlers = NULL;
	finally_handlers_context_t *finally_context = ecalloc(1, sizeof(finally_handlers_context_t));
	finally_context->coroutine = coroutine;
	finally_context->scope = coroutine->coroutine.scope;
	finally_context->dtor = finally_context_dtor;
	finally_context->params_count = 1;
	ZVAL_OBJ(&finally_context->params[0], &coroutine->std);

	if (async_call_finally_handlers(finally_handlers, finally_context, 1)) {
		GC_ADDREF(&coroutine->std); // Keep reference to coroutine while handlers are running
	} else {
		efree(finally_context);
		zend_array_destroy(finally_handlers);
	}
}

///////////////////////////////////////////////////////////
/// 7. PHP Methods
///////////////////////////////////////////////////////////

// Context Management Method
METHOD(getContext)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_coroutine_t *coroutine = THIS_COROUTINE;

	if (coroutine->coroutine.context == NULL) {
		async_context_t *context = async_context_new();
		if (UNEXPECTED(context == NULL)) {
			RETURN_THROWS();
		}

		coroutine->coroutine.context = &context->base;
	}

	// Return the context object
	RETURN_OBJ_COPY(&((async_context_t *) coroutine->coroutine.context)->std);
}

// Finally Handler Method
METHOD(onFinally)
{
	zval *callable;

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_ZVAL(callable)
	ZEND_PARSE_PARAMETERS_END();

	if (UNEXPECTED(false == zend_is_callable(callable, 0, NULL))) {
		zend_argument_type_error(1, "argument must be callable");
		RETURN_THROWS();
	}

	async_coroutine_t *coroutine = THIS_COROUTINE;

	// Check if coroutine is already finished
	if (ZEND_COROUTINE_IS_FINISHED(&coroutine->coroutine)) {

		// Call the callable immediately
		zval result, param;
		ZVAL_UNDEF(&result);
		ZVAL_OBJ(&param, &coroutine->std);

		if (UNEXPECTED(call_user_function(NULL, NULL, callable, &result, 1, &param) == FAILURE)) {
			zend_throw_error(NULL, "Failed to call finally handler in finished coroutine");
			zval_ptr_dtor(&result);
			RETURN_THROWS();
		}

		return;
	}

	// Lazy initialization of finally_handlers array
	if (coroutine->finally_handlers == NULL) {
		coroutine->finally_handlers = zend_new_array(0);
	}

	if (UNEXPECTED(zend_hash_next_index_insert(coroutine->finally_handlers, callable) == NULL)) {
		async_throw_error("Failed to add finally handler to coroutine");
		RETURN_THROWS();
	}

	Z_TRY_ADDREF_P(callable);
}

// Identity Methods
METHOD(getId)
{
	ZEND_PARSE_PARAMETERS_NONE();

	RETURN_LONG(Z_OBJ_P(ZEND_THIS)->handle);
}

METHOD(asHiPriority)
{
	// TODO: Implement priority handling in scheduler
	// For now, just return the same coroutine
	RETURN_ZVAL(ZEND_THIS, 1, 0);
}

// Result Methods
METHOD(getResult)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_coroutine_t *coroutine = THIS_COROUTINE;

	if (!ZEND_COROUTINE_IS_FINISHED(&coroutine->coroutine)) {
		RETURN_NULL();
	}

	if (Z_TYPE(coroutine->coroutine.result) == IS_UNDEF) {
		RETURN_NULL();
	}

	RETURN_ZVAL(&coroutine->coroutine.result, 1, 0);
}

METHOD(getException)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_coroutine_t *coroutine = THIS_COROUTINE;

	if (false == ZEND_COROUTINE_IS_FINISHED(&coroutine->coroutine)) {
		RETURN_NULL();
	}

	if (coroutine->coroutine.exception == NULL) {
		RETURN_NULL();
	}

	RETURN_OBJ_COPY(coroutine->coroutine.exception);
}

METHOD(getTrace)
{
	// TODO: Implement debug trace collection
	// This would require fiber stack trace functionality
	array_init(return_value);
}

// Location Methods
METHOD(getSpawnFileAndLine)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_coroutine_t *coroutine = THIS_COROUTINE;

	array_init(return_value);

	if (coroutine->coroutine.filename) {
		add_next_index_str(return_value, zend_string_copy(coroutine->coroutine.filename));
	} else {
		add_next_index_null(return_value);
	}

	add_next_index_long(return_value, coroutine->coroutine.lineno);
}

METHOD(getSpawnLocation)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_coroutine_t *coroutine = THIS_COROUTINE;

	if (coroutine->coroutine.filename) {
		RETURN_STR(zend_strpprintf(0, "%s:%d", ZSTR_VAL(coroutine->coroutine.filename), coroutine->coroutine.lineno));
	} else {
		RETURN_STRING("unknown");
	}
}

METHOD(getSuspendFileAndLine)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_coroutine_t *coroutine = THIS_COROUTINE;

	array_init(return_value);

	if (coroutine->waker.filename) {
		add_next_index_str(return_value, zend_string_copy(coroutine->waker.filename));
		add_next_index_long(return_value, coroutine->waker.lineno);
	} else {
		add_next_index_null(return_value);
		add_next_index_long(return_value, 0);
	}
}

METHOD(getSuspendLocation)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_coroutine_t *coroutine = THIS_COROUTINE;

	if (coroutine->waker.filename) {
		RETURN_STR(zend_strpprintf(0, "%s:%d", ZSTR_VAL(coroutine->waker.filename), coroutine->waker.lineno));
	} else {
		RETURN_STRING("unknown");
	}
}

// Status Methods
METHOD(isStarted)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_BOOL(ZEND_COROUTINE_IS_STARTED(&THIS_COROUTINE->coroutine));
}

METHOD(isQueued)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_coroutine_t *coroutine = THIS_COROUTINE;

	if (coroutine->coroutine.waker == NULL) {
		RETURN_FALSE;
	}

	RETURN_BOOL(coroutine->waker.status == ZEND_ASYNC_WAKER_QUEUED);
}

METHOD(isRunning)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_coroutine_t *coroutine = THIS_COROUTINE;

	// Coroutine is running if it's the current one and is started but not finished
	RETURN_BOOL(ZEND_COROUTINE_IS_STARTED(&coroutine->coroutine) &&
				false == ZEND_COROUTINE_IS_FINISHED(&coroutine->coroutine));
}

METHOD(isSuspended)
{
	ZEND_PARSE_PARAMETERS_NONE();

	RETURN_BOOL(ZEND_COROUTINE_SUSPENDED(&THIS_COROUTINE->coroutine));
}

METHOD(isCancelled)
{
	ZEND_PARSE_PARAMETERS_NONE();

	RETURN_BOOL(ZEND_COROUTINE_IS_CANCELLED(&THIS_COROUTINE->coroutine) &&
				ZEND_COROUTINE_IS_FINISHED(&THIS_COROUTINE->coroutine));
}

METHOD(isCancellationRequested)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_coroutine_t *coroutine = THIS_COROUTINE;

	RETURN_BOOL((ZEND_COROUTINE_IS_CANCELLED(&coroutine->coroutine) &&
				 !ZEND_COROUTINE_IS_FINISHED(&coroutine->coroutine)) ||
				coroutine->deferred_cancellation != NULL);
}

METHOD(isFinished)
{
	ZEND_PARSE_PARAMETERS_NONE();

	RETURN_BOOL(ZEND_COROUTINE_IS_FINISHED(&THIS_COROUTINE->coroutine));
}

// Advanced Methods
METHOD(getAwaitingInfo)
{
	ZEND_PARSE_PARAMETERS_NONE();

	zend_array *info = ZEND_ASYNC_GET_AWAITING_INFO(&THIS_COROUTINE->coroutine);

	if (info == NULL) {
		array_init(return_value);
	} else {
		RETURN_ARR(info);
	}
}

METHOD(cancel)
{
	zend_object *exception = NULL;

	zend_class_entry *ce_cancellation_exception = ZEND_ASYNC_GET_CE(ZEND_ASYNC_EXCEPTION_CANCELLATION);

	ZEND_PARSE_PARAMETERS_START(0, 1)
	Z_PARAM_OPTIONAL;
	Z_PARAM_OBJ_OF_CLASS_OR_NULL(exception, ce_cancellation_exception)
	ZEND_PARSE_PARAMETERS_END();

	ZEND_ASYNC_CANCEL(&THIS_COROUTINE->coroutine, exception, false);
}
