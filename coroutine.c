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
#include "coroutine.h"

#include "context.h"
#include "coroutine_arginfo.h"
#include "exceptions.h"
#include "php_async.h"

#include "scheduler.h"
#include "scope.h"
#include "zend_common.h"
#include "zend_exceptions.h"
#include "zend_ini.h"

#define METHOD(name) PHP_METHOD(Async_Coroutine, name)

zend_class_entry * async_ce_coroutine = NULL;

static zend_function coroutine_root_function = { ZEND_INTERNAL_FUNCTION };

///////////////////////////////////////////////////////////
/// Coroutine methods
///////////////////////////////////////////////////////////

METHOD(getId)
{

}

METHOD(asHiPriority)
{

}

METHOD(getContext)
{

}

METHOD(getTrace)
{

}

METHOD(getSpawnFileAndLine)
{

}

METHOD(getSpawnLocation)
{

}

METHOD(getSuspendFileAndLine)
{

}

METHOD(getSuspendLocation)
{

}

METHOD(isStarted)
{

}

METHOD(isQueued)
{

}

METHOD(isRunning)
{

}

METHOD(isSuspended)
{

}

METHOD(isCancelled)
{

}

METHOD(isCancellationRequested)
{

}

METHOD(isFinished)
{

}

METHOD(getAwaitingInfo)
{

}

METHOD(cancel)
{

}

METHOD(onFinally)
{

}

///////////////////////////////////////////////////////////
/// Coroutine methods end
///////////////////////////////////////////////////////////

static zend_always_inline async_coroutine_t *coroutine_from_context(zend_fiber_context *context)
{
	ZEND_ASSERT(context->kind == async_ce_coroutine && "Fiber context does not belong to a Coroutine fiber");

	return (async_coroutine_t *)(((char *) context) - XtOffsetOf(async_coroutine_t, context));
}

void async_coroutine_cleanup(zend_fiber_context *context)
{
	async_coroutine_t *coroutine = coroutine_from_context(context);

	zend_vm_stack current_stack = EG(vm_stack);
	EG(vm_stack) = coroutine->vm_stack;
	zend_vm_stack_destroy();
	EG(vm_stack) = current_stack;
	coroutine->execute_data = NULL;
	

	OBJ_RELEASE(&coroutine->std);
}

void async_coroutine_finalize(zend_fiber_transfer *transfer, async_coroutine_t * coroutine)
{
	ZEND_COROUTINE_SET_FINISHED(&coroutine->coroutine);

	// call coroutines handlers
	zend_object * exception = NULL;

	if (EG(exception)) {
		if (EG(prev_exception)) {
			zend_exception_set_previous(EG(exception), EG(prev_exception));
		}

		exception = EG(exception);
		GC_ADDREF(exception);

		zend_clear_exception();

		if (instanceof_function(exception->ce, zend_ce_cancellation_exception)
			|| zend_is_graceful_exit(exception)
			|| zend_is_unwind_exit(exception)) {
			OBJ_RELEASE(exception);
			exception = NULL;
		}
	}

	// Hold the exception inside coroutine if it is not NULL.
	if (exception != NULL) {
		coroutine->coroutine.exception = exception;
		GC_ADDREF(exception);
	}

	zend_exception_save();
	// Mark second parameter of zend_async_callbacks_notify as ZVAL
	ZEND_ASYNC_EVENT_SET_ZVAL_RESULT(&coroutine->coroutine.event);
	ZEND_COROUTINE_CLR_EXCEPTION_HANDLED(&coroutine->coroutine);
	ZEND_ASYNC_CALLBACKS_NOTIFY(&coroutine->coroutine.event, &coroutine->coroutine.result, exception);
	zend_async_callbacks_free(&coroutine->coroutine.event);

	if (coroutine->coroutine.internal_context != NULL) {
		zend_async_coroutine_internal_context_dispose(&coroutine->coroutine);
	}

	zend_exception_restore();

	// If the exception was handled by any handler, we do not propagate it further.
	if (exception != NULL && ZEND_COROUTINE_IS_EXCEPTION_HANDLED(&coroutine->coroutine)) {
		OBJ_RELEASE(exception);
		exception = NULL;
	}

	// Otherwise, we rethrow the exception.
	if (exception != NULL) {
		zend_throw_exception_internal(exception);
	}

	if (EG(exception)) {
		if (!(coroutine->flags & ZEND_FIBER_FLAG_DESTROYED)
			|| !(zend_is_graceful_exit(EG(exception)) || zend_is_unwind_exit(EG(exception)))
		) {
			coroutine->flags |= ZEND_FIBER_FLAG_THREW;
			transfer->flags = ZEND_FIBER_TRANSFER_FLAG_ERROR;

			ZVAL_OBJ_COPY(&transfer->value, EG(exception));
		}

		zend_clear_exception();
	}

	if (EXPECTED(ZEND_ASYNC_SCHEDULER != &coroutine->coroutine)) {
		// Permanently remove the coroutine from the Scheduler.
		if (UNEXPECTED(zend_hash_index_del(&ASYNC_G(coroutines), coroutine->std.handle) == FAILURE)) {
			zend_error(E_CORE_ERROR, "Failed to remove coroutine from the list");
		}

		// Decrease the active coroutine count if the coroutine is not a zombie and is started.
		if (ZEND_COROUTINE_IS_STARTED(&coroutine->coroutine)
			&& false == ZEND_COROUTINE_IS_ZOMBIE(&coroutine->coroutine)) {
			ZEND_ASYNC_DECREASE_COROUTINE_COUNT
		}
	}
}

