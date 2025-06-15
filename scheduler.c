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
#include <Zend/zend_async_API.h>

#include "coroutine.h"
#include "internal/zval_circular_buffer.h"
#include "scheduler.h"
#include "php_async.h"
#include "exceptions.h"
#include "scope.h"
#include "zend_common.h"

void async_scheduler_startup(void)
{
}

void async_scheduler_shutdown(void)
{
}

zend_always_inline static void execute_microtasks(void)
{
	circular_buffer_t *buffer = &ASYNC_G(microtasks);
	zend_async_microtask_t *task = NULL;

	while (circular_buffer_is_not_empty(buffer)) {

		circular_buffer_pop(buffer, &task);

		if (EXPECTED(false == task->is_cancelled)) {
			task->handler(task);
		}

		if (task->ref_count > 0) {
			task->ref_count--;
		}

		if (task->ref_count <= 0) {
			task->dtor(task);
		}

		if (UNEXPECTED(EG(exception) != NULL)) {
			return;
		}
	}
}

/**
 * Defines a transfer object for the coroutine context.
 *
 * This function is used to return control from a coroutine FOR THE LAST TIME.
 * If you only need to suspend coroutines, you should use the `switch_context()` function.
 *
 * This function initializes the coroutine context if it is not already initialized,
 * and sets the transfer value to the provided exception or NULL.
 *
 * @param coroutine The coroutine to define the transfer for.
 * @param exception The exception to pass, or NULL if no exception is to be passed.
 * @param transfer The transfer object to define.
 */
static zend_always_inline void define_transfer(async_coroutine_t *coroutine, zend_object * exception, zend_fiber_transfer *transfer)
{
	if (UNEXPECTED(coroutine->context.status == ZEND_FIBER_STATUS_INIT
		&& zend_fiber_init_context(&coroutine->context, async_ce_coroutine, async_coroutine_execute, EG(fiber_stack_size)) == FAILURE)) {
		zend_throw_error(NULL, "Failed to initialize coroutine context");
		return;
	}

	transfer->context = &coroutine->context;
	transfer->flags = exception != NULL ? ZEND_FIBER_TRANSFER_FLAG_ERROR : 0;

	if (exception != NULL) {
		ZVAL_OBJ(&transfer->value, exception);
	} else {
		ZVAL_NULL(&transfer->value);
	}

	ZEND_ASYNC_CURRENT_COROUTINE = &coroutine->coroutine;
}

/**
 * Switches the context to the given coroutine, optionally passing an exception.
 *
 * If the coroutine context is not initialized, it will be initialized first.
 * If an exception is provided, it will be set in the transfer value.
 *
 * IMPORTANT! This function must be called ONLY if the coroutine being switched from is not finishing its execution.
 * If the coroutine is yielding control for the **last time**, then you must use define_transfer().
 *
 * @param coroutine The coroutine to switch to.
 * @param exception The exception to pass, or NULL if no exception is to be passed.
 */
static zend_always_inline void switch_context(async_coroutine_t *coroutine, zend_object * exception)
{
	zend_fiber_transfer transfer = {
		.context = &coroutine->context,
		.flags = exception != NULL ? ZEND_FIBER_TRANSFER_FLAG_ERROR : 0,
	};

	if (coroutine->context.status == ZEND_FIBER_STATUS_INIT
		&& zend_fiber_init_context(&coroutine->context, async_ce_coroutine, async_coroutine_execute, EG(fiber_stack_size)) == FAILURE) {
		zend_throw_error(NULL, "Failed to initialize coroutine context");
		return;
	}

	if (exception != NULL) {
		ZVAL_OBJ(&transfer.value, exception);
	} else {
		ZVAL_NULL(&transfer.value);
	}

	zend_coroutine_t * previous_coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	ZEND_ASYNC_CURRENT_COROUTINE = &coroutine->coroutine;

	zend_fiber_switch_context(&transfer);

	ZEND_ASYNC_CURRENT_COROUTINE = previous_coroutine;

	/* Forward bailout into current coroutine. */
	if (UNEXPECTED(transfer.flags & ZEND_FIBER_TRANSFER_FLAG_BAILOUT)) {
		ZEND_ASYNC_CURRENT_COROUTINE = NULL;
		zend_bailout();
	}

	// Transfer the exception to the current coroutine.
	if (UNEXPECTED(transfer.flags & ZEND_FIBER_TRANSFER_FLAG_ERROR)) {
		zend_throw_exception_internal(Z_OBJ(transfer.value));
		ZVAL_NULL(&transfer.value);
	}
}

