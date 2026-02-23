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
#include "zend_observer.h"

///////////////////////////////////////////////////////////
/// STATIC DECLARATIONS AND CONSTANTS
///////////////////////////////////////////////////////////

#define FIBER_DEBUG_LOG_ON false
#define FIBER_DEBUG_SWITCH false
#if FIBER_DEBUG_LOG_ON
#define FIBER_DEBUG(...) fprintf(stdout, __VA_ARGS__)
#else
#define FIBER_DEBUG(...) ((void) 0)
#endif

static zend_function root_function = { ZEND_INTERNAL_FUNCTION };

typedef enum
{
	COROUTINE_NOT_EXISTS,
	COROUTINE_SWITCHED,
	COROUTINE_IGNORED,
	COROUTINE_FINISHED,
	SHOULD_BE_EXIT
} switch_status;

static ZEND_STACK_ALIGNED void fiber_entry(zend_fiber_transfer *transfer);
static void fiber_context_cleanup(zend_fiber_context *context);

#define TRY_HANDLE_EXCEPTION() \
	if (UNEXPECTED(EG(exception) != NULL)) { \
		if (ZEND_ASYNC_GRACEFUL_SHUTDOWN) { \
			finally_shutdown(); \
			break; \
		} \
		start_graceful_shutdown(); \
	}

#define TRY_HANDLE_SUSPEND_EXCEPTION() \
	if (UNEXPECTED(EG(exception) != NULL)) { \
		if (ZEND_ASYNC_GRACEFUL_SHUTDOWN) { \
			finally_shutdown(); \
			switch_to_scheduler(transfer); \
			zend_exception_restore_fast(exception_ptr, prev_exception_ptr); \
			return; \
		} \
		start_graceful_shutdown(); \
	}

///////////////////////////////////////////////////////////
/// MODULE INIT/SHUTDOWN
///////////////////////////////////////////////////////////

void async_scheduler_startup(void)
{
}

void async_scheduler_shutdown(void)
{
}

///////////////////////////////////////////////////////////
/// FIBER CONTEXT MANAGEMENT
///////////////////////////////////////////////////////////

static void fiber_context_cleanup(zend_fiber_context *context)
{
	async_fiber_context_t *fiber_context = (async_fiber_context_t *) context;

	// There's no need to destroy execute_data
	// because it's also located in the fiber's stack.
	efree(fiber_context);
}

async_fiber_context_t *async_fiber_context_create(void)
{
	async_fiber_context_t *context = ecalloc(1, sizeof(async_fiber_context_t));

	if (zend_fiber_init_context(&context->context, async_ce_coroutine, fiber_entry, EG(fiber_stack_size)) == FAILURE) {
		efree(context);
		return NULL;
	}

	context->flags = ZEND_FIBER_STATUS_INIT;
	context->context.cleanup = fiber_context_cleanup;

	return context;
}

static zend_always_inline void fiber_pool_init(void)
{
	circular_buffer_ctor(&ASYNC_G(fiber_context_pool), ASYNC_FIBER_POOL_SIZE, sizeof(async_fiber_context_t *), NULL);
}

static void fiber_pool_cleanup(void)
{
	async_fiber_context_t *fiber_context = NULL;

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	ZEND_ASYNC_CURRENT_COROUTINE = NULL;

	while (circular_buffer_pop_ptr(&ASYNC_G(fiber_context_pool), (void **) &fiber_context) == SUCCESS) {
		if (fiber_context != NULL) {
			zend_fiber_transfer transfer = { .context = &fiber_context->context, .flags = 0 };

			// If the current coroutine is NULL, this state explicitly tells the fiber to stop execution.
			// We set this value each time, since the switch_to_scheduler function may change it.
			ZEND_ASYNC_CURRENT_COROUTINE = NULL;
			zend_fiber_switch_context(&transfer);

			// Transfer the exception to the current coroutine.
			if (UNEXPECTED(transfer.flags & ZEND_FIBER_TRANSFER_FLAG_ERROR)) {
				async_rethrow_exception(Z_OBJ(transfer.value));
				ZVAL_NULL(&transfer.value);
			}
		}
	}

	ZEND_ASYNC_CURRENT_COROUTINE = coroutine;

	circular_buffer_dtor(&ASYNC_G(fiber_context_pool));
}

///////////////////////////////////////////////////////////
/// MICROTASK EXECUTION
///////////////////////////////////////////////////////////

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

///////////////////////////////////////////////////////////
/// FIBER CONTEXT SWITCHING UTILITIES
///////////////////////////////////////////////////////////

static zend_always_inline void fiber_context_update_before_suspend(void)
{
	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_CURRENT_COROUTINE;

	if (coroutine != NULL && coroutine->fiber_context != NULL) {
		coroutine->fiber_context->execute_data = EG(current_execute_data);
	}
}

/**
 * Transfers the current exception (EG(exception)) to the transfer object.
 *
 * @param transfer Transfer object that will hold the transfer information.
 */
static zend_always_inline void transfer_current_exception(zend_fiber_transfer *transfer)
{
	if (EXPECTED(EG(exception) == NULL)) {
		transfer->flags &= ~ZEND_FIBER_TRANSFER_FLAG_ERROR;
		return;
	}

	zend_object *exception = EG(exception);
	GC_ADDREF(exception);
	zend_clear_exception();

	transfer->flags |= ZEND_FIBER_TRANSFER_FLAG_ERROR;
	ZVAL_OBJ(&transfer->value, exception);
}

/**
 * Switches control from the current fiber to the coroutine's fiber.
 * The coroutine's fiber must be defined!
 *
 * @param coroutine The coroutine to switch to.
 */
static zend_always_inline void fiber_switch_context(async_coroutine_t *coroutine)
{
	async_fiber_context_t *fiber_context = coroutine->fiber_context;

	ZEND_ASSERT(fiber_context != NULL && "Fiber context is NULL in fiber_switch_context");

	zend_fiber_transfer transfer = { .context = &fiber_context->context, .flags = 0 };

#if FIBER_DEBUG_SWITCH
	zend_fiber_context *from = EG(current_fiber_context);
	zend_fiber_context *to = &coroutine->fiber_context->context;

	if (ZEND_ASYNC_SCHEDULER == &coroutine->coroutine) {
		FIBER_DEBUG("Switch fiber: %p => %p for scheduler: %p\n", from, to, coroutine);
	} else {
		FIBER_DEBUG("Switch fiber: %p => %p for coroutine: %p\n", from, to, coroutine);
	}
#endif

	zend_fiber_switch_context(&transfer);

	/* Forward bailout into current coroutine. */
	if (UNEXPECTED(transfer.flags & ZEND_FIBER_TRANSFER_FLAG_BAILOUT)) {
		ZEND_ASYNC_CURRENT_COROUTINE = NULL;
		zend_bailout();
	}

	// Transfer the exception to the current coroutine.
	if (UNEXPECTED(transfer.flags & ZEND_FIBER_TRANSFER_FLAG_ERROR)) {
		async_rethrow_exception(Z_OBJ(transfer.value));
		ZVAL_NULL(&transfer.value);
	}
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
		// In case the control is transferred to the Scheduler,
		// the bailout flag must be cleared so that the Scheduler continues to operate normally.
		// In other words, critical exceptions should not cause the Scheduler to terminate fatally.
		transfer->flags &= ~ZEND_FIBER_TRANSFER_FLAG_BAILOUT;
		transfer->context = &async_coroutine->fiber_context->context;
		transfer_current_exception(transfer);
		ZEND_ASYNC_CURRENT_COROUTINE = &async_coroutine->coroutine;
	} else {
		fiber_context_update_before_suspend();
		ZEND_ASYNC_CURRENT_COROUTINE = &async_coroutine->coroutine;
		fiber_switch_context(async_coroutine);
	}
}