ZEND_STACK_ALIGNED void async_coroutine_execute(zend_fiber_transfer *transfer)
{
	ZEND_ASSERT(Z_TYPE(transfer->value) == IS_NULL && "Initial transfer value to coroutine context must be NULL");
	ZEND_ASSERT(!transfer->flags && "No flags should be set on initial transfer");

	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_CURRENT_COROUTINE;
	ZEND_COROUTINE_SET_STARTED(&coroutine->coroutine);

	/* Determine the current error_reporting ini setting. */
	zend_long error_reporting = INI_INT("error_reporting");
	if (!error_reporting && !INI_STR("error_reporting")) {
		error_reporting = E_ALL;
	}

	EG(vm_stack) = NULL;

	zend_first_try {
		zend_vm_stack stack = zend_vm_stack_new_page(ZEND_FIBER_VM_STACK_SIZE, NULL);
		EG(vm_stack) = stack;
		EG(vm_stack_top) = stack->top + ZEND_CALL_FRAME_SLOT;
		EG(vm_stack_end) = stack->end;
		EG(vm_stack_page_size) = ZEND_FIBER_VM_STACK_SIZE;

		coroutine->execute_data = (zend_execute_data *) stack->top;

		memset(coroutine->execute_data, 0, sizeof(zend_execute_data));

		coroutine->execute_data->func = &coroutine_root_function;

		EG(current_execute_data) = coroutine->execute_data;
		EG(jit_trace_num) = 0;
		EG(error_reporting) = (int) error_reporting;

#ifdef ZEND_CHECK_STACK_LIMIT
		EG(stack_base) = zend_fiber_stack_base(coroutine->context.stack);
		EG(stack_limit) = zend_fiber_stack_limit(coroutine->context.stack);
#endif

		if (EXPECTED(coroutine->coroutine.internal_entry == NULL))
		{
			ZEND_ASSERT(coroutine->coroutine.fcall != NULL && "Coroutine function call is not set");
			coroutine->coroutine.fcall->fci.retval = &coroutine->coroutine.result;

			zend_call_function(&coroutine->coroutine.fcall->fci, &coroutine->coroutine.fcall->fci_cache);

			zval_ptr_dtor(&coroutine->coroutine.fcall->fci.function_name);
			ZVAL_UNDEF(&coroutine->coroutine.fcall->fci.function_name);
		} else {
			coroutine->coroutine.internal_entry();
		}

		async_coroutine_finalize(transfer, coroutine);

	} zend_catch {
		coroutine->flags |= ZEND_FIBER_FLAG_BAILOUT;
		transfer->flags = ZEND_FIBER_TRANSFER_FLAG_BAILOUT;

		if (EXPECTED(ZEND_ASYNC_SCHEDULER != &coroutine->coroutine)) {
			// Permanently remove the coroutine from the Scheduler.
			if (UNEXPECTED(zend_hash_index_del(&ASYNC_G(coroutines), coroutine->std.handle) == FAILURE)) {
				async_throw_error("Failed to remove coroutine from the list");
			}

			// Decrease the active coroutine count if the coroutine is not a zombie.
			if (false == ZEND_COROUTINE_IS_ZOMBIE(&coroutine->coroutine)) {
				ZEND_ASYNC_DECREASE_COROUTINE_COUNT
			}
		}
	} zend_end_try();

	coroutine->context.cleanup = &async_coroutine_cleanup;
	coroutine->vm_stack = EG(vm_stack);

	//
	// The scheduler coroutine always terminates into the main execution flow.
	//
	if (UNEXPECTED(&coroutine->coroutine == ZEND_ASYNC_SCHEDULER)) {
		if (transfer != ASYNC_G(main_transfer)) {

			if (UNEXPECTED(Z_TYPE(transfer->value) == IS_OBJECT)) {
				zval_ptr_dtor(&transfer->value);
				zend_error(E_CORE_WARNING, "The transfer value must be NULL when the main coroutine is resumed");
			}

			transfer->context = ASYNC_G(main_transfer)->context;
			transfer->flags = ASYNC_G(main_transfer)->flags;
			ZVAL_COPY_VALUE(&transfer->value, &ASYNC_G(main_transfer)->value);
			ZVAL_NULL(&ASYNC_G(main_transfer)->value);
		}

		return;
	}

	transfer->context = NULL;

	async_scheduler_coroutine_suspend(transfer);
}

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
static bool coroutine_replay(zend_async_event_t *event, zend_async_event_callback_t *callback, zval *result, zend_object **exception)
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
		zend_throw_exception_internal(coroutine->coroutine.exception);
	} else if (exception != NULL && coroutine->coroutine.exception != NULL) {
		*exception = coroutine->coroutine.exception;
		GC_ADDREF(*exception);
	}

	return coroutine->coroutine.exception != NULL || Z_TYPE(coroutine->coroutine.result) != IS_UNDEF;
}