static zend_always_inline async_coroutine_t * next_coroutine(void)
{
	async_coroutine_t *coroutine;

	if (UNEXPECTED(circular_buffer_pop(&ASYNC_G(coroutine_queue), &coroutine) == FAILURE)) {
		ZEND_ASSERT("Failed to pop the coroutine from the pending queue.");
		return NULL;
	}

	return coroutine;
}

/**
 * Executes the next coroutine in the queue.
 *
 * If the coroutine is not ready to be executed, it will return false.
 * If the coroutine is finished, it will clean up and return true.
 *
 * @param transfer The transfer object to define the context for the coroutine.
 * @return true if a coroutine was executed or cleaned up, false otherwise.
 */
static bool execute_next_coroutine(zend_fiber_transfer *transfer)
{
	async_coroutine_t *async_coroutine = next_coroutine();
	zend_coroutine_t *coroutine = &async_coroutine->coroutine;

	if (UNEXPECTED(coroutine == NULL)) {
		return false;
	}

	zend_async_waker_t * waker = coroutine->waker;

	if (UNEXPECTED(waker == NULL || waker->status == ZEND_ASYNC_WAKER_IGNORED)) {

		//
		// This state triggers if the fiber has never been started;
		// in this case, it is deallocated differently than usual.
		// Finalizing handlers are called. Memory is freed in the correct order!
		//
		if (ZEND_COROUTINE_IS_CANCELLED(coroutine)) {
			async_coroutine_finalize(NULL, async_coroutine);
		}

		coroutine->event.dispose(&coroutine->event);
		return true;
	}

	if (UNEXPECTED(waker->status == ZEND_ASYNC_WAKER_WAITING)) {
		zend_error(E_ERROR, "Attempt to resume a fiber that has not been resolved");
		coroutine->event.dispose(&coroutine->event);
		return false;
	}

	waker->status = ZEND_ASYNC_WAKER_RESULT;
	zend_object * error = waker->error;

	// The Waker object can be destroyed immediately if the result is an error.
	// It will be delivered to the coroutine as an exception.
	if (error != NULL) {
		waker->error = NULL;
		zend_async_waker_destroy(coroutine);
	}

	if (transfer != NULL) {
		define_transfer(async_coroutine, error, transfer);
		return true;
	} else if (ZEND_ASYNC_CURRENT_COROUTINE == coroutine) {
		if (error != NULL) {
			zend_throw_exception_internal(error);
		}
		return true;
	} else {
		switch_context(async_coroutine, error);
	}

	//
	// At this point, the async_coroutine must already be destroyed
	//

	return true;
}

/**
 * Switches to the scheduler coroutine.
 *
 * This method is used to transfer control to the special internal Scheduler coroutine.
 * The transfer parameter can be NULL for temporary suspension.
 * However, if the current coroutine is losing control PERMANENTLY, you must provide transfer.
 *
 * If the transfer object is provided, it will define the transfer for the scheduler.
 * If no transfer is provided, it will switch to the scheduler context without defining a transfer.
 *
 * @param transfer The transfer object to define for the scheduler, or NULL if not needed.
 */
static zend_always_inline void switch_to_scheduler(zend_fiber_transfer *transfer)
{
	async_coroutine_t *async_coroutine = (async_coroutine_t *) ZEND_ASYNC_SCHEDULER;

	ZEND_ASSERT(async_coroutine != NULL && "Scheduler coroutine is not initialized");

	if (transfer != NULL) {
		define_transfer(async_coroutine, NULL, transfer);
	} else {
		switch_context(async_coroutine, NULL);
	}
}