static zend_always_inline void return_to_main(zend_fiber_transfer *transfer)
{
	transfer->context = ASYNC_G(main_transfer)->context;
	transfer_current_exception(transfer);
}

///////////////////////////////////////////////////////////
/// COROUTINE QUEUE MANAGEMENT
///////////////////////////////////////////////////////////

static zend_always_inline void process_resumed_coroutines(void)
{
	circular_buffer_t *resumed_queue = &ASYNC_G(resumed_coroutines);
	zend_coroutine_t *coroutine = NULL;

	while (circular_buffer_pop_ptr(resumed_queue, (void **) &coroutine) == SUCCESS) {
		if (EXPECTED(coroutine != NULL && coroutine->waker != NULL)) {
			ZEND_ASYNC_WAKER_CLEAN_EVENTS(coroutine->waker);
		}
	}
}

static zend_always_inline async_coroutine_t *next_coroutine(void)
{
	async_coroutine_t *coroutine;

	if (UNEXPECTED(circular_buffer_pop_ptr(&ASYNC_G(coroutine_queue), (void **) &coroutine) == FAILURE)) {
		ZEND_ASSERT("Failed to pop the coroutine from the pending queue.");
		return NULL;
	}

	return coroutine;
}

static zend_always_inline bool return_fiber_to_pool(async_fiber_context_t *fiber_context)
{
	circular_buffer_t *buffer = &ASYNC_G(fiber_context_pool);

	if (buffer->capacity > 0 && false == circular_buffer_is_full(buffer)) {
		if (EXPECTED(circular_buffer_push_ptr(buffer, fiber_context) != FAILURE)) {
			return true;
		}

		async_throw_error("Failed to push fiber context to the pool");
		return false;
	}

	return false;
}

/**
 * Executes the next coroutine in the queue.
 *
 * The function is used when one Fiber switches to another.
 * The execute_next_coroutine_from_fiber function is used
 * when a Fiber is ready to execute another coroutine within itself.
 *
 * @return switch_status - status of the coroutine switching.
 */
static zend_always_inline switch_status execute_next_coroutine(void)
{
	async_coroutine_t *async_coroutine = next_coroutine();

	if (UNEXPECTED(async_coroutine == NULL)) {
		return COROUTINE_NOT_EXISTS;
	}

	zend_coroutine_t *coroutine = &async_coroutine->coroutine;

	// If the current coroutine is the same as the one we are trying to execute,
	if (UNEXPECTED(coroutine == ZEND_ASYNC_CURRENT_COROUTINE)) {
		return COROUTINE_SWITCHED;
	}

	if (async_coroutine->waker.status == ZEND_ASYNC_WAKER_IGNORED) {
		ZEND_ASYNC_CURRENT_COROUTINE = coroutine;
		FIBER_DEBUG("Execute coroutine %p in Fiber %p\n", coroutine, EG(current_fiber_context));
		async_coroutine_execute(async_coroutine);
		ZEND_ASYNC_CURRENT_COROUTINE = NULL;
		return COROUTINE_IGNORED;
	} else if (async_coroutine->fiber_context != NULL) {
		fiber_context_update_before_suspend();
		ZEND_ASYNC_CURRENT_COROUTINE = coroutine;
		fiber_switch_context(async_coroutine);
		return COROUTINE_SWITCHED;
	} else {

		// The coroutine doesn't have its own Fiber,
		// so we first need to allocate a Fiber context for it and then start it.
		circular_buffer_pop_ptr(&ASYNC_G(fiber_context_pool), (void **) &async_coroutine->fiber_context);

		if (async_coroutine->fiber_context == NULL) {
			async_coroutine->fiber_context = async_fiber_context_create();
		}

		fiber_context_update_before_suspend();
		ZEND_ASYNC_CURRENT_COROUTINE = coroutine;
		fiber_switch_context(async_coroutine);
		return COROUTINE_SWITCHED;
	}
}

/**
 * Executes the next coroutine in the queue.
 *
 * This function is used in two different cases:
 * Inside a Fiber that is free to run a coroutine, in which case transfer != NULL.
 * During a suspend operation, when the Fiber is occupied by the current
 * coroutine but needs to switch to another Fiber with a new one.
 *
 * @param transfer A transfer object that is not NULL if the current Fiber has no owning coroutine.
 * @param fiber_context The current Fiber context if available.
 * @return switch_status - status of the coroutine switching.
 */

#define AVAILABLE_FOR_COROUTINE (transfer != NULL)

static zend_always_inline switch_status execute_next_coroutine_from_fiber(zend_fiber_transfer *transfer,
																		  async_fiber_context_t *fiber_context)
{
	async_coroutine_t *async_coroutine = next_coroutine();

	if (UNEXPECTED(async_coroutine == NULL)) {
		return COROUTINE_NOT_EXISTS;
	}

	zend_coroutine_t *coroutine = &async_coroutine->coroutine;

next_coroutine:

	if (async_coroutine->waker.status == ZEND_ASYNC_WAKER_IGNORED) {
		// Case: the coroutine in queue was cancelled.
		// In this case, only the coroutine finalization process needs to be executed,
		// and it can be done immediately.
		ZEND_ASYNC_CURRENT_COROUTINE = coroutine;
		FIBER_DEBUG("Execute coroutine %p in Fiber %p\n", coroutine, EG(current_fiber_context));
		async_coroutine_execute(async_coroutine);
		ZEND_ASYNC_CURRENT_COROUTINE = NULL;
		return COROUTINE_IGNORED;
	}

	if (AVAILABLE_FOR_COROUTINE && async_coroutine->fiber_context == fiber_context) {

		// Case: The current coroutine is assigned to this fiber.
		// Just execute it without switching.
		FIBER_DEBUG("Execute coroutine %p in Fiber %p\n", coroutine, EG(current_fiber_context));
		async_coroutine_execute(async_coroutine);
		return COROUTINE_FINISHED;

	} else if (AVAILABLE_FOR_COROUTINE && async_coroutine->fiber_context != NULL) {

		// Case: the current fiber has no coroutine to execute,
		// but the next coroutine in the queue is already in use.
		if (return_fiber_to_pool(fiber_context)) {
			fiber_context_update_before_suspend();
			ZEND_ASYNC_CURRENT_COROUTINE = coroutine;
			fiber_switch_context(async_coroutine);

			// When control returns to us, we try to execute the coroutine that is currently active.
			coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

			if (UNEXPECTED(coroutine == NULL)) {
				// There are no more coroutines to execute; we need to exit.
				return SHOULD_BE_EXIT;
			}

			async_coroutine = (async_coroutine_t *) coroutine;
			goto next_coroutine;
		} else {
			// The pool is already full, so the Fiber should be destroyed after the switch occurs.
			transfer->context = &async_coroutine->fiber_context->context;
			transfer_current_exception(transfer);
			return SHOULD_BE_EXIT;
		}

	} else if (async_coroutine->fiber_context != NULL) {
		fiber_context_update_before_suspend();
		ZEND_ASYNC_CURRENT_COROUTINE = coroutine;
		fiber_switch_context(async_coroutine);
		return COROUTINE_SWITCHED;
	} else if (AVAILABLE_FOR_COROUTINE && async_coroutine->fiber_context == NULL) {
		// Note that async_coroutine_execute is also called in cases
		// where the coroutine was never executed and was canceled.
		// In this case, no context switch occurs, so this code executes regardless of which fiber it's running in.
		async_coroutine->fiber_context = fiber_context;
		ZEND_ASYNC_CURRENT_COROUTINE = coroutine;
		FIBER_DEBUG("Execute coroutine %p in Fiber %p\n", coroutine, EG(current_fiber_context));
		async_coroutine_execute(async_coroutine);
		return COROUTINE_FINISHED;
	} else {

		// (AVAILABLE_FOR_COROUTINE == false)
		// The coroutine doesn't have its own Fiber,
		// so we first need to allocate a Fiber context for it and then start it.
		circular_buffer_pop_ptr(&ASYNC_G(fiber_context_pool), (void **) &async_coroutine->fiber_context);

		if (async_coroutine->fiber_context == NULL) {
			async_coroutine->fiber_context = async_fiber_context_create();
		}

		fiber_context_update_before_suspend();
		ZEND_ASYNC_CURRENT_COROUTINE = coroutine;
		fiber_switch_context(async_coroutine);
		return COROUTINE_SWITCHED;
	}
}