static zend_string* coroutine_info(zend_async_event_t *event)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) event;

	zend_string * zend_coroutine_name = zend_coroutine_callable_name(&coroutine->coroutine);

	if (ZEND_COROUTINE_SUSPENDED(&coroutine->coroutine)) {
		return zend_strpprintf(0,
			"Coroutine %d spawned at %s:%d, suspended at %s:%d (%s)",
			coroutine->std.handle,
			coroutine->coroutine.filename ? ZSTR_VAL(coroutine->coroutine.filename) : "",
			coroutine->coroutine.lineno,
			coroutine->coroutine.waker->filename ? ZSTR_VAL(coroutine->coroutine.waker->filename) : "",
			coroutine->coroutine.waker->lineno,
			ZSTR_VAL(zend_coroutine_name)
		);
	} else {
		return zend_strpprintf(0,
			"Coroutine %d spawned at %s:%d (%s)",
			coroutine->std.handle,
			coroutine->coroutine.filename ? ZSTR_VAL(coroutine->coroutine.filename) : "",
			coroutine->coroutine.lineno,
			ZSTR_VAL(zend_coroutine_name)
		);
	}
}

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

	async_scheduler_coroutine_suspend(NULL);
}

void async_coroutine_resume(zend_coroutine_t *coroutine, zend_object * error, const bool transfer_error)
{
	if (UNEXPECTED(coroutine->waker == NULL)) {
		async_throw_error("Cannot resume a coroutine that has not been suspended");
		return;
	}

	if (error != NULL) {
		if (coroutine->waker->error != NULL) {
			zend_exception_set_previous(error, coroutine->waker->error);
			OBJ_RELEASE(coroutine->waker->error);
		}

		coroutine->waker->error = error;

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

void async_coroutine_cancel(zend_coroutine_t *zend_coroutine, zend_object *error, const bool transfer_error, const bool is_safely)
{
	// If the coroutine finished, do nothing.
	if (ZEND_COROUTINE_IS_FINISHED(zend_coroutine)) {
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

	if (false == ZEND_COROUTINE_IS_STARTED(zend_coroutine)) {
		zend_coroutine->waker->status = ZEND_ASYNC_WAKER_IGNORED;
		zend_coroutine->exception = error;

		if (false == transfer_error) {
			GC_ADDREF(error);
		}

		return;
	}

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

static void coroutine_dispose(zend_async_event_t *event)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) event;
	OBJ_RELEASE(&coroutine->std);
}

static void coroutine_object_destroy(zend_object *object)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_OBJECT_TO_EVENT(object);

	if (coroutine->coroutine.scope != NULL) {
		async_scope_remove_coroutine((async_scope_t *) coroutine->coroutine.scope, coroutine);
		coroutine->coroutine.scope = NULL;
	}

	if (coroutine->coroutine.fcall) {

		zend_fcall_t * fcall = coroutine->coroutine.fcall;
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

	if (coroutine->coroutine.filename) {
		zend_string_release_ex(coroutine->coroutine.filename, 0);
		coroutine->coroutine.filename = NULL;
	}

	if (coroutine->coroutine.waker) {
		zend_async_waker_destroy(&coroutine->coroutine);
		coroutine->coroutine.waker = NULL;
	}

	if (coroutine->coroutine.internal_context != NULL) {
		zend_async_coroutine_internal_context_dispose(&coroutine->coroutine);
	}

	zval_ptr_dtor(&coroutine->coroutine.result);

	if (coroutine->coroutine.exception != NULL) {
		// If the coroutine has an exception, we need to release it.

		zend_object *exception = coroutine->coroutine.exception;
		coroutine->coroutine.exception = NULL;
		OBJ_RELEASE(exception);
	}
}

static void coroutine_free(zend_object *object)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_OBJECT_TO_EVENT(object);

	zend_async_callbacks_free(&coroutine->coroutine.event);
	zend_object_std_dtor(object);
}

static zend_object *coroutine_object_create(zend_class_entry *class_entry)
{
	async_coroutine_t *coroutine = zend_object_alloc(sizeof(async_coroutine_t), class_entry);

	ZVAL_UNDEF(&coroutine->coroutine.result);

	ZEND_ASYNC_EVENT_SET_ZEND_OBJ(&coroutine->coroutine.event);
	ZEND_ASYNC_EVENT_SET_NO_FREE_MEMORY(&coroutine->coroutine.event);
	ZEND_ASYNC_EVENT_SET_ZEND_OBJ_OFFSET(&coroutine->coroutine.event, XtOffsetOf(async_coroutine_t, std));

	zend_async_event_t *event = &coroutine->coroutine.event;

	event->start = coroutine_event_start;
	event->stop = coroutine_event_stop;
	event->add_callback = coroutine_add_callback;
	event->del_callback = coroutine_del_callback;
	event->replay = coroutine_replay;
	event->info = coroutine_info;
	event->dispose = coroutine_dispose;

	coroutine->flags = ZEND_FIBER_STATUS_INIT;
	coroutine->coroutine.extended_data = NULL;

	zend_object_std_init(&coroutine->std, class_entry);
	object_properties_init(&coroutine->std, class_entry);

	return &coroutine->std;
}

zend_coroutine_t *async_new_coroutine(zend_async_scope_t *scope)
{
	zend_object * object = coroutine_object_create(async_ce_coroutine);

	if (UNEXPECTED(EG(exception))) {
		return NULL;
	}

	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_OBJECT_TO_EVENT(object);
	coroutine->coroutine.scope = scope;

	return &coroutine->coroutine;
}

static zend_object_handlers coroutine_handlers;

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
}