static bool resolve_deadlocks(void)
{
	zval *value;

	async_warning(
		"No active coroutines, deadlock detected. Coroutines in waiting: %u", ZEND_ASYNC_ACTIVE_COROUTINE_COUNT
	);

	ZEND_HASH_FOREACH_VAL(&ASYNC_G(coroutines), value)

		async_coroutine_t* coroutine = (async_coroutine_t*)Z_PTR_P(value);

		ZEND_ASSERT(coroutine->coroutine.waker != NULL && "The Coroutine has no waker object");

		if (coroutine->coroutine.waker != NULL && coroutine->coroutine.waker->filename != NULL) {

			//Maybe we need to get the function name
			//zend_string * function_name = NULL;
			//zend_get_function_name_by_fci(&fiber_state->fiber->fci, &fiber_state->fiber->fci_cache, &function_name);

			async_warning(
				"Resume that suspended in file: %s, line: %d will be canceled",
				ZSTR_VAL(coroutine->coroutine.waker->filename),
				coroutine->coroutine.waker->lineno
			);
		}

		ZEND_ASYNC_CANCEL(
			&coroutine->coroutine,
			async_new_exception(async_ce_cancellation_exception, "Deadlock detected"),
			true
		);

		if (EG(exception) != NULL) {
			return true;
		}

	ZEND_HASH_FOREACH_END();

	return false;
}

zend_always_inline static void execute_queued_coroutines(void)
{
	while (false == circular_buffer_is_empty(&ASYNC_G(coroutine_queue))) {
		execute_next_coroutine(NULL);

		if (UNEXPECTED(EG(exception))) {
			zend_exception_save();
		}
	}
}

static void async_scheduler_dtor(void)
{
	ZEND_ASYNC_SCHEDULER_CONTEXT = true;

	execute_microtasks();

	ZEND_ASYNC_SCHEDULER_CONTEXT = false;

	if (UNEXPECTED(false == circular_buffer_is_empty(&ASYNC_G(microtasks)))) {
		async_warning(
			"%u microtasks were not executed", circular_buffer_count(&ASYNC_G(microtasks))
		);
	}

	if (UNEXPECTED(false == circular_buffer_is_empty(&ASYNC_G(coroutine_queue)))) {
		async_warning(
			"%u deferred coroutines were not executed",
			circular_buffer_count(&ASYNC_G(coroutine_queue))
		);
	}

	zval_c_buffer_cleanup(&ASYNC_G(coroutine_queue));
	zval_c_buffer_cleanup(&ASYNC_G(microtasks));

	zval *current;
	// foreach by fibers_state and release all fibers
	ZEND_HASH_FOREACH_VAL(&ASYNC_G(coroutines), current) {
		async_coroutine_t *coroutine = Z_PTR_P(current);
		OBJ_RELEASE(&coroutine->std);
	} ZEND_HASH_FOREACH_END();

	zend_hash_clean(&ASYNC_G(coroutines));
	zend_hash_destroy(&ASYNC_G(coroutines));
	zend_hash_init(&ASYNC_G(coroutines), 0, NULL, NULL, 0);

	ZEND_ASYNC_GRACEFUL_SHUTDOWN = false;
	ZEND_ASYNC_SCHEDULER_CONTEXT = false;
	ZEND_ASYNC_DEACTIVATE;

	zend_exception_restore();
}

static void dispose_coroutines(void)
{
	zval * current;

	ZEND_HASH_FOREACH_VAL(&ASYNC_G(coroutines), current) {
		zend_coroutine_t *coroutine = Z_PTR_P(current);

		if (coroutine->waker != NULL) {
			coroutine->waker->status = ZEND_ASYNC_WAKER_IGNORED;
		}

		coroutine->event.dispose(&coroutine->event);

		if (EG(exception)) {
			zend_exception_save();
		}

	} ZEND_HASH_FOREACH_END();
}