zend_always_inline static void execute_queued_coroutines(void)
{
	zend_object *saved_exception = NULL;

	while (false == circular_buffer_is_empty(&ASYNC_G(coroutine_queue))) {
		execute_next_coroutine();

		if (UNEXPECTED(EG(exception))) {
			if (saved_exception) {
				zend_exception_set_previous(EG(exception), saved_exception);
			}
			saved_exception = EG(exception);
			EG(exception) = NULL;
		}
	}

	if (UNEXPECTED(saved_exception)) {
		if (EG(exception)) {
			zend_exception_set_previous(EG(exception), saved_exception);
		} else {
			EG(exception) = saved_exception;
		}
	}
}

///////////////////////////////////////////////////////////
/// DEADLOCK RESOLUTION AND ERROR HANDLING
///////////////////////////////////////////////////////////

static void dump_deadlock_info(const zend_long real_coroutines)
{
	if (!ASYNC_G(debug_deadlock)) {
		return;
	}

	php_printf("\n=== DEADLOCK DETECTED: " ZEND_LONG_FMT " coroutines in waiting, "
		"active_events=%u ===\n\n",
		real_coroutines, ZEND_ASYNC_G(active_event_count));

	zval *value;
	ZEND_HASH_FOREACH_VAL(&ASYNC_G(coroutines), value)
	{
		const async_coroutine_t *coroutine = (async_coroutine_t *) Z_PTR_P(value);
		const zend_async_event_t *event = &coroutine->coroutine.event;

		/* Print coroutine info */
		if (event->info != NULL) {
			zend_string *info = event->info((zend_async_event_t *) event);
			php_printf("%s\n", ZSTR_VAL(info));
			zend_string_release(info);
		} else {
			php_printf("Coroutine %d\n", coroutine->std.handle);
		}

		/* Print events the coroutine is waiting for */
		const zend_async_waker_t *waker = coroutine->coroutine.waker;

		if (waker == NULL || waker->events.nNumOfElements == 0) {
			php_printf("  waiting for: <nothing>\n\n");
			continue;
		}

		php_printf("  waiting for:\n");

		zend_async_waker_trigger_t *trigger;
		ZEND_HASH_FOREACH_PTR(&waker->events, trigger)
		{
			if (trigger->event != NULL && trigger->event->info != NULL) {
				zend_string *event_info = trigger->event->info(trigger->event);
				php_printf("    - %s\n", ZSTR_VAL(event_info));
				zend_string_release(event_info);
			} else {
				php_printf("    - <unknown event>\n");
			}
		}
		ZEND_HASH_FOREACH_END();

		php_printf("\n");
	}
	ZEND_HASH_FOREACH_END();

	php_printf("========================================\n\n");
}

static bool resolve_deadlocks(void)
{
	zval *value;

	const zend_long active_coroutines = ZEND_ASYNC_ACTIVE_COROUTINE_COUNT;
	const zend_long real_coroutines = zend_hash_num_elements(&ASYNC_G(coroutines));

	if (active_coroutines > real_coroutines) {
		async_warning("The active coroutine counter contains an incorrect value: %u, real counter: %u.",
					  active_coroutines,
					  real_coroutines);
	}

	if (real_coroutines == 0) {
		return false;
	}

	//
	// Let’s count the number of coroutine-fibers that are in the YIELD state.
	// This state differs from the regular Suspended state in that
	// the Fiber has transferred control back to the parent coroutine.
	//
	zend_long fiber_coroutines_count = 0;

	ZEND_HASH_FOREACH_VAL(&ASYNC_G(coroutines), value)
	{
		const zend_coroutine_t *coroutine = (zend_coroutine_t *) Z_PTR_P(value);

		if (ZEND_COROUTINE_IS_FIBER(coroutine) && ZEND_COROUTINE_IS_YIELD(coroutine) &&
			coroutine->extended_data != NULL) {
			fiber_coroutines_count++;
		}
	}
	ZEND_HASH_FOREACH_END();

	//
	// If all coroutines are fiber coroutines in the SUSPENDED state,
	// we can simply cancel them without creating a deadlock exception.
	//
	if (fiber_coroutines_count == real_coroutines) {
		ZEND_ASYNC_SCHEDULER_CONTEXT = true;
		ZEND_HASH_FOREACH_VAL(&ASYNC_G(coroutines), value)
		{
			zend_coroutine_t *coroutine = (zend_coroutine_t *) Z_PTR_P(value);

			if (ZEND_COROUTINE_IS_FIBER(coroutine) && ZEND_COROUTINE_IS_YIELD(coroutine) &&
				coroutine->extended_data != NULL) {
				ZEND_ASYNC_CANCEL(coroutine, zend_create_graceful_exit(), true);

				if (UNEXPECTED(EG(exception) != NULL)) {
					process_resumed_coroutines();
					ZEND_ASYNC_SCHEDULER_CONTEXT = false;
					return true;
				}
			}
		}
		ZEND_HASH_FOREACH_END();
		process_resumed_coroutines();
		ZEND_ASYNC_SCHEDULER_CONTEXT = false;
		return false;
	}

	dump_deadlock_info(real_coroutines);

	// Create deadlock exception to be set as exit_exception
	zend_object *deadlock_exception =
			async_new_exception(async_ce_deadlock_error,
								"Deadlock detected: no active coroutines, %u coroutines in waiting",
								real_coroutines);

	// Set as exit exception if there isn't one already
	if (ZEND_ASYNC_EXIT_EXCEPTION == NULL) {
		ZEND_ASYNC_EXIT_EXCEPTION = deadlock_exception;
	} else {
		// If there's already an exit exception, make the deadlock exception previous
		zend_exception_set_previous(deadlock_exception, ZEND_ASYNC_EXIT_EXCEPTION);
		ZEND_ASYNC_EXIT_EXCEPTION = deadlock_exception;
	}

	ZEND_ASYNC_SCHEDULER_CONTEXT = true;

	ZEND_HASH_FOREACH_VAL(&ASYNC_G(coroutines), value)
	{
		async_coroutine_t *coroutine = (async_coroutine_t *) Z_PTR_P(value);

		ZEND_ASSERT(coroutine->coroutine.waker != NULL && "The Coroutine has no waker object");

		// In case a deadlock condition is detected, cancellation protection flags no longer apply.
		if (ZEND_COROUTINE_IS_PROTECTED(&coroutine->coroutine)) {
			ZEND_COROUTINE_CLR_PROTECTED(&coroutine->coroutine);
		}

		ZEND_ASYNC_CANCEL(
				&coroutine->coroutine, async_new_exception(async_ce_cancellation_exception, "Deadlock detected"), true);

		if (UNEXPECTED(EG(exception) != NULL)) {
			process_resumed_coroutines();
			ZEND_ASYNC_SCHEDULER_CONTEXT = false;
			return true;
		}
	}
	ZEND_HASH_FOREACH_END();

	process_resumed_coroutines();
	ZEND_ASYNC_SCHEDULER_CONTEXT = false;
	return false;
}