//////////////////////////////////////////////////////////////////////
/// Coroutine Context API
//////////////////////////////////////////////////////////////////////


bool async_coroutine_context_set(zend_coroutine_t * z_coroutine, zval *key, zval *value)
{
	async_coroutine_t * coroutine = (async_coroutine_t *) (z_coroutine != NULL ? z_coroutine : ZEND_ASYNC_CURRENT_COROUTINE);

	if (UNEXPECTED(coroutine == NULL || coroutine->coroutine.context == NULL)) {
		return false;
	}

	coroutine->coroutine.context->set(coroutine->coroutine.context, key, value);
	return true;
}

bool async_coroutine_context_get(zend_coroutine_t * z_coroutine, zval *key, zval *result)
{
	async_coroutine_t * coroutine = (async_coroutine_t *) (z_coroutine != NULL ? z_coroutine : ZEND_ASYNC_CURRENT_COROUTINE);

	if (UNEXPECTED(coroutine == NULL || coroutine->coroutine.context == NULL)) {
		if (result != NULL) {
			ZVAL_NULL(result);
		}
		return false;
	}

	return coroutine->coroutine.context->find(coroutine->coroutine.context, key, result, false);
}

bool async_coroutine_context_has(zend_coroutine_t * z_coroutine, zval *key)
{
	async_coroutine_t * coroutine = (async_coroutine_t *) (z_coroutine != NULL ? z_coroutine : ZEND_ASYNC_CURRENT_COROUTINE);

	if (UNEXPECTED(coroutine == NULL || coroutine->coroutine.context == NULL)) {
		return false;
	}

	return coroutine->coroutine.context->find(coroutine->coroutine.context, key, NULL, false);
}

bool async_coroutine_context_delete(zend_coroutine_t * z_coroutine, zval *key)
{
	async_coroutine_t * coroutine = (async_coroutine_t *) (z_coroutine != NULL ? z_coroutine : ZEND_ASYNC_CURRENT_COROUTINE);

	if (UNEXPECTED(coroutine == NULL || coroutine->coroutine.context == NULL)) {
		return false;
	}

	return coroutine->coroutine.context->unset(coroutine->coroutine.context, key);
}