static void cancel_queued_coroutines(void)
{
	zend_exception_save();

	// 1. Walk through all coroutines and cancel them if they are suspended.
	zval * current;

	zend_object * cancellation_exception = async_new_exception(async_ce_cancellation_exception, "Graceful shutdown");

	ZEND_HASH_FOREACH_VAL(&ASYNC_G(coroutines), current) {
		zend_coroutine_t *coroutine = Z_PTR_P(current);

		if (((async_coroutine_t *) coroutine)->context.status == ZEND_FIBER_STATUS_INIT) {
			// No need to cancel the fiber if it has not been started.
			coroutine->waker->status = ZEND_ASYNC_WAKER_IGNORED;
			ZEND_COROUTINE_SET_CANCELLED(coroutine);
			coroutine->exception = cancellation_exception;
			GC_ADDREF(cancellation_exception);
		} else {
			ZEND_ASYNC_CANCEL(coroutine, cancellation_exception, false);
		}

		if (EG(exception)) {
			zend_exception_save();
		}

	} ZEND_HASH_FOREACH_END();

	OBJ_RELEASE(cancellation_exception);

	zend_exception_restore();
}

void async_scheduler_start_waker_events(zend_async_waker_t * waker)
{
	ZEND_ASSERT(waker != NULL && "Waker is NULL in async_scheduler_start_waker_events");

	zval * current;
	ZEND_HASH_FOREACH_VAL(&waker->events, current) {
		const zend_async_waker_trigger_t * trigger = Z_PTR_P(current);
		trigger->event->start(trigger->event);
	} ZEND_HASH_FOREACH_END();
}

void async_scheduler_stop_waker_events(zend_async_waker_t * waker)
{
	ZEND_ASSERT(waker != NULL && "Waker is NULL in async_scheduler_stop_waker_events");

	zval * current;
	ZEND_HASH_FOREACH_VAL(&waker->events, current) {
		const zend_async_waker_trigger_t * trigger = Z_PTR_P(current);
		trigger->event->stop(trigger->event);
	} ZEND_HASH_FOREACH_END();
}

void start_graceful_shutdown(void)
{
	if (ZEND_ASYNC_GRACEFUL_SHUTDOWN) {
		return;
	}

	ZEND_ASYNC_GRACEFUL_SHUTDOWN = true;

	// If the exit exception is not defined, we will define it.
	if (EG(exception) == NULL && ZEND_ASYNC_EXIT_EXCEPTION == NULL) {
		async_throw_error("Graceful shutdown mode is activated");
	}

	if (EG(exception) != NULL) {
		ZEND_ASYNC_EXIT_EXCEPTION = EG(exception);
		GC_ADDREF(EG(exception));
		zend_clear_exception();
	}

	cancel_queued_coroutines();

	if (UNEXPECTED(EG(exception) != NULL)) {
		zend_exception_set_previous(EG(exception), ZEND_ASYNC_EXIT_EXCEPTION);
		GC_DELREF(ZEND_ASYNC_EXIT_EXCEPTION);
		ZEND_ASYNC_EXIT_EXCEPTION = EG(exception);
		GC_ADDREF(EG(exception));
		zend_clear_exception();
	}

	// After exiting this function, EG(exception) must be 100% clean.
}

static void finally_shutdown(void)
{
	if (ZEND_ASYNC_EXIT_EXCEPTION != NULL && EG(exception) != NULL) {
		zend_exception_set_previous(EG(exception), ZEND_ASYNC_EXIT_EXCEPTION);
		GC_DELREF(ZEND_ASYNC_EXIT_EXCEPTION);
		ZEND_ASYNC_EXIT_EXCEPTION = EG(exception);
		GC_ADDREF(EG(exception));
		zend_clear_exception();
	}

	cancel_queued_coroutines();
	execute_queued_coroutines();

	execute_microtasks();

	if (UNEXPECTED(EG(exception))) {
		if (ZEND_ASYNC_EXIT_EXCEPTION != NULL) {
			zend_exception_set_previous(EG(exception), ZEND_ASYNC_EXIT_EXCEPTION);
			GC_DELREF(ZEND_ASYNC_EXIT_EXCEPTION);
			ZEND_ASYNC_EXIT_EXCEPTION = EG(exception);
			GC_ADDREF(EG(exception));
		}
	}
}