///////////////////////////////////////////////////////////
/// SHUTDOWN AND CLEANUP
///////////////////////////////////////////////////////////
static void cancel_queued_coroutines(void)
{
	zend_object **exception = &EG(exception);
	zend_object *prev_exception_storage = NULL;
	zend_object **prev_exception = &prev_exception_storage;

	zend_exception_save_fast(exception, prev_exception);

	// 1. Walk through all coroutines and cancel them if they are suspended.
	zval *current;

	zend_object *cancellation_exception = async_new_exception(async_ce_cancellation_exception, "Graceful shutdown");

	ZEND_HASH_FOREACH_VAL(&ASYNC_G(coroutines), current)
	{
		zend_coroutine_t *coroutine = Z_PTR_P(current);

		if (false == ZEND_COROUTINE_IS_STARTED(coroutine)) {
			// No need to cancel the fiber if it has not been started.
			coroutine->waker->status = ZEND_ASYNC_WAKER_IGNORED;
			ZEND_COROUTINE_SET_CANCELLED(coroutine);
			coroutine->exception = cancellation_exception;
			GC_ADDREF(cancellation_exception);
		} else {
			// In case a deadlock condition is detected, cancellation protection flags no longer apply.
			if (ZEND_COROUTINE_IS_PROTECTED(coroutine)) {
				ZEND_COROUTINE_CLR_PROTECTED(coroutine);
			}

			ZEND_ASYNC_CANCEL(coroutine, cancellation_exception, false);
		}

		if (*exception) {
			zend_exception_save_fast(exception, prev_exception);
		}
	}
	ZEND_HASH_FOREACH_END();

	OBJ_RELEASE(cancellation_exception);

	zend_exception_restore_fast(exception, prev_exception);
}

bool start_graceful_shutdown(void)
{
	if (ZEND_ASYNC_GRACEFUL_SHUTDOWN) {
		return true;
	}

	ZEND_ASYNC_GRACEFUL_SHUTDOWN = true;

	// If the exit exception is not defined, we will define it.
	if (EG(exception) == NULL && ZEND_ASYNC_EXIT_EXCEPTION == NULL) {
		zend_error(E_CORE_WARNING, "Graceful shutdown mode was started");
	}

	if (EG(exception) != NULL) {
		ZEND_ASYNC_EXIT_EXCEPTION = EG(exception);
		GC_ADDREF(EG(exception));
		zend_clear_exception();
	}

	cancel_queued_coroutines();

	if (UNEXPECTED(EG(exception) != NULL)) {
		zend_exception_set_previous(EG(exception), ZEND_ASYNC_EXIT_EXCEPTION);
		ZEND_ASYNC_EXIT_EXCEPTION = EG(exception);
		GC_ADDREF(EG(exception));
		zend_clear_exception();
	}

	// After exiting this function, EG(exception) must be 100% clean.
	return true;
}

static void finally_shutdown(void)
{
	if (ZEND_ASYNC_EXIT_EXCEPTION != NULL && EG(exception) != NULL) {
		zend_exception_set_previous(EG(exception), ZEND_ASYNC_EXIT_EXCEPTION);
		ZEND_ASYNC_EXIT_EXCEPTION = EG(exception);
		GC_ADDREF(EG(exception));
		zend_clear_exception();
	}

	cancel_queued_coroutines();
	execute_queued_coroutines();

	ZEND_ASYNC_SCHEDULER_CONTEXT = true;
	execute_microtasks();

	if (circular_buffer_is_not_empty(&ASYNC_G(resumed_coroutines))) {
		process_resumed_coroutines();
	}

	ZEND_ASYNC_SCHEDULER_CONTEXT = false;

	if (UNEXPECTED(EG(exception))) {
		if (ZEND_ASYNC_EXIT_EXCEPTION != NULL) {
			zend_exception_set_previous(EG(exception), ZEND_ASYNC_EXIT_EXCEPTION);
			ZEND_ASYNC_EXIT_EXCEPTION = EG(exception);
			GC_ADDREF(EG(exception));
		}
	}
}

static void async_scheduler_dtor(void)
{
	ZEND_ASYNC_SCHEDULER_CONTEXT = true;

	execute_microtasks();

	ZEND_ASYNC_SCHEDULER_CONTEXT = false;

	if (UNEXPECTED(false == circular_buffer_is_empty(&ASYNC_G(microtasks)))) {
		async_warning("%u microtasks were not executed", circular_buffer_count(&ASYNC_G(microtasks)));
	}

	if (UNEXPECTED(false == circular_buffer_is_empty(&ASYNC_G(coroutine_queue)))) {
		async_warning("%u deferred coroutines were not executed", circular_buffer_count(&ASYNC_G(coroutine_queue)));
	}

	// Destroy the scheduler coroutine at the end.
	async_coroutine_t *async_coroutine = (async_coroutine_t *) ZEND_ASYNC_SCHEDULER;
	ZEND_ASYNC_SCHEDULER = NULL;
	async_coroutine->fiber_context = NULL;
	OBJ_RELEASE(&async_coroutine->std);

	zval_c_buffer_cleanup(&ASYNC_G(coroutine_queue));
	zval_c_buffer_cleanup(&ASYNC_G(resumed_coroutines));
	zval_c_buffer_cleanup(&ASYNC_G(microtasks));

	async_coroutine_t *coroutine;
	// foreach by fibers_state and release all fibers
	ZEND_HASH_FOREACH_PTR(&ASYNC_G(coroutines), coroutine)
	{
		OBJ_RELEASE(&coroutine->std);
	}
	ZEND_HASH_FOREACH_END();

	zend_hash_clean(&ASYNC_G(coroutines));
	zend_hash_destroy(&ASYNC_G(coroutines));
	zend_hash_init(&ASYNC_G(coroutines), 0, NULL, NULL, 0);

	ZEND_ASYNC_GRACEFUL_SHUTDOWN = false;
	ZEND_ASYNC_SCHEDULER_CONTEXT = false;
}

