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

		if (instanceof_function(EG(exception)->ce, async_ce_cancellation_exception)
			|| zend_is_graceful_exit(EG(exception))
			|| zend_is_unwind_exit(EG(exception))) {
			OBJ_RELEASE(exception);
			exception = NULL;
		}
	}

	zend_exception_save();
	// Mark second parameter of zend_async_callbacks_notify as ZVAL
	ZEND_ASYNC_EVENT_SET_ZVAL_RESULT(&coroutine->coroutine.event);
	ZEND_COROUTINE_CLR_EXCEPTION_HANDLED(&coroutine->coroutine);
	zend_async_callbacks_notify(&coroutine->coroutine.event, &coroutine->coroutine.result, exception);
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
			async_throw_error("Failed to remove coroutine from the list");
		}

		// Decrease the active coroutine count if the coroutine is not a zombie.
		if (false == ZEND_COROUTINE_IS_ZOMBIE(&coroutine->coroutine)) {
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
			transfer->context = ASYNC_G(main_transfer)->context;
			transfer->flags = ASYNC_G(main_transfer)->flags;
			ZVAL_COPY_VALUE(&transfer->value, &ASYNC_G(main_transfer)->value);
			ZVAL_UNDEF(&ASYNC_G(main_transfer)->value);
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

static zend_string* coroutine_info(zend_async_event_t *event)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) event;

	zend_string * zend_coroutine_name = zend_coroutine_callable_name(&coroutine->coroutine);

	if (coroutine->coroutine.waker != NULL) {
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
		zval_ptr_dtor(&coroutine->coroutine.fcall->fci.function_name);
		efree(coroutine->coroutine.fcall);
		coroutine->coroutine.fcall = NULL;
	}

	if (coroutine->coroutine.filename) {
		zend_string_release_ex(coroutine->coroutine.filename, 0);
		coroutine->coroutine.filename = NULL;
	}

	if (coroutine->coroutine.waker) {
		zend_async_waker_destroy(&coroutine->coroutine);
		coroutine->coroutine.waker = NULL;
	}

	zval_ptr_dtor(&coroutine->coroutine.result);
}

static void coroutine_free(zend_object *object)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_OBJECT_TO_EVENT(object);

	zend_async_callbacks_free(&coroutine->coroutine.event);
	zend_object_std_dtor(object);
}

static zend_object *coroutine_object_create(zend_class_entry *class_entry)
{
	async_coroutine_t *coroutine = zend_object_alloc(
		sizeof(async_coroutine_t) + zend_object_properties_size(async_ce_coroutine), class_entry
	);

	ZVAL_UNDEF(&coroutine->coroutine.result);

	ZEND_ASYNC_EVENT_SET_ZEND_OBJ(&coroutine->coroutine.event);
	ZEND_ASYNC_EVENT_SET_NO_FREE_MEMORY(&coroutine->coroutine.event);
	ZEND_ASYNC_EVENT_SET_ZEND_OBJ_OFFSET(&coroutine->coroutine.event, XtOffsetOf(async_coroutine_t, std));

	zend_async_event_t *event = &coroutine->coroutine.event;

	event->start = coroutine_event_start;
	event->stop = coroutine_event_stop;
	event->add_callback = coroutine_add_callback;
	event->del_callback = coroutine_del_callback;
	event->info = coroutine_info;
	event->dispose = coroutine_dispose;

	coroutine->flags = ZEND_FIBER_STATUS_INIT;
	coroutine->coroutine.extended_data = NULL;

	zend_object_std_init(&coroutine->std, class_entry);
	object_properties_init(&coroutine->std, class_entry);

	return &coroutine->std;
}

zend_coroutine_t *new_coroutine(zend_async_scope_t *scope)
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