void async_scheduler_main_loop(void);

#define TRY_HANDLE_EXCEPTION() \
	if (UNEXPECTED(EG(exception) != NULL)) { \
	    if(ZEND_ASYNC_GRACEFUL_SHUTDOWN) { \
			finally_shutdown(); \
            break; \
        } \
		start_graceful_shutdown(); \
	}

/**
 * The main loop of the scheduler.
 */
void async_scheduler_launch(void)
{
	if (ZEND_ASYNC_SCHEDULER) {
		async_throw_error("The scheduler cannot be started when is already enabled");
		return;
	}

	if (false == zend_async_reactor_is_enabled()) {
		async_throw_error("The scheduler cannot be started without the Reactor");
		return;
	}

	ZEND_ASYNC_REACTOR_STARTUP();

	if (UNEXPECTED(EG(exception) != NULL)) {
		return;
	}

	//
	// We convert the current main execution flow into the main coroutine.
	// The main coroutine differs from others in that it is already started, and its handle is NULL.
	// We also carefully normalize the state of the main coroutine as
	// if it had actually been started via the spawn function.
	//

	if (ZEND_ASYNC_MAIN_SCOPE == NULL) {
		ZEND_ASYNC_MAIN_SCOPE = async_new_scope(NULL);

		if (UNEXPECTED(EG(exception) != NULL)) {
			return;
		}
	}

	if (UNEXPECTED(EG(exception) != NULL)) {
		return;
	}

	zend_async_scope_t * scope = ZEND_ASYNC_MAIN_SCOPE;
	async_coroutine_t * main_coroutine = (async_coroutine_t *) ZEND_ASYNC_NEW_COROUTINE(scope);

	if (UNEXPECTED(EG(exception) != NULL)) {
		return;
	}

	if (UNEXPECTED(main_coroutine == NULL)) {
		async_throw_error("Failed to create the main coroutine");
		return;
	}

	zval options;
	ZVAL_UNDEF(&options);
	scope->before_coroutine_enqueue(&main_coroutine->coroutine, scope, &options);
	zval_dtor(&options);

	if (UNEXPECTED(EG(exception) != NULL)) {
		return;
	}

	scope->after_coroutine_enqueue(&main_coroutine->coroutine, scope);
	if (UNEXPECTED(EG(exception) != NULL)) {
		return;
	}

	zend_async_call_main_coroutine_start_handlers(&main_coroutine->coroutine);
	if (UNEXPECTED(EG(exception))) {
		return;
	}

	// Copy the main coroutine context
	main_coroutine->context = *EG(main_fiber_context);
	// Set the current fiber context to the main coroutine context
	EG(current_fiber_context) = &main_coroutine->context;

	zend_fiber_switch_blocked();

	// Normalize the main coroutine state
	ZEND_COROUTINE_SET_STARTED(&main_coroutine->coroutine);
	ZEND_COROUTINE_SET_MAIN(&main_coroutine->coroutine);

	if (UNEXPECTED(zend_hash_index_add_ptr(&ASYNC_G(coroutines), main_coroutine->std.handle, main_coroutine) == NULL)) {
		async_throw_error("Failed to add the main coroutine to the list");
		return;
	}

	ZEND_ASYNC_INCREASE_COROUTINE_COUNT;
	ZEND_ASYNC_CURRENT_COROUTINE = &main_coroutine->coroutine;

	// The current execution context is the main coroutine,
	// to which we must return after the Scheduler completes.
	zend_fiber_transfer *main_transfer = ecalloc(1, sizeof(zend_fiber_transfer));
	main_transfer->context = EG(main_fiber_context);
	main_transfer->flags = 0;
	ZVAL_NULL(&main_transfer->value);

	ASYNC_G(main_transfer) = main_transfer;
	ASYNC_G(main_vm_stack) = EG(vm_stack);

	zend_coroutine_t * scheduler_coroutine = ZEND_ASYNC_NEW_COROUTINE(NULL);
	if (UNEXPECTED(EG(exception) != NULL)) {
		return;
	}

	if (UNEXPECTED(scheduler_coroutine == NULL)) {
		async_throw_error("Failed to create the scheduler coroutine");
		return;
	}

	scheduler_coroutine->internal_entry = async_scheduler_main_loop;
	ZEND_ASYNC_SCHEDULER = scheduler_coroutine;
	ZEND_ASYNC_ACTIVATE;
}