///////////////////////////////////////////////////////////
/// WAKER EVENT MANAGEMENT
///////////////////////////////////////////////////////////

static zend_always_inline void start_waker_events(zend_async_waker_t *waker)
{
	ZEND_ASSERT(waker != NULL && "Waker is NULL in async_scheduler_start_waker_events");

	zend_async_waker_trigger_t *trigger;
	ZEND_HASH_FOREACH_PTR(&waker->events, trigger)
	{
		trigger->event->start(trigger->event);
	}
	ZEND_HASH_FOREACH_END();
}

static zend_always_inline void stop_waker_events(zend_async_waker_t *waker)
{
	ZEND_ASSERT(waker != NULL && "Waker is NULL in async_scheduler_stop_waker_events");

	zend_async_waker_trigger_t *trigger;
	ZEND_HASH_FOREACH_PTR(&waker->events, trigger)
	{
		trigger->event->stop(trigger->event);
	}
	ZEND_HASH_FOREACH_END();
}

///////////////////////////////////////////////////////////
/// SCHEDULER CORE
///////////////////////////////////////////////////////////

/**
 * The main loop of the scheduler.
 */
bool async_scheduler_launch(void)
{
	if (ZEND_ASYNC_SCHEDULER) {
		async_throw_error("The scheduler cannot be started when is already enabled");
		return false;
	}

	if (UNEXPECTED(EG(active_fiber))) {
		async_throw_error("The True Async Scheduler cannot be started from within a Fiber");
		return false;
	}

	if (false == zend_async_reactor_is_enabled()) {
		async_throw_error("The scheduler cannot be started without the Reactor");
		return false;
	}

	if (!ZEND_ASYNC_REACTOR_STARTUP()) {
		return false;
	}

	fiber_pool_init();

	if (UNEXPECTED(EG(exception) != NULL)) {
		return false;
	}

	//
	// We convert the current main execution flow into the main coroutine.
	// The main coroutine differs from others in that it is already started, and its handle is NULL.
	// We also carefully normalize the state of the main coroutine as
	// if it had actually been started via the spawn function.
	//

	if (ZEND_ASYNC_MAIN_SCOPE == NULL) {
		ZEND_ASYNC_MAIN_SCOPE = ZEND_ASYNC_NEW_SCOPE(NULL);

		if (ZEND_ASYNC_MAIN_SCOPE == NULL) {
			return false;
		}
	}

	if (UNEXPECTED(EG(exception) != NULL)) {
		return false;
	}

	zend_async_scope_t *scope = ZEND_ASYNC_MAIN_SCOPE;
	async_coroutine_t *main_coroutine = (async_coroutine_t *) ZEND_ASYNC_NEW_COROUTINE(scope);

	if (UNEXPECTED(main_coroutine == NULL)) {
		return false;
	}

	if (UNEXPECTED(main_coroutine == NULL)) {
		async_throw_error("Failed to create the main coroutine");
		return false;
	}

	scope->after_coroutine_enqueue(&main_coroutine->coroutine, scope);
	if (UNEXPECTED(EG(exception) != NULL)) {
		return false;
	}

	// Create a new Fiber context for the main coroutine.
	async_fiber_context_t *fiber_context = ecalloc(1, sizeof(async_fiber_context_t));
	// Copy the main coroutine context
	fiber_context->context = *EG(main_fiber_context);
	fiber_context->execute_data = EG(current_execute_data);

	// Set the current fiber context to the main coroutine context
	EG(current_fiber_context) = &fiber_context->context;
	zend_fiber_context *zend_fiber_context = &fiber_context->context;

	// The main coroutine will always own the fiber, unlike other coroutines.
	main_coroutine->fiber_context = fiber_context;

	zend_fiber_switch_blocked();

	// Normalize the main coroutine state
	ZEND_COROUTINE_SET_STARTED(&main_coroutine->coroutine);
	ZEND_COROUTINE_SET_MAIN(&main_coroutine->coroutine);

	if (UNEXPECTED(zend_hash_index_add_ptr(&ASYNC_G(coroutines), main_coroutine->std.handle, main_coroutine) == NULL)) {
		async_throw_error("Failed to add the main coroutine to the list");
		return false;
	}

	ZEND_ASYNC_INCREASE_COROUTINE_COUNT;
	ZEND_ASYNC_CURRENT_COROUTINE = &main_coroutine->coroutine;

	// The current execution context is the main coroutine,
	// to which we must return after the Scheduler completes.
	zend_fiber_transfer *main_transfer = ecalloc(1, sizeof(zend_fiber_transfer));
	main_transfer->context = EG(main_fiber_context);
	main_transfer->flags = 0;
	ZVAL_NULL(&main_transfer->value);

	//
	// Because the main coroutine is created on the fly, this code is here.
	// At the moment when PHP decides to activate the scheduler,
	// we must normalize the observer's state and notify it that we are creating the main coroutine.
	//
	// This code contains a logical conflict because main_transfer is, in a way, the context of the zero coroutine.
	// It's essentially a switch from the zero context to the coroutine context, even though,
	// logically, both contexts belong to the main execution thread.
	//
	zend_fiber_context->status = ZEND_FIBER_STATUS_INIT;
	zend_observer_fiber_switch_notify(main_transfer->context, zend_fiber_context);
	zend_fiber_context->status = ZEND_FIBER_STATUS_RUNNING;

	ASYNC_G(main_transfer) = main_transfer;
	ASYNC_G(main_vm_stack) = EG(vm_stack);

	zend_coroutine_t *scheduler_coroutine = ZEND_ASYNC_NEW_COROUTINE(NULL);
	if (UNEXPECTED(scheduler_coroutine == NULL)) {
		return false;
	}

	if (UNEXPECTED(scheduler_coroutine == NULL)) {
		async_throw_error("Failed to create the scheduler coroutine");
		return false;
	}

	scope = ZEND_ASYNC_NEW_SCOPE(NULL);
	if (UNEXPECTED(scope == NULL)) {
		return false;
	}

	zval options;
	ZVAL_UNDEF(&options);
	if (!scope->before_coroutine_enqueue(scheduler_coroutine, scope, &options)) {
		zval_ptr_dtor(&options);
		return false;
	}
	zval_ptr_dtor(&options);

	scope->after_coroutine_enqueue(scheduler_coroutine, scope);
	if (UNEXPECTED(EG(exception) != NULL)) {
		return false;
	}

	scheduler_coroutine->internal_entry = NULL;
	((async_coroutine_t *) scheduler_coroutine)->fiber_context = async_fiber_context_create();
	ZEND_ASYNC_SCHEDULER = scheduler_coroutine;
	ZEND_ASYNC_ACTIVATE;

	return zend_async_call_main_coroutine_start_handlers(&main_coroutine->coroutine);
}

