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

		task->ref_count--;

		if (task->ref_count <= 0) {
			task->dtor(task);
		}

		if (UNEXPECTED(EG(exception) != NULL)) {
			return;
		}
	}
}

static zend_always_inline void define_transfer(async_coroutine_t *coroutine, zend_object * exception, zend_fiber_transfer *transfer)
{
	if (UNEXPECTED(coroutine->context.handle == NULL
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

static zend_always_inline void switch_context(async_coroutine_t *coroutine, zend_object * exception)
{
	zend_fiber_transfer transfer = {
		.context = &coroutine->context,
		.flags = exception != NULL ? ZEND_FIBER_TRANSFER_FLAG_ERROR : 0,
	};

	if (coroutine->context.handle == NULL
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

static bool execute_next_coroutine(zend_fiber_transfer *transfer)
{
	async_coroutine_t *async_coroutine = next_coroutine();
	zend_coroutine_t *coroutine = &async_coroutine->coroutine;

	if (UNEXPECTED(coroutine == NULL)) {
		return false;
	}

	if (UNEXPECTED(coroutine->waker == NULL)) {
		coroutine->event.dispose(&coroutine->event);
		return true;
	}

	zend_async_waker_t * waker = coroutine->waker;

	if (UNEXPECTED(waker->status == ZEND_ASYNC_WAKER_IGNORED)) {

		//
		// This state triggers if the fiber has never been started;
		// in this case, it is deallocated differently than usual.
		// Finalizing handlers are called. Memory is freed in the correct order!
		//
		coroutine->event.dispose(&coroutine->event);
		return true;
	}

	if (UNEXPECTED(waker->status == ZEND_ASYNC_WAKER_WAITING)) {
		zend_error(E_ERROR, "Attempt to resume a fiber that has not been resolved");
		coroutine->event.dispose(&coroutine->event);
		return false;
	}

	waker->status = ZEND_ASYNC_WAKER_NO_STATUS;

	zend_object * error = waker->error;
	waker->error = NULL;
	zend_async_waker_destroy(coroutine);

	if (transfer != NULL) {
		define_transfer(async_coroutine, error, transfer);
		return true;
	} else {
		switch_context(async_coroutine, error);
	}

	//
	// At this point, the async_coroutine must already be destroyed
	//

	if (error != NULL) {
		OBJ_RELEASE(error);
	}

	// Ignore the exception if it is a cancellation exception
	if (UNEXPECTED(EG(exception) && instanceof_function(EG(exception)->ce, async_ce_cancellation_exception))) {
        zend_clear_exception();
    }

	return true;
}

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

	ZEND_ASYNC_REACTOR_SHUTDOWN();

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
			coroutine->event.dispose(&coroutine->event);
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

void start_graceful_shutdown(void)
{
	if (ZEND_ASYNC_GRACEFUL_SHUTDOWN) {
		return;
	}

	if (EG(exception) == NULL) {
		async_throw_error("Graceful shutdown mode is activated");
	}

	ZEND_ASYNC_GRACEFUL_SHUTDOWN = true;
	ZEND_ASYNC_EXIT_EXCEPTION = EG(exception);
	GC_ADDREF(EG(exception));

	zend_clear_exception();
	cancel_queued_coroutines();

	if (UNEXPECTED(EG(exception) != NULL)) {
		zend_exception_set_previous(EG(exception), ZEND_ASYNC_EXIT_EXCEPTION);
		GC_DELREF(ZEND_ASYNC_EXIT_EXCEPTION);
		ZEND_ASYNC_EXIT_EXCEPTION = EG(exception);
		GC_ADDREF(EG(exception));
		zend_clear_exception();
	}
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

static void async_scheduler_launch(void);
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

	async_coroutine_t * main_coroutine = (async_coroutine_t *) ZEND_ASYNC_NEW_COROUTINE(NULL);

	if (UNEXPECTED(EG(exception) != NULL)) {
		return;
	}

	if (UNEXPECTED(main_coroutine == NULL)) {
		async_throw_error("Failed to create the main coroutine");
		return;
	}

	// Copy the main coroutine context
	main_coroutine->context = *EG(main_fiber_context);
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

	async_scheduler_coroutine_suspend(NULL);

	if (ASYNC_G(main_transfer)) {
		efree(ASYNC_G(main_transfer));
		ASYNC_G(main_transfer) = NULL;
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
		execute_next_coroutine(transfer);
	} else {
		switch_to_scheduler(transfer);
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
	ZEND_ASYNC_EXIT_EXCEPTION = NULL;

	async_scheduler_dtor();

	if (EG(exception) != NULL && exit_exception != NULL) {
		zend_exception_set_previous(EG(exception), exit_exception);
		GC_DELREF(exit_exception);
		exit_exception = EG(exception);
		GC_ADDREF(exit_exception);
		zend_clear_exception();
	}

	if (exit_exception != NULL) {
		zend_throw_exception_internal(exit_exception);
	}
}