/**
 * A special function that is called when the main coroutine permanently loses the execution flow.
 * Exiting this function means that the entire PHP script has finished.
 */
void async_scheduler_main_coroutine_suspend(void)
{
	if (UNEXPECTED(ZEND_ASYNC_SCHEDULER == NULL)) {
		async_scheduler_launch();

		if (UNEXPECTED(EG(exception) != NULL)) {
			return;
		}
	}

	async_coroutine_t * coroutine = (async_coroutine_t *)ZEND_ASYNC_CURRENT_COROUTINE;
	zend_fiber_transfer * transfer = ASYNC_G(main_transfer);

	// We reach this point when the main coroutine has completed its execution.
	async_coroutine_finalize(transfer, coroutine);

	coroutine->context.cleanup = NULL;

	OBJ_RELEASE(&coroutine->std);

	//
	// At this point, we transfer control to the Scheduler coroutine.
	// Although this code performs 1–2 extra context switches,
	// it helps normalize the coroutine switching logic.
	//

	// Since the main coroutine has just finished its execution,
	// we must normalize the state of EG(current_fiber_context)
	// so that on the next switch we return to this exact point.
	EG(current_fiber_context) = transfer->context;

	switch_to_scheduler(NULL);

	if (ASYNC_G(main_transfer)) {
		efree(ASYNC_G(main_transfer));
		ASYNC_G(main_transfer) = NULL;
	}

	// The main Scope object must already be destroyed at this point.
	// Here we additionally remove the dead reference to it to avoid any ambiguous state.
	ZEND_ASYNC_MAIN_SCOPE = NULL;

	//
	// By leaving this function, we terminate the execution of the PHP script.
	// This is the exit point for ASYNC.
	//

	zend_object * exit_exception = ZEND_ASYNC_EXIT_EXCEPTION;
	ZEND_ASYNC_EXIT_EXCEPTION = NULL;

	//
	// Before exiting completely, we rethrow the exit exception
	// that was raised somewhere in other coroutines.
	//
	if (EG(exception) != NULL && exit_exception != NULL) {
		zend_exception_set_previous(EG(exception), exit_exception);
		GC_DELREF(exit_exception);
	} else if (exit_exception != NULL) {
		zend_throw_exception_internal(exit_exception);
	}
}

#define TRY_HANDLE_SUSPEND_EXCEPTION() \
	if (UNEXPECTED(EG(exception) != NULL)) { \
		if(ZEND_ASYNC_GRACEFUL_SHUTDOWN) { \
			finally_shutdown(); \
			return; \
		} \
		start_graceful_shutdown(); \
	}