/**
 * A special function that is called when the main coroutine permanently loses the execution flow.
 * Exiting this function means that the entire PHP script has finished.
 *
 * This function is needed because the main coroutine runs differently from the others
 * — its logic cycle is broken.
 */
bool async_scheduler_main_coroutine_suspend(void)
{
	bool do_bailout = false;

	if (UNEXPECTED(ZEND_ASYNC_SCHEDULER == NULL)) {
		if (!async_scheduler_launch()) {
			return false;
		}
	}

	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_CURRENT_COROUTINE;
	zend_fiber_transfer *transfer = ASYNC_G(main_transfer);
	zend_fiber_context *fiber_context = &coroutine->fiber_context->context;
	async_fiber_context_t *async_fiber_context = coroutine->fiber_context;
	coroutine->fiber_context = NULL;
	ZEND_ASYNC_CURRENT_COROUTINE = NULL;

	zend_try
	{
		// We reach this point when the main coroutine has completed its execution.
		async_coroutine_finalize(coroutine);
		fiber_context->cleanup = NULL;

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

		// Destroy main Fiber context.
		efree(async_fiber_context);

		switch_to_scheduler(NULL);
	}
	zend_catch
	{
		do_bailout = true;
	}
	zend_end_try();

	ZEND_ASYNC_CURRENT_COROUTINE = NULL;
	ZEND_ASSERT(ZEND_ASYNC_ACTIVE_COROUTINE_COUNT == 0 && "The active coroutine counter must be 0 at this point");
	ZEND_ASYNC_DEACTIVATE;

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

	zend_object *exit_exception = ZEND_ASYNC_EXIT_EXCEPTION;
	ZEND_ASYNC_EXIT_EXCEPTION = NULL;

	if (UNEXPECTED(do_bailout)) {
		if (exit_exception != NULL) {
			OBJ_RELEASE(exit_exception);
		}
		zend_bailout();
	}

	//
	// Before exiting completely, we rethrow the exit exception
	// that was raised somewhere in other coroutines.
	//
	if (EG(exception) != NULL && exit_exception != NULL) {
		if (UNEXPECTED(EG(exception) != exit_exception)) {
			zend_exception_set_previous(EG(exception), exit_exception);
		}
	} else if (exit_exception != NULL) {
		async_rethrow_exception(exit_exception);
	}

	return EG(exception) == NULL;
}

bool async_scheduler_coroutine_enqueue(zend_coroutine_t *coroutine)
{
	/**
	 * Note that the Scheduler is initialized after the first use of suspend,
	 * not at the start of the Zend engine.
	 */
	if (UNEXPECTED(coroutine == NULL && ZEND_ASYNC_SCHEDULER == NULL)) {
		if (!async_scheduler_launch()) {
			return false;
		}

		coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
		ZEND_ASSERT(coroutine != NULL && "The current coroutine must be initialized");
	}

	// If the transfer is NULL, it means that the coroutine is being resumed
	// That's why we're adding it to the queue.
	// coroutine->waker->status != ZEND_ASYNC_WAKER_QUEUED means not need to add to queue twice
	if (coroutine != NULL && (coroutine->waker == NULL || false == ZEND_ASYNC_WAKER_IN_QUEUE(coroutine->waker))) {
		if (coroutine->waker == NULL) {
			zend_async_waker_t *waker = zend_async_waker_new(coroutine);
			if (UNEXPECTED(EG(exception))) {
				async_throw_error("Failed to create waker for coroutine");
				return false;
			}

			coroutine->waker = waker;
		}

		if (UNEXPECTED(circular_buffer_push_ptr_with_resize(&ASYNC_G(coroutine_queue), coroutine) == FAILURE)) {
			async_throw_error("Failed to enqueue coroutine");
		} else {
			coroutine->waker->status = ZEND_ASYNC_WAKER_QUEUED;

			// Behavior for a new coroutine
			// see: async_API.c spawn(zend_async_scope_t *scope, zend_object *scope_provider, int32_t priority)
			if (false == ZEND_COROUTINE_IS_STARTED(coroutine) &&
				zend_hash_index_find(&ASYNC_G(coroutines), ((async_coroutine_t *) coroutine)->std.handle) == NULL) {
				// save the filename and line number where the coroutine was created
				zend_apply_current_filename_and_line(&coroutine->filename, &coroutine->lineno);

				// Notify scope that a new coroutine has been enqueued
				zend_async_scope_t *scope = coroutine->scope;

				if (UNEXPECTED(scope == NULL)) {
					// throw error if the coroutine has no scope
					coroutine->waker->status = ZEND_ASYNC_WAKER_NO_STATUS;
					async_throw_error("The coroutine has no scope assigned");
					return false;
				}

				if (UNEXPECTED(zend_hash_index_add_ptr(&ASYNC_G(coroutines),
													   ((async_coroutine_t *) coroutine)->std.handle,
													   coroutine) == NULL)) {
					coroutine->waker->status = ZEND_ASYNC_WAKER_IGNORED;
					async_throw_error("Failed to add coroutine to the list");
					return false;
				}

				ZEND_ASYNC_INCREASE_COROUTINE_COUNT;

				scope->after_coroutine_enqueue(coroutine, scope);
				if (UNEXPECTED(EG(exception))) {
					coroutine->waker->status = ZEND_ASYNC_WAKER_IGNORED;
					return false;
				}
			}

			// Add to resumed_coroutines queue for event cleanup
			if (ZEND_ASYNC_IS_SCHEDULER_CONTEXT) {
				circular_buffer_push_ptr_with_resize(&ASYNC_G(resumed_coroutines), coroutine);
			}
		}

		//
		// We stop all events as soon as the coroutine is ready to run.
		//
		stop_waker_events(coroutine->waker);
	}

	return true;
}

/**
 * Implements a single tick of the Scheduler
 * and is called from the suspend operation while the context switch has not yet occurred.
 */
static zend_always_inline void scheduler_next_tick(void)
{
	zend_fiber_transfer *transfer = NULL;
	bool *in_scheduler_context = &ZEND_ASYNC_SCHEDULER_CONTEXT;

	*in_scheduler_context = true;

	zend_object **exception_ptr = &EG(exception);
	zend_object *prev_exception = NULL;
	zend_object **prev_exception_ptr = &prev_exception;

	if (ZEND_ASYNC_G(heartbeat_handler) != NULL) {
		ZEND_ASYNC_G(heartbeat_handler)();
		TRY_HANDLE_SUSPEND_EXCEPTION();
		if (circular_buffer_is_not_empty(&ASYNC_G(resumed_coroutines))) {
			process_resumed_coroutines();
		}
	}

	execute_microtasks();
	TRY_HANDLE_SUSPEND_EXCEPTION();

	const uint64_t current_time = zend_hrtime();
	bool has_handles = true;

	if (UNEXPECTED(current_time - ASYNC_G(last_reactor_tick) > REACTOR_CHECK_INTERVAL)) {
		ASYNC_G(last_reactor_tick) = current_time;
		const circular_buffer_t *queue = &ASYNC_G(coroutine_queue);

		has_handles = ZEND_ASYNC_REACTOR_EXECUTE(circular_buffer_is_not_empty(queue));

		TRY_HANDLE_SUSPEND_EXCEPTION();
	}

	*in_scheduler_context = false;

	if (circular_buffer_is_not_empty(&ASYNC_G(resumed_coroutines))) {
		process_resumed_coroutines();
	}

	// Fast return path without context switching...
	const zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine != NULL && coroutine->waker != NULL &&
				   coroutine->waker->status == ZEND_ASYNC_WAKER_RESULT)) {
		return;
	}

	const bool is_next_coroutine = circular_buffer_is_not_empty(&ASYNC_G(coroutine_queue));

	if (UNEXPECTED(false == has_handles && false == is_next_coroutine &&
				   zend_hash_num_elements(&ASYNC_G(coroutines)) > 0 &&
				   circular_buffer_is_empty(&ASYNC_G(microtasks)) &&
				   resolve_deadlocks())) {
		switch_to_scheduler(transfer);
	}

	if (EXPECTED(is_next_coroutine)) {
		//
		// The execute_next_coroutine() may fail to transfer control to another coroutine for various reasons.
		// In that case, it returns false, and we are then required to yield control to the scheduler.
		//
		if (COROUTINE_SWITCHED != execute_next_coroutine() && EG(exception) == NULL) {
			switch_to_scheduler(transfer);
		}
	} else {
		switch_to_scheduler(transfer);
	}
}

bool async_scheduler_coroutine_suspend(void)
{
	//
	// Before suspending the coroutine, we save the current exception state.
	//
	zend_object **exception_ptr = &EG(exception);
	zend_object *prev_exception = NULL;
	zend_object **prev_exception_ptr = &prev_exception;

	zend_exception_save_fast(exception_ptr, prev_exception_ptr);

	/**
	 * Note that the Scheduler is initialized after the first use of suspend,
	 * not at the start of the Zend engine.
	 */
	if (UNEXPECTED(ZEND_ASYNC_SCHEDULER == NULL)) {
		if (!async_scheduler_launch()) {
			return false;
		}
	}

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	//
	// Before suspending the coroutine,
	// we start all its Waker-events.
	// This causes timers to start, POLL objects to begin waiting for events, and so on.
	//
	if (coroutine != NULL && coroutine->waker != NULL) {

		zend_async_waker_t *waker = coroutine->waker;
		const bool not_in_queue = ZEND_ASYNC_WAKER_NOT_IN_QUEUE(waker);

		// Let's check that the coroutine has something to wait for;
		// If a coroutine isn't waiting for anything, it must be in the execution queue.
		// otherwise, it's a potential deadlock.
		if (waker->events.nNumOfElements == 0 && not_in_queue) {
			async_throw_error("The coroutine has no events to wait for");
			zend_async_waker_clean(coroutine);
			zend_exception_restore_fast(exception_ptr, prev_exception_ptr);
			return false;
		}

		// Before starting the events, we change the status of the Waker.
		// This is important because the coroutine may return to the execution queue immediately
		// after the events are initialized.
		if (not_in_queue) {
			waker->status = ZEND_ASYNC_WAKER_WAITING;
		}

		bool *in_scheduler_context = &ZEND_ASYNC_SCHEDULER_CONTEXT;
		bool prev_in_scheduler_context = *in_scheduler_context;

		*in_scheduler_context = true;
		start_waker_events(waker);
		*in_scheduler_context = prev_in_scheduler_context;

		// Fast return path without placing the coroutine in the queue.
		if (UNEXPECTED(waker->status == ZEND_ASYNC_WAKER_RESULT)) {
			zend_hash_clean(&waker->events);
			goto resuming;
		}

		// If an exception occurs during the startup of the Waker object,
		// that exception belongs to the current coroutine,
		// which means we have the right to immediately return to the point from which we were called.
		if (UNEXPECTED(*exception_ptr)) {
			// Before returning, We are required to properly destroy the Waker object.
			zend_exception_save_fast(exception_ptr, prev_exception_ptr);
			stop_waker_events(coroutine->waker);
			zend_async_waker_clean(coroutine);
			zend_exception_restore_fast(exception_ptr, prev_exception_ptr);
			return false;
		}
	}

	if (UNEXPECTED(coroutine->switch_handlers)) {
		ZEND_COROUTINE_LEAVE(coroutine);
		ZEND_ASSERT(EG(exception) == NULL && "The exception after ZEND_COROUTINE_LEAVE must be NULL");
	}

	// Define current filename and line number for the coroutine suspend.
	if (EXPECTED(coroutine->waker)) {
		zend_apply_current_filename_and_line(&coroutine->waker->filename, &coroutine->waker->lineno);
	}

	scheduler_next_tick();

	ZEND_ASYNC_CURRENT_COROUTINE = coroutine;

	if (EXPECTED(coroutine->waker->status == ZEND_ASYNC_WAKER_QUEUED)) {
		coroutine->waker->status = ZEND_ASYNC_WAKER_RESULT;
	}

	if (UNEXPECTED(coroutine->switch_handlers)) {
		ZEND_COROUTINE_ENTER(coroutine);
	}

resuming:

	// Rethrow exception if waker has it
	if (coroutine->waker->error != NULL) {
		zend_object *exception = coroutine->waker->error;
		coroutine->waker->error = NULL;
		async_rethrow_exception(exception);
	}

	zend_exception_restore_fast(exception_ptr, prev_exception_ptr);

	return *exception_ptr == NULL;
}

///////////////////////////////////////////////////////////
/// FIBER ENTRY POINT
///////////////////////////////////////////////////////////

/**
 * The main entry point for the Fiber.
 *
 * Fibers are containers for coroutine execution. A single fiber can run multiple coroutines.
 * There are three types of fibers:
 * Main, Scheduler, and Regular.
 * The Main fiber is the primary execution thread that exists at startup.
 * The Scheduler fiber is responsible solely for managing the event loop.
 * The Regular fiber runs both scheduler tasks and coroutines.
 *
 * @param transfer Control transfer context
 */