void async_scheduler_coroutine_enqueue(zend_coroutine_t * coroutine)
{
	/**
	 * Note that the Scheduler is initialized after the first use of suspend,
	 * not at the start of the Zend engine.
	 */
	if (UNEXPECTED(coroutine == NULL && ZEND_ASYNC_SCHEDULER == NULL)) {
		async_scheduler_launch();

		if (UNEXPECTED(EG(exception) != NULL)) {
			return;
		}

		coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
		ZEND_ASSERT(coroutine != NULL && "The current coroutine must be initialized");
	}

	// If the transfer is NULL, it means that the coroutine is being resumed
	// That’s why we’re adding it to the queue.
	// coroutine->waker->status != ZEND_ASYNC_WAKER_WAITING means not need to add to queue twice
	if (coroutine != NULL
		&& (coroutine->waker == NULL
			|| (coroutine->waker != NULL
				&& coroutine->waker->status != ZEND_ASYNC_WAKER_WAITING)
			)
	) {
		if (coroutine->waker == NULL) {
			zend_async_waker_t *waker = zend_async_waker_new(coroutine);
			if (UNEXPECTED(EG(exception))) {
				async_throw_error("Failed to create waker for coroutine");
				return;
			}

			coroutine->waker = waker;
		}

		coroutine->waker->status = ZEND_ASYNC_WAKER_QUEUED;

		if (UNEXPECTED(circular_buffer_push(&ASYNC_G(coroutine_queue), &coroutine, true)) == FAILURE) {
			async_throw_error("Failed to enqueue coroutine");
		}

		//
		// We stop all events as soon as the coroutine is ready to run.
		//
		async_scheduler_stop_waker_events(coroutine->waker);
	}
}

void async_scheduler_coroutine_suspend(zend_fiber_transfer *transfer)
{
	ZEND_ASSERT(EG(exception) == NULL && "The current exception must be NULL");

	/**
	 * Note that the Scheduler is initialized after the first use of suspend,
	 * not at the start of the Zend engine.
	 */
	if (UNEXPECTED(ZEND_ASYNC_SCHEDULER == NULL)) {
		async_scheduler_launch();

		if (UNEXPECTED(EG(exception) != NULL)) {
			return;
		}
	}

	ZEND_ASYNC_SCHEDULER_HEARTBEAT;

	zend_coroutine_t * coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	//
	// Before suspending the coroutine,
	// we start all its Waker-events.
	// This causes timers to start, POLL objects to begin waiting for events, and so on.
	//
	if (transfer == NULL && coroutine != NULL && coroutine->waker != NULL) {
		async_scheduler_start_waker_events(coroutine->waker);

		// If an exception occurs during the startup of the Waker object,
		// that exception belongs to the current coroutine,
		// which means we have the right to immediately return to the point from which we were called.
		if (UNEXPECTED(EG(exception) != NULL)) {
			// Before returning, We are required to properly destroy the Waker object.
			zend_exception_save();
			async_scheduler_stop_waker_events(coroutine->waker);
			zend_async_waker_destroy(coroutine);
			zend_exception_restore();
			return;
		}
	}

	if (UNEXPECTED(coroutine->switch_handlers)) {
		ZEND_COROUTINE_LEAVE(coroutine);
		ZEND_ASSERT(EG(exception) == NULL && "The exception after ZEND_COROUTINE_LEAVE must be NULL");
	}

	//
	// The async_scheduler_coroutine_suspend function is called
	// with the transfer parameter not null when the current coroutine finishes execution.
	// This means that the transfer structure may contain an exception object
	// if the coroutine ended with an error.
	// We are required to handle this situation.
	//
	if (UNEXPECTED(transfer != NULL && transfer->flags & ZEND_FIBER_TRANSFER_FLAG_ERROR)) {

		zend_object * exception = Z_OBJ(transfer->value);
		ZEND_ASSERT(Z_TYPE(transfer->value) == IS_OBJECT && "The transfer value must be an exception object");

		transfer->flags = 0; // Reset the flags to avoid reprocessing the exception
		ZVAL_NULL(&transfer->value); // Reset the transfer value to avoid memory leaks

		if (ZEND_ASYNC_EXIT_EXCEPTION != NULL) {
			zend_exception_set_previous(exception, ZEND_ASYNC_EXIT_EXCEPTION);
			GC_DELREF(ZEND_ASYNC_EXIT_EXCEPTION);
			ZEND_ASYNC_EXIT_EXCEPTION = exception;
		} else {
			ZEND_ASYNC_EXIT_EXCEPTION = exception;
		}

		if(ZEND_ASYNC_GRACEFUL_SHUTDOWN) {
			finally_shutdown();
		} else {
			start_graceful_shutdown();
		}

		switch_to_scheduler(transfer);
		return;
	}

	ZEND_ASYNC_SCHEDULER_CONTEXT = true;

	execute_microtasks();
	TRY_HANDLE_SUSPEND_EXCEPTION();

	const bool has_handles = ZEND_ASYNC_REACTOR_EXECUTE(circular_buffer_is_not_empty(&ASYNC_G(coroutine_queue)));
	TRY_HANDLE_SUSPEND_EXCEPTION();

	execute_microtasks();

	ZEND_ASYNC_SCHEDULER_CONTEXT = false;

	TRY_HANDLE_SUSPEND_EXCEPTION();

	const bool is_next_coroutine = circular_buffer_is_not_empty(&ASYNC_G(coroutine_queue));

	if (UNEXPECTED(
		false == has_handles
		&& false == is_next_coroutine
		&& ZEND_ASYNC_ACTIVE_COROUTINE_COUNT > 0
		&& circular_buffer_is_empty(&ASYNC_G(microtasks))
		&& resolve_deadlocks()
		)) {
			switch_to_scheduler(transfer);
		}

	if (EXPECTED(is_next_coroutine)) {
		//
		// The execute_next_coroutine() may fail to transfer control to another coroutine for various reasons.
		// In that case, it returns false, and we are then required to yield control to the scheduler.
		//
		if (false == execute_next_coroutine(transfer) && EG(exception) == NULL) {
			switch_to_scheduler(transfer);
		}
	} else {
		switch_to_scheduler(transfer);
	}

	if (UNEXPECTED(coroutine->switch_handlers && transfer == NULL)) {
		ZEND_COROUTINE_ENTER(coroutine);
	}
}