ZEND_STACK_ALIGNED void fiber_entry(zend_fiber_transfer *transfer)
{
	ZEND_ASSERT(!transfer->flags && "No flags should be set on initial transfer");

	transfer->context = NULL;

	/* Determine the current error_reporting ini setting. */
	zend_long error_reporting = INI_INT("error_reporting");
	if (!error_reporting && !INI_STR("error_reporting")) {
		error_reporting = E_ALL;
	}

	EG(vm_stack) = NULL;

	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_CURRENT_COROUTINE;
	ZEND_ASSERT(coroutine != NULL && "The current coroutine must be initialized");

	async_fiber_context_t *fiber_context = coroutine->fiber_context;
	ZEND_ASSERT(fiber_context != NULL && "The fiber context must be initialized");
	zend_fiber_context *internal_fiber_context = &fiber_context->context;

	const bool is_scheduler = &coroutine->coroutine == ZEND_ASYNC_SCHEDULER;

	// Allocate VM stack on C stack instead of heap
	char vm_stack_memory[ZEND_FIBER_VM_STACK_SIZE];
	bool *in_scheduler_context = &ZEND_ASYNC_SCHEDULER_CONTEXT;
	zend_async_heartbeat_handler_t *heartbeat_handler = &ZEND_ASYNC_G(heartbeat_handler);

	zend_first_try
	{
		zend_vm_stack stack = (zend_vm_stack) vm_stack_memory;

		// Initialize VM stack structure manually
		// see zend_vm_stack_init()
		stack->top = ZEND_VM_STACK_ELEMENTS(stack);
		stack->end = (zval *) ((char *) vm_stack_memory + ZEND_FIBER_VM_STACK_SIZE);
		stack->prev = NULL;

		// we allocate space for the first call frame, thereby normalizing the stack
		EG(vm_stack) = stack;
		EG(vm_stack_top) = stack->top + ZEND_CALL_FRAME_SLOT;
		EG(vm_stack_end) = stack->end;
		EG(vm_stack_page_size) = ZEND_FIBER_VM_STACK_SIZE;

		zend_execute_data *execute_data = (zend_execute_data *) stack->top;
		memset(execute_data, 0, sizeof(zend_execute_data));

		execute_data->func = &root_function;
		// We store a reference to the first call frame for subsequent VM state switching.
		fiber_context->execute_data = execute_data;

		EG(current_execute_data) = execute_data;
		EG(jit_trace_num) = 0;
		EG(error_reporting) = (int) error_reporting;

#ifdef ZEND_CHECK_STACK_LIMIT
		EG(stack_base) = zend_fiber_stack_base(internal_fiber_context->stack);
		EG(stack_limit) = zend_fiber_stack_limit(internal_fiber_context->stack);
#endif

		if (EXPECTED(false == is_scheduler)) {
			FIBER_DEBUG("Execute primary coroutine %p in Fiber %p\n", coroutine, EG(current_fiber_context));
			async_coroutine_execute(coroutine);
		}

		bool has_handles = true;
		bool has_next_coroutine = true;
		bool was_executed = false;
		switch_status status = COROUTINE_NOT_EXISTS;

		const circular_buffer_t *coroutine_queue = &ASYNC_G(coroutine_queue);
		circular_buffer_t *resumed_coroutines = &ASYNC_G(resumed_coroutines);

		do {

			TRY_HANDLE_EXCEPTION();

			*in_scheduler_context = true;

			ZEND_ASSERT(circular_buffer_is_not_empty(resumed_coroutines) == 0 && "resumed_coroutines should be 0");

			if (*heartbeat_handler) {
				(*heartbeat_handler)();
				TRY_HANDLE_EXCEPTION();
			}

			execute_microtasks();
			TRY_HANDLE_EXCEPTION();

			has_next_coroutine = circular_buffer_count(coroutine_queue) > 0;
			has_handles = ZEND_ASYNC_REACTOR_EXECUTE(has_next_coroutine);

			if (circular_buffer_is_not_empty(resumed_coroutines)) {
				process_resumed_coroutines();
			}

			TRY_HANDLE_EXCEPTION();

			*in_scheduler_context = false;

			if (EXPECTED(has_next_coroutine)) {
				status = execute_next_coroutine_from_fiber(is_scheduler ? NULL : transfer, fiber_context);
				was_executed = status != COROUTINE_NOT_EXISTS;

				if (UNEXPECTED(status == SHOULD_BE_EXIT)) {
					break;
				}

			} else if (is_scheduler) {
				// The scheduler continues running even if there are no coroutines in the queue to execute.
				was_executed = false;
			} else {
				// There are no more coroutines in the execution queue;
				// perhaps we should terminate this Fiber.

				// If the Fiber context pool is not empty, we can return the Fiber context to the pool.
				// and then switch to the scheduler.
				if (return_fiber_to_pool(fiber_context)) {
					switch_to_scheduler(NULL);
					async_coroutine_t *next_coroutine = (async_coroutine_t *) ZEND_ASYNC_CURRENT_COROUTINE;

					if (UNEXPECTED(next_coroutine == NULL)) {
						break;
					}

					next_coroutine->fiber_context = fiber_context;
					FIBER_DEBUG("Execute coroutine %p in Fiber %p\n", next_coroutine, EG(current_fiber_context));
					was_executed = true;
					async_coroutine_execute(next_coroutine);
				} else {
					break;
				}
			}

			TRY_HANDLE_EXCEPTION();

			if (UNEXPECTED(false == has_handles && false == was_executed &&
						   zend_hash_num_elements(&ASYNC_G(coroutines)) > 0 &&
						   circular_buffer_is_empty(coroutine_queue) &&
						   circular_buffer_is_empty(&ASYNC_G(microtasks)) &&
						   resolve_deadlocks())) {
				break;
			}

		} while (zend_hash_num_elements(&ASYNC_G(coroutines)) > 0 ||
				 circular_buffer_is_not_empty(&ASYNC_G(microtasks)) || ZEND_ASYNC_REACTOR_LOOP_ALIVE());
	}
	zend_catch
	{
		fiber_context->flags |= ZEND_FIBER_FLAG_BAILOUT;
		transfer->flags = ZEND_FIBER_TRANSFER_FLAG_BAILOUT;
		*in_scheduler_context = false;
	}
	zend_end_try();

	// At this point, the fiber is finishing and should properly transfer control back.

	// If the fiber's return target is already defined, do nothing.
	if (transfer->context != NULL) {
		return;
	}

	// If the fiber is not the scheduler, we must switch to the scheduler.
	if (false == is_scheduler) {
		// If the fiber is not the scheduler, we must switch to the scheduler.
		// The transfer value must be NULL, as we are not transferring any value.
		switch_to_scheduler(transfer);
		return;
	}

	// It's the scheduler fiber, so we must finalize it.
	ZEND_ASSERT(ZEND_ASYNC_REACTOR_LOOP_ALIVE() == false && "The event loop must be stopped");

	fiber_pool_cleanup();

	zend_object *exit_exception = ZEND_ASYNC_EXIT_EXCEPTION;

	async_scheduler_dtor();

	if (EG(exception) != NULL && exit_exception != NULL) {
		if (UNEXPECTED(EG(exception) != exit_exception)) {
			zend_exception_set_previous(EG(exception), exit_exception);
		}
		exit_exception = EG(exception);
		GC_ADDREF(exit_exception);
		zend_clear_exception();
	} else if (exit_exception != NULL) {
		async_rethrow_exception(exit_exception);
	}

	return_to_main(transfer);

	// Free VM stack
	zend_vm_stack stack = EG(vm_stack);

	// Destroy the VM stack associated with the fiber context.
	// Except for the first segment, which is located directly in the fiber's stack.
	while (stack != NULL && stack->prev != NULL) {
		zend_vm_stack prev = stack->prev;
		efree(stack);
		stack = prev;
	}

	EG(vm_stack) = NULL;
}