void async_scheduler_main_loop(void)
{
	zend_try
	{
		bool has_handles = true;
		bool has_next_coroutine = true;
		bool was_executed = false;

		do {

			ZEND_ASYNC_SCHEDULER_HEARTBEAT;

			ZEND_ASYNC_SCHEDULER_CONTEXT = true;

			execute_microtasks();
			TRY_HANDLE_EXCEPTION();

			has_next_coroutine = circular_buffer_is_not_empty(&ASYNC_G(coroutine_queue));
			has_handles = ZEND_ASYNC_REACTOR_EXECUTE(has_next_coroutine);
			TRY_HANDLE_EXCEPTION();

			execute_microtasks();
			TRY_HANDLE_EXCEPTION();

			ZEND_ASYNC_SCHEDULER_CONTEXT = false;

			if (EXPECTED(has_next_coroutine)) {
				was_executed = execute_next_coroutine(NULL);
			} else {
				was_executed = false;
			}

			TRY_HANDLE_EXCEPTION();

			if (UNEXPECTED(
				false == has_handles
				&& false == was_executed
				&& ZEND_ASYNC_ACTIVE_COROUTINE_COUNT > 0
				&& circular_buffer_is_empty(&ASYNC_G(coroutine_queue))
				&& circular_buffer_is_empty(&ASYNC_G(microtasks))
				&& resolve_deadlocks()
				)) {
					break;
				}

		} while (zend_hash_num_elements(&ASYNC_G(coroutines)) > 0
			|| circular_buffer_is_not_empty(&ASYNC_G(microtasks))
			|| ZEND_ASYNC_REACTOR_LOOP_ALIVE()
		);

	} zend_catch {
		dispose_coroutines();
		async_scheduler_dtor();
		zend_bailout();
	} zend_end_try();

	ZEND_ASSERT(ZEND_ASYNC_REACTOR_LOOP_ALIVE() == false && "The event loop must be stopped");

	zend_object * exit_exception = ZEND_ASYNC_EXIT_EXCEPTION;

	async_scheduler_dtor();

	if (EG(exception) != NULL && exit_exception != NULL) {
		zend_exception_set_previous(EG(exception), exit_exception);
		GC_DELREF(exit_exception);
		exit_exception = EG(exception);
		GC_ADDREF(exit_exception);
		zend_clear_exception();
	}

	// Here we are guaranteed to exit the coroutine without exceptions.
}