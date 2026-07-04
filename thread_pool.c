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

#include "thread_pool.h"
#include "thread_pool_arginfo.h"
#include "thread.h"
#include "async_API.h"
#include "exceptions.h"
#include "php_async.h"
#include "scheduler.h"
#include "thread_channel.h"
#include "future.h"
#include "zend_common.h"
#include "zend_interfaces.h"
#include "zend_exceptions.h"
#include "zend_closures.h"

zend_class_entry *async_ce_thread_pool = NULL;
zend_class_entry *async_ce_thread_pool_exception = NULL;

static zend_object_handlers thread_pool_handlers;

#define METHOD(name) PHP_METHOD(Async_ThreadPool, name)
#define THIS_POOL() (ASYNC_THREAD_POOL_FROM_OBJ(Z_OBJ_P(ZEND_THIS))->pool)

///////////////////////////////////////////////////////////
/// Pool refcount
///////////////////////////////////////////////////////////

static void thread_pool_destroy(async_thread_pool_t *pool);
static void thread_pool_close(async_thread_pool_t *pool);
static void thread_pool_drain_tasks(async_thread_pool_t *pool, bool reject, zend_object *reject_with);
static bool thread_pool_spawn_task_coroutine(
	async_thread_pool_t *pool, zend_async_scope_t *pool_scope,
	zval *callable,
	zend_fcall_info *fci, zend_fcall_info_cache *fcc,
	zval *params, uint32_t param_count,
	async_thread_snapshot_t *snapshot, zend_future_shared_state_t *state,
	int32_t *active_count, zend_async_trigger_event_t *slot_event);

///////////////////////////////////////////////////////////
/// Worker entry — C handler called inside spawned thread
///////////////////////////////////////////////////////////

/**
 * @brief Worker loop — receives tasks from channel, executes, completes.
 *
 * ctx = async_thread_pool_t* (shared pool).
 *
 * Each task is a 4-element array:
 *   [0] kind        — TASK_KIND_CLOSURE | TASK_KIND_INTERNAL (long)
 *   [1] payload_a   — closure: snapshot_ptr ; internal: handler_ptr
 *   [2] payload_b   — closure: args_array   ; internal: ctx_ptr
 *   [3] state_ptr   — shared future state (long)
 *
 * The discriminator lets the worker dispatch either to the PHP-closure
 * code path (snapshot + zend_call_function) or to a C-handler call
 * (handler(event, ctx)) without changing the channel transport.
 */
#define TASK_SLOT_KIND      0
#define TASK_SLOT_PAYLOAD_A 1
#define TASK_SLOT_PAYLOAD_B 2
#define TASK_SLOT_STATE     3
#define TASK_KIND_CLOSURE   0
#define TASK_KIND_INTERNAL  1

static zend_function worker_root_function = { ZEND_INTERNAL_FUNCTION };

/* Build a ThreadTransferException carrying the current bailout's message.
 * Used when the worker observed a graceful exit()/die() (unwind-exit token) or a
 * fatal-error bailout: the pool delivers this to awaiters instead of re-raising
 * zend_bailout() or passing the token to reject() — either crashes the worker
 * fiber, which can't transfer a non-throwable exit token. */
static zend_object *thread_pool_bailout_exception(void)
{
	const zend_string *msg = PG(last_error_message);
	return async_new_exception(async_ce_thread_transfer_exception, "%s",
		msg != NULL ? ZSTR_VAL(msg)
		            : "ThreadPool worker terminated via exit() or a fatal error");
}

/* Build a clean ThreadTransferException carrying another exception's message.
 * Used for errors thrown deep in the cross-thread transfer machinery (e.g.
 * "Cannot load transferred object"): that Error's full object graph — its
 * backtrace reaches into worker-local load state — can crash the awaiter when
 * deep-copied to the parent thread, so we re-ship only its message text. The
 * copy happens inside async_new_exception while `src` is still alive. */
static zend_object *thread_pool_wrap_transfer_error(zend_object *src)
{
	zval rv;
	const zval *msg = zend_read_property_ex(src->ce, src, ZSTR_KNOWN(ZEND_STR_MESSAGE), 1, &rv);
	return async_new_exception(async_ce_thread_transfer_exception, "%s",
		(msg != NULL && Z_TYPE_P(msg) == IS_STRING) ? Z_STRVAL_P(msg) : "thread transfer failed");
}

/* task_channel is swapped by reload() and read cross-thread on bailout paths —
 * always go through the atomic load. */
#define POOL_TASK_CHANNEL(pool) \
	((async_thread_channel_t *) zend_atomic_ptr_load_ex(&(pool)->task_channel))

/* Record (once) the bootloader-failure message on the pool, before its channel
 * is closed, so a submit() that races the close reports the real reason instead
 * of a generic closed-pool error. Guarded by the channel mutex; first failing
 * worker wins. */
static void thread_pool_record_bootloader_error(async_thread_pool_t *pool, zend_object *ex)
{
	async_thread_channel_t *channel = POOL_TASK_CHANNEL(pool);

	if (channel == NULL) {
		return;
	}

	zval rv;
	const zval *msg = zend_read_property_ex(ex->ce, ex, ZSTR_KNOWN(ZEND_STR_MESSAGE), 1, &rv);
	const char *text = (msg != NULL && Z_TYPE_P(msg) == IS_STRING)
		? Z_STRVAL_P(msg) : "ThreadPool bootloader failed";

	ASYNC_MUTEX_LOCK(channel->mutex);
	if (pool->bootloader_error == NULL) {
		pool->bootloader_error = pestrdup(text, 1);
	}
	ASYNC_MUTEX_UNLOCK(channel->mutex);
}

/* Throw the reason a submit failed against a closed pool: the real bootloader
 * error if a worker recorded one before closing the channel, otherwise the
 * given generic message. A pending generic channel-closed exception is replaced
 * by the bootloader error so the awaiter sees the true cause, not the symptom. */
static void thread_pool_throw_closed(async_thread_pool_t *pool, const char *fallback)
{
	if (pool->bootloader_error != NULL) {
		if (EG(exception)) {
			zend_clear_exception();
		}
		zend_throw_exception(async_ce_thread_transfer_exception, pool->bootloader_error, 0);
		return;
	}

	if (!EG(exception)) {
		zend_throw_exception(async_ce_thread_pool_exception, fallback, 0);
	}
}

/* event is always NULL for pool workers (started via ZEND_ASYNC_START_THREAD) */
/* Per-spawn worker context: the cohort channel is captured SYNCHRONOUSLY at
 * spawn time (in start_worker), not read lazily here — so a worker started by
 * reload N stays bound to that reload's channel even if reload N+1 swaps
 * pool->task_channel before this thread runs. Freed by the worker on exit. */
typedef struct {
	async_thread_pool_t    *pool;
	async_thread_channel_t *channel;
} thread_pool_worker_ctx_t;

/* Own function so the handler's zend_try isn't nested (GCC -Wmaybe-uninitialized). */
static bool thread_pool_call_guarded(zend_fcall_info *fci, zend_fcall_info_cache *fcc)
{
	volatile bool bailed = false;
	zend_try {
		zend_call_function(fci, fcc);
	} zend_catch {
		bailed = true;
	} zend_end_try();
	return bailed;
}

static bool thread_pool_suspend_guarded(void)
{
	volatile bool bailed = false;
	zend_try {
		ZEND_ASYNC_SUSPEND();
	} zend_catch {
		bailed = true;
	} zend_end_try();
	return bailed;
}

static void thread_pool_worker_handler(zend_async_thread_event_t *event, void *ctx)
{
	thread_pool_worker_ctx_t *wc = (thread_pool_worker_ctx_t *) ctx;
	async_thread_pool_t *pool = wc->pool;
	/* This worker's cohort channel. reload() gives fresh workers a NEW channel
	 * and closes this one, so an old worker leaves its loop when receive()
	 * returns false on the closed channel. */
	async_thread_channel_t *channel = wc->channel;
	int bailout = 0;
	/* Per-worker pool scope: child of worker's main scope. Pinned for the
	 * worker's lifetime; each spawned task lives in its own child scope of
	 * this one (see thread_pool_spawn_task_coroutine). Created lazily, only
	 * when coroutine_mode is enabled — sync workers don't need it. */
	zend_async_scope_t *pool_scope = NULL;
	/* Concurrency accounting (pool->concurrency > 0 only). Worker parks on
	 * slot_event at the limit. Volatile: assigned in the try, read by the
	 * bailout handler below, so it must survive the longjmp. */
	int32_t active_count = 0;
	zend_async_trigger_event_t * volatile slot_event = NULL;

	ZEND_ASSERT(event == NULL);

	/* Create a fake internal frame so EG(current_execute_data) != NULL.
	 * Without this, zend_throw_exception triggers bailout because it thinks
	 * there is no PHP stack to catch the exception. */
	zend_execute_data fake_frame = {0};
	fake_frame.func = &worker_root_function;
	fake_frame.prev_execute_data = EG(current_execute_data);
	EG(current_execute_data) = &fake_frame;

	zend_try {
		ZEND_ASYNC_SCHEDULER_INIT();

		if (UNEXPECTED(EG(exception))) {
			zend_exception_error(EG(exception), E_WARNING);
			zend_clear_exception();
			goto done;
		}

		/* Bootloader — run once per worker before entering the receive loop.
		 * On failure we fail the entire pool: close the channel and reject all
		 * pending submissions. Other workers will then exit on next recv(). */
		if (pool->bootloader_snapshot != NULL) {
			zval boot_callable, boot_retval;
			ZVAL_UNDEF(&boot_callable);
			ZVAL_UNDEF(&boot_retval);

			async_thread_create_closure(&pool->bootloader_snapshot->entry, &boot_callable);

			if (UNEXPECTED(EG(exception))) {
				/* Bootloader transfer failed (e.g. a $this-bound bootloader whose
				 * class isn't defined on the worker). Re-ship the error's message
				 * as a clean transfer exception (the raw Error's backtrace reaches
				 * into worker-local load state and crashes the awaiter if copied). */
				zend_object *boot_ex = thread_pool_wrap_transfer_error(EG(exception));
				zend_clear_exception();
				zval_ptr_dtor(&boot_callable);
				thread_pool_record_bootloader_error(pool, boot_ex);
				thread_pool_close(pool);
				thread_pool_drain_tasks(pool, true, boot_ex);
				OBJ_RELEASE(boot_ex);
				goto done;
			}

			zend_fcall_info boot_fci;
			zend_fcall_info_cache boot_fcc;
			volatile bool boot_bailed = false;
			if (zend_fcall_info_init(&boot_callable, 0, &boot_fci, &boot_fcc, NULL, NULL) == SUCCESS) {
				boot_fci.retval = &boot_retval;
				boot_bailed = thread_pool_call_guarded(&boot_fci, &boot_fcc);
			}

			zval_ptr_dtor(&boot_retval);
			zval_ptr_dtor(&boot_callable);

			if (boot_bailed
				|| (EG(exception) != NULL
					&& (zend_is_unwind_exit(EG(exception))
						|| zend_is_graceful_exit(EG(exception))))) {
				/* Bootloader called exit()/die() (unwind-exit token) or hit a fatal
				 * error (bailout). Convert into a transfer exception for every
				 * pending task instead of leaking the token through reject or
				 * re-raising zend_bailout(), either of which crashes the worker fiber. */
				if (EG(exception) != NULL) {
					zend_clear_exception();
				}
				zend_object *boot_ex = thread_pool_bailout_exception();
				thread_pool_record_bootloader_error(pool, boot_ex);
				thread_pool_close(pool);
				thread_pool_drain_tasks(pool, true, boot_ex);
				OBJ_RELEASE(boot_ex);
				goto done;
			}

			if (UNEXPECTED(EG(exception))) {
				/* Bootloader body threw — propagate the real exception to every
				 * pending task's awaiter instead of a generic cancellation. */
				zend_object *boot_ex = EG(exception);
				GC_ADDREF(boot_ex);
				zend_clear_exception();
				thread_pool_record_bootloader_error(pool, boot_ex);
				thread_pool_close(pool);
				thread_pool_drain_tasks(pool, true, boot_ex);
				OBJ_RELEASE(boot_ex);
				goto done;
			}
		}

		zval task;
		while (true) {
			/* Concurrency gate: when at the limit, park via wait-only
			 * receive (result=NULL) suspending on both the channel and
			 * slot_event. Wakes on a free slot (dispose fires
			 * slot_event), new submit, OR close. */
			while (pool->coroutine_mode && pool->concurrency > 0
				&& active_count >= pool->concurrency) {
				if (slot_event == NULL) {
					slot_event = ZEND_ASYNC_NEW_TRIGGER_EVENT();
				}
				if (!channel->channel.receive(&channel->channel, NULL, &slot_event->base)) {
					/* Channel closed — bail out. */
					goto done;
				}
				if (UNEXPECTED(EG(exception))) {
					zend_clear_exception();
					goto done;
				}
			}

			if (!channel->channel.receive(&channel->channel, &task, NULL)) {
				break;
			}
			ZEND_ASSERT(Z_TYPE(task) == IS_ARRAY);

			const zend_long kind =
				Z_LVAL_P(zend_hash_index_find(Z_ARRVAL(task), TASK_SLOT_KIND));
			zend_future_shared_state_t *state =
				(zend_future_shared_state_t *)(uintptr_t) Z_LVAL_P(
					zend_hash_index_find(Z_ARRVAL(task), TASK_SLOT_STATE));

			zend_atomic_int_dec(&pool->base.pending_count);
			zend_atomic_int_inc(&pool->base.running_count);

			if (kind == TASK_KIND_INTERNAL) {
				/* C-handler task — payload_a is handler ptr, payload_b is
				 * caller's pemalloc'd ctx. Handler frees ctx itself before
				 * returning; pool only takes ownership when dispatch fails
				 * (handled in drain path). */
				zend_thread_pool_internal_handler_t handler =
					(zend_thread_pool_internal_handler_t)(uintptr_t) Z_LVAL_P(
						zend_hash_index_find(Z_ARRVAL(task), TASK_SLOT_PAYLOAD_A));
				void *handler_ctx = (void *)(uintptr_t) Z_LVAL_P(
					zend_hash_index_find(Z_ARRVAL(task), TASK_SLOT_PAYLOAD_B));

				/* event arg reserved for future extension (handler stashing
				 * result/exception). The shared_state's external event lives
				 * behind a private struct field; until a public accessor lands
				 * we pass NULL. Handler completes via return + EG(exception). */
				handler(NULL, handler_ctx);

				zend_atomic_int_dec(&pool->base.running_count);
				zend_atomic_int_inc(&pool->base.completed_count);

				if (EG(exception)) {
					async_future_shared_state_reject(state, EG(exception));
					zend_clear_exception();
				} else {
					zval undef;
					ZVAL_UNDEF(&undef);
					async_future_shared_state_complete(state, &undef);
				}

				async_future_shared_state_delref(state);
				zval_ptr_dtor(&task);
				continue;
			}

			/* TASK_KIND_CLOSURE — original PHP-closure path. */
			async_thread_snapshot_t *snapshot =
				(async_thread_snapshot_t *)(uintptr_t) Z_LVAL_P(
					zend_hash_index_find(Z_ARRVAL(task), TASK_SLOT_PAYLOAD_A));
			const zval *args_zv =
				zend_hash_index_find(Z_ARRVAL(task), TASK_SLOT_PAYLOAD_B);

			zval callable, retval;
			zval *params = NULL;
			uint32_t param_count = 0;
			ZVAL_UNDEF(&retval);
			ZVAL_UNDEF(&callable);

			/* Heap-copy op_array names so they outlive the arena on a fatal. */
			async_thread_snapshot_materialize_entry(snapshot);

			async_thread_create_closure(&snapshot->entry, &callable);

			if (UNEXPECTED(EG(exception))) {
				zend_atomic_int_dec(&pool->base.running_count);
				zend_atomic_int_inc(&pool->base.completed_count);
				async_future_shared_state_reject(state, EG(exception));
				zend_clear_exception();
				goto task_cleanup;
			}

			zend_fcall_info fci;
			zend_fcall_info_cache fcc;

			if (UNEXPECTED(zend_fcall_info_init(&callable, 0, &fci, &fcc, NULL, NULL) != SUCCESS)) {
				zend_atomic_int_dec(&pool->base.running_count);
				zend_atomic_int_inc(&pool->base.completed_count);
				if (EG(exception)) {
					async_future_shared_state_reject(state, EG(exception));
					zend_clear_exception();
				}
				goto task_cleanup;
			}

			fci.retval = &retval;
			param_count = zend_hash_num_elements(Z_ARRVAL_P(args_zv));

			if (param_count > 0) {
				params = emalloc(sizeof(zval) * param_count);
				fci.params = params;
				fci.param_count = param_count;
				uint32_t i = 0;
				zval *arg;
				ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(args_zv), arg) {
					ZVAL_COPY(&params[i++], arg);
				} ZEND_HASH_FOREACH_END();
			}

			if (pool->coroutine_mode) {
				/* Lazily create the worker's pool scope on first task. Pinned
				 * so a cancellation cascade from the worker's main scope
				 * doesn't free it out from under in-flight tasks. */
				if (pool_scope == NULL) {
					pool_scope = ZEND_ASYNC_NEW_SCOPE(ZEND_ASYNC_CURRENT_SCOPE);
					if (UNEXPECTED(pool_scope == NULL)) {
						zend_atomic_int_dec(&pool->base.running_count);
						zend_atomic_int_inc(&pool->base.completed_count);
						if (EG(exception)) {
							async_future_shared_state_reject(state, EG(exception));
							zend_clear_exception();
						}
						goto task_cleanup;
					}
					ZEND_ASYNC_SCOPE_SET_OWNER_PINNED(pool_scope);
				}

				/* Spawn task in a fresh child scope of pool_scope.
				 * Completion via pool_task_dispose. The slot pointers are
				 * passed only when concurrency is enforced so dispose
				 * skips the fire path entirely otherwise. */
				int32_t *task_active = NULL;
				zend_async_trigger_event_t *task_event = NULL;
				if (pool->concurrency > 0) {
					if (slot_event == NULL) {
						slot_event = ZEND_ASYNC_NEW_TRIGGER_EVENT();
					}
					task_active = &active_count;
					task_event = slot_event;
				}
				if (thread_pool_spawn_task_coroutine(
						pool, pool_scope, &callable, &fci, &fcc, params,
						param_count, snapshot, state,
						task_active, task_event)) {
					if (pool->concurrency > 0) {
						active_count++;
					}
					/* Ownership of params/snapshot/state transferred to
					 * coroutine. callable's closure was addref'd into
					 * fcall->fci.function_name — release our local ref.
					 * retval was UNDEF (real return goes into coroutine->result). */
					zval_ptr_dtor(&callable);
					zval_ptr_dtor(&retval);
					zval_ptr_dtor(&task);
					continue;
				}
				/* Spawn failed — fall through to reject the future with the
				 * pending exception (if any) and free everything synchronously. */
				zend_atomic_int_dec(&pool->base.running_count);
				zend_atomic_int_inc(&pool->base.completed_count);
				if (EG(exception)) {
					async_future_shared_state_reject(state, EG(exception));
					zend_clear_exception();
				}
				goto task_cleanup;
			}

			/* Sync mode: run the body as a coroutine in a per-task nursery scope so
			 * Async\spawn() inside it lands there. Cancel + drain before freeing the
			 * snapshot so an un-awaited child can't outlive its arena. */
			zend_coroutine_t *worker_coro = ZEND_ASYNC_CURRENT_COROUTINE;
			zend_async_scope_t *task_scope =
				worker_coro != NULL ? ZEND_ASYNC_NEW_SCOPE(ZEND_ASYNC_CURRENT_SCOPE) : NULL;

			if (UNEXPECTED(task_scope == NULL)) {
				zend_atomic_int_dec(&pool->base.running_count);
				zend_atomic_int_inc(&pool->base.completed_count);
				if (EG(exception)) {
					async_future_shared_state_reject(state, EG(exception));
					zend_clear_exception();
				}
				goto task_cleanup;
			}

			/* Nursery (NOT-safe): un-awaited children cancelled at exit. Pinned so
			 * it survives the drain; unpinned before RELEASE. */
			ZEND_ASYNC_SCOPE_CLR_DISPOSE_SAFELY(task_scope);
			ZEND_ASYNC_SCOPE_SET_OWNER_PINNED(task_scope);

			zend_coroutine_t *body = ZEND_ASYNC_SPAWN_WITH(task_scope);
			if (UNEXPECTED(body == NULL)) {
				ZEND_ASYNC_SCOPE_CLR_OWNER_PINNED(task_scope);
				ZEND_ASYNC_SCOPE_RELEASE(task_scope);
				zend_atomic_int_dec(&pool->base.running_count);
				zend_atomic_int_inc(&pool->base.completed_count);
				if (EG(exception)) {
					async_future_shared_state_reject(state, EG(exception));
					zend_clear_exception();
				}
				goto task_cleanup;
			}

			/* Hand the call to the body; params ownership moves to it, snapshot
			 * stays ours to free after the drain. */
			zend_fcall_t *fcall = ecalloc(1, sizeof(zend_fcall_t));
			fcall->fci = fci;
			fcall->fci_cache = fcc;
			fcall->fci.param_count = param_count;
			fcall->fci.params = params;
			fcall->fci.retval = &body->result;
			Z_TRY_ADDREF(fcall->fci.function_name);
			body->fcall = fcall;
			params = NULL;

			/* Await the body; its callback copies result/error into our waker. A
			 * fatal re-raises zend_bailout() out of the coroutine — caught here. */
			bool body_bailed = false;
			ZEND_ASYNC_WAKER_NEW(worker_coro);
			zend_async_resume_when(worker_coro, &body->event, false,
								   zend_async_waker_callback_resolve, NULL);
			body_bailed = thread_pool_suspend_guarded();

			/* Decrement running and bump completed BEFORE notifying the awaiter
			 * via complete/reject — otherwise a coroutine waking from await()
			 * would observe stale running_count and a missing completed bump. */
			zend_atomic_int_dec(&pool->base.running_count);
			zend_atomic_int_inc(&pool->base.completed_count);

			if (UNEXPECTED(body_bailed)) {
				/* Fatal in the body: reject this task and tear the pool down. */
				zend_async_waker_clean(worker_coro);
				zend_object *bex = thread_pool_bailout_exception();
				async_future_shared_state_reject(state, bex);
				thread_pool_close(pool);
				thread_pool_drain_tasks(pool, true, bex);
				OBJ_RELEASE(bex);
				ZEND_ASYNC_SCOPE_CLR_OWNER_PINNED(task_scope);
				ZEND_ASYNC_SCOPE_RELEASE(task_scope);
				zval_ptr_dtor(&callable);
				zval_ptr_dtor(&retval);
				async_thread_snapshot_destroy(snapshot);
				async_future_shared_state_delref(state);
				zval_ptr_dtor(&task);
				break;
			}

			zend_object *body_error = NULL;
			if (worker_coro->waker != NULL && worker_coro->waker->error != NULL) {
				body_error = worker_coro->waker->error;
				worker_coro->waker->error = NULL;
			} else if (EG(exception) != NULL) {
				body_error = EG(exception);
				GC_ADDREF(body_error);
				zend_clear_exception();
			}

			if (body_error != NULL) {
				async_future_shared_state_reject(state, body_error);
				OBJ_RELEASE(body_error);
			} else if (worker_coro->waker != NULL
					&& Z_TYPE(worker_coro->waker->result) != IS_UNDEF) {
				async_future_shared_state_complete(state, &worker_coro->waker->result);
			} else {
				zval null_result;
				ZVAL_NULL(&null_result);
				async_future_shared_state_complete(state, &null_result);
			}

			zend_async_waker_clean(worker_coro);

			/* Cancel + await un-awaited children before freeing the snapshot
			 * arena that backs their op_arrays. */
			if (!ZEND_ASYNC_SCOPE_IS_CLOSED(task_scope)) {
				ZEND_ASYNC_SCOPE_CANCEL(task_scope, NULL, false, false);
				ZEND_ASYNC_SCOPE_AWAIT_AFTER_CANCELLATION(task_scope, worker_coro, NULL, NULL, NULL);
				if (UNEXPECTED(EG(exception))) {
					zend_clear_exception();
				}
			}

			ZEND_ASYNC_SCOPE_CLR_OWNER_PINNED(task_scope);
			ZEND_ASYNC_SCOPE_RELEASE(task_scope);

			/* Drop the closure ref and free the snapshot. */
			zval_ptr_dtor(&callable);
			zval_ptr_dtor(&retval);
			async_thread_snapshot_destroy(snapshot);
			async_future_shared_state_delref(state);
			zval_ptr_dtor(&task);
			continue;

		task_cleanup:
			if (params) {
				for (uint32_t i = 0; i < param_count; i++) {
					zval_ptr_dtor(&params[i]);
				}
				efree(params);
			}
			zval_ptr_dtor(&retval);
			zval_ptr_dtor(&callable);
			async_thread_snapshot_destroy(snapshot);
			async_future_shared_state_delref(state);
			zval_ptr_dtor(&task);
		}

		if (EG(exception)) {
			if (!instanceof_function(EG(exception)->ce, async_ce_thread_channel_exception)) {
				zend_exception_error(EG(exception), E_WARNING);
			}
			zend_clear_exception();
		}

	done:
		/* Cancel (if requested) and release pool_scope BEFORE AFTER_MAIN:
		 * unpinning + release lets the cascade disposal complete during
		 * the scheduler drain instead of leaking the scope. */
		if (pool_scope != NULL) {
			if (zend_atomic_int_load(&pool->cancel_requested)) {
				/* is_safely=false overrides inherited DISPOSE_SAFELY —
				 * we want in-flight task coroutines to actually die. */
				ZEND_ASYNC_SCOPE_CANCEL(pool_scope, NULL, false, false);
			}
			ZEND_ASYNC_SCOPE_CLR_OWNER_PINNED(pool_scope);
			ZEND_ASYNC_SCOPE_RELEASE(pool_scope);
			pool_scope = NULL;
		}

		/* Backstop for a retiring SYNC-mode worker: its tasks ran inline (no
		 * per-task coroutine scope), so any coroutine still alive is a straggler
		 * the bootloader spawned into the main scope (e.g. a DB-pool healthcheck
		 * timer) that the server-side scope drain never reaches. Its live reactor
		 * handle would keep AFTER_MAIN — and this worker's reload exit-token —
		 * hung forever, so force graceful shutdown to cancel it. Coroutine-mode
		 * pools instead drain their in-flight task coroutines below (see 077),
		 * so they must not take this path. */
		if (!pool->coroutine_mode) {
			start_graceful_shutdown();
		}

		ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false);

		/* AFTER_MAIN drained all task coroutines (and ran their dispose,
		 * which may have fired slot_event). Now safe to release it. */
		if (slot_event != NULL) {
			slot_event->base.dispose(&slot_event->base);
			slot_event = NULL;
		}

	} zend_catch {
		bailout = 1;
	} zend_end_try();

	/* Restore execute_data */
	EG(current_execute_data) = fake_frame.prev_execute_data;

	if (bailout) {
		/* A bailout escaped the per-task/bootloader guards (e.g. during the
		 * scheduler drain). Re-raising zend_bailout() inside the worker fiber
		 * crashes it, so reject any still-pending tasks and exit cleanly. Done
		 * before DELREF so the pool is still alive for the drain. */
		zend_object *bex = thread_pool_bailout_exception();
		thread_pool_close(pool);
		thread_pool_drain_tasks(pool, true, bex);
		OBJ_RELEASE(bex);

		/* Bailout longjmped past `done:` (which disposes slot_event). Its open
		 * uv_async would block uv_loop_close — dispose it while reactor is up. */
		if (slot_event != NULL) {
			slot_event->base.dispose(&slot_event->base);
			slot_event = NULL;
		}
	}

	/* Rolling reload: post one exit token iff OUR cohort channel is the one the
	 * active rotation retired (identity check before any notify dereference —
	 * dying replacements and stragglers don't send). Past zend_end_try so
	 * bailout exits report too; capacity == cohort size, never blocks. */
	async_thread_channel_t *reload_notify =
			(async_thread_channel_t *) zend_atomic_ptr_load_ex(&pool->reload_notify);

	if (UNEXPECTED(reload_notify != NULL) &&
			zend_atomic_ptr_load_ex(&pool->reload_old) == (void *) channel) {
		zval token;
		ZVAL_TRUE(&token);

		if (UNEXPECTED(false == reload_notify->channel.send(&reload_notify->channel, &token))) {
			zend_clear_exception();
		}
	}

	/* Release worker's ref on pool */
	ZEND_THREAD_POOL_DELREF(&pool->base);

	pefree(wc, 1);
}

///////////////////////////////////////////////////////////
/// Coroutine-mode task: spawn + extended_dispose
///////////////////////////////////////////////////////////

typedef struct {
	async_thread_pool_t *pool;
	async_thread_snapshot_t *snapshot;
	zend_future_shared_state_t *state;
	/* Slot accounting (both NULL when concurrency=0). Pointers point into
	 * the worker handler's stack — safe because dispose runs in the same
	 * scheduler as the worker. */
	int32_t *active_count;
	zend_async_trigger_event_t *slot_event;
} pool_task_ctx_t;

/* Coroutine extended_dispose — invoked by the runtime after the task
 * coroutine finishes (return or throw). At this point coroutine->result
 * and coroutine->exception are populated; event callbacks have already
 * fired. We resolve the future, release pool-side resources, then mark
 * the exception handled so the scheduler doesn't propagate it up the
 * scope chain (which would trigger a spurious graceful-shutdown cancel
 * of sibling coroutines, including the worker's main). */
static void pool_task_dispose(zend_coroutine_t *coroutine)
{
	pool_task_ctx_t *ctx = coroutine->extended_data;
	if (ctx == NULL) {
		return;
	}
	coroutine->extended_data = NULL;

	zend_atomic_int_dec(&ctx->pool->base.running_count);
	zend_atomic_int_inc(&ctx->pool->base.completed_count);

	if (coroutine->exception != NULL) {
		async_future_shared_state_reject(ctx->state, coroutine->exception);
		ZEND_COROUTINE_SET_EXCEPTION_HANDLED(coroutine);
	} else if (Z_TYPE(coroutine->result) != IS_UNDEF) {
		async_future_shared_state_complete(ctx->state, &coroutine->result);
	} else {
		/* UNDEF result, no exception = the body bailed out (fatal/OOM/exit).
		 * Reject with the cause instead of resolving to a silent null. */
		zend_object *bex = thread_pool_bailout_exception();
		async_future_shared_state_reject(ctx->state, bex);
		OBJ_RELEASE(bex);
	}

	async_future_shared_state_delref(ctx->state);
	async_thread_snapshot_destroy(ctx->snapshot);

	/* Release the slot: decrement and wake the worker. notify is a no-op
	 * if no waker is registered. The active < limit invariant always holds
	 * after decrement (worker never exceeds the limit), so we fire
	 * unconditionally. */
	if (ctx->active_count != NULL) {
		(*ctx->active_count)--;
		ZEND_ASYNC_CALLBACKS_NOTIFY(&ctx->slot_event->base, NULL, NULL);
	}

	efree(ctx);
}

/* Build fcall, spawn a coroutine in the worker's scheduler, attach the
 * completion handler via extended_dispose. Ownership of callable/params/
 * snapshot/state transfers to the coroutine on success.
 *
 * Each task runs in its own child scope of `pool_scope` so cancellation of
 * one task doesn't disturb siblings, and cancelling `pool_scope` cascades
 * to every in-flight task. */
static bool thread_pool_spawn_task_coroutine(
	async_thread_pool_t *pool, zend_async_scope_t *pool_scope,
	zval *callable,
	zend_fcall_info *fci, zend_fcall_info_cache *fcc,
	zval *params, uint32_t param_count,
	async_thread_snapshot_t *snapshot, zend_future_shared_state_t *state,
	int32_t *active_count, zend_async_trigger_event_t *slot_event)
{
	(void) callable;
	zend_async_scope_t *task_scope = ZEND_ASYNC_NEW_SCOPE(pool_scope);
	if (UNEXPECTED(task_scope == NULL)) {
		return false;
	}

	zend_coroutine_t *coroutine = ZEND_ASYNC_SPAWN_WITH(task_scope);
	if (UNEXPECTED(coroutine == NULL)) {
		/* No coroutine took ownership; the task scope was freshly created
		 * with refcount=1, so release it here. */
		ZEND_ASYNC_SCOPE_RELEASE(task_scope);
		return false;
	}

	/* Build the coroutine's fcall — same shape as ZEND_ASYNC_FCALL_DEFINE
	 * but reuses the params buffer the worker already populated. */
	zend_fcall_t *fcall = ecalloc(1, sizeof(zend_fcall_t));
	fcall->fci = *fci;
	fcall->fci_cache = *fcc;
	fcall->fci.param_count = param_count;
	fcall->fci.params = params; /* taken over by fcall; freed via release */
	fcall->fci.retval = &coroutine->result;
	Z_TRY_ADDREF(fcall->fci.function_name);

	coroutine->fcall = fcall;

	pool_task_ctx_t *ctx = emalloc(sizeof(pool_task_ctx_t));
	ctx->pool = pool;
	ctx->snapshot = snapshot;
	ctx->state = state;
	ctx->active_count = active_count;
	ctx->slot_event = slot_event;

	coroutine->extended_data = ctx;
	coroutine->extended_dispose = pool_task_dispose;

	return true;
}

///////////////////////////////////////////////////////////
/// Pool lifecycle
///////////////////////////////////////////////////////////

static void thread_pool_close_base(zend_async_thread_pool_t *pool);
static void thread_pool_dispose_base(zend_async_thread_pool_t *pool);

/**
 * Create and start a single worker thread.
 * On success, stores the thread handle in pool->workers[index] and
 * increments pool refcount (+1 for the worker).
 * Returns true on success, false on failure.
 */
static bool thread_pool_start_worker(async_thread_pool_t *pool, int32_t index)
{
	zend_async_thread_internal_entry_t *entry = pecalloc(1, sizeof(zend_async_thread_internal_entry_t), 1);
	entry->handler = thread_pool_worker_handler;

	/* Capture the cohort channel NOW (on the spawning thread), so the worker
	 * binds to the current channel even if a later reload swaps it. The worker
	 * frees this context on exit. */
	thread_pool_worker_ctx_t *wc = pemalloc(sizeof(*wc), 1);
	wc->pool = pool;
	wc->channel = POOL_TASK_CHANNEL(pool);
	entry->ctx = wc;

	/* Create thread context (no event for pool workers).
	 * start_thread will add ref for the runner. */
	zend_async_thread_context_t *context = pecalloc(1, sizeof(zend_async_thread_context_t), 1);
	ZEND_ATOMIC_INT_INIT(&context->ref_count, 0);
	ZEND_ATOMIC_INT64_INIT(&context->thread_id, 0);
	context->snapshot = NULL;
	context->bailout_error_message = NULL;
	zend_atomic_ptr_init(&context->event, NULL); /* pool workers never have an event */
	ZEND_ASYNC_THREAD_CONTEXT_EVENT_MUTEX_ALLOC(context);
	context->internal_entry = NULL; /* set by start_thread */

	/* Take the worker's pool ref BEFORE spawning. A worker can run to
	 * completion and DELREF immediately (e.g. a bootloader that throws), so
	 * its ref must already be accounted for — otherwise that DELREF drops the
	 * pool to zero and frees it before we store the handle below. */
	ZEND_THREAD_POOL_ADDREF(&pool->base);

	zend_async_thread_handle_t handle = ZEND_ASYNC_START_THREAD(entry, context);

	if (UNEXPECTED(handle == 0)) {
		ZEND_THREAD_POOL_DELREF(&pool->base);
		pefree(wc, 1);
		pefree(entry, 1);
		ZEND_ASYNC_THREAD_CONTEXT_EVENT_MUTEX_FREE(context);
		pefree(context, 1);
		return false;
	}

	pool->base.workers[index] = handle;

	return true;
}

/* Wake every reload() caller queued behind the active rotation; each re-checks
 * the rotation counters and either coalesces or becomes the next rotator. */
static void thread_pool_reload_fire_waiters(async_thread_pool_t *pool)
{
	zend_async_trigger_event_t *trigger;

	ZEND_HASH_FOREACH_PTR(&pool->reload_waiters, trigger)
	{
		trigger->trigger(trigger);
	}
	ZEND_HASH_FOREACH_END();
}

/* Park the calling coroutine until the active rotation finishes (same
 * per-waiter trigger pattern as thread_channel). false = woken by exception. */
static bool thread_pool_reload_wait(async_thread_pool_t *pool)
{
	zend_async_trigger_event_t *trigger = ZEND_ASYNC_NEW_TRIGGER_EVENT();

	if (UNEXPECTED(trigger == NULL)) {
		return false;
	}

	zend_hash_index_update_ptr(&pool->reload_waiters, (zend_ulong)(uintptr_t) trigger, trigger);
	zend_async_resume_when(ZEND_ASYNC_CURRENT_COROUTINE, &trigger->base, false,
			zend_async_waker_callback_resolve, NULL);

	/* A bailout through SUSPEND would leak the trigger and leave a stale entry
	 * in reload_waiters. Catch, clean up, re-raise. */
	bool bailed = false;
	zend_try {
		ZEND_ASYNC_SUSPEND();
	} zend_catch {
		bailed = true;
	} zend_end_try();

	ZEND_ASYNC_WAKER_DESTROY(ZEND_ASYNC_CURRENT_COROUTINE);
	zend_hash_index_del(&pool->reload_waiters, (zend_ulong)(uintptr_t) trigger);
	trigger->base.dispose(&trigger->base);

	if (UNEXPECTED(bailed)) {
		zend_bailout();
	}

	return !EG(exception);
}

/**
 * In-place rolling reload (blue-green). Fresh workers start on a NEW task
 * channel; the old cohort is retired by closing theirs (their receive() returns
 * false and they leave the loop). Replacements spawn 1:1 as old workers drain —
 * ~N workers throughout, no 2N spike. Runs on the calling coroutine: it awaits
 * each old worker's exit token before spawning its replacement.
 *
 * Overlapping calls serialize and coalesce: callers queued behind an active
 * rotation are all satisfied by the single follow-up rotation that starts after
 * their entry ("when my reload() returns, no live worker predates my call").
 */
static void thread_pool_reload(zend_async_thread_pool_t *base)
{
	async_thread_pool_t *pool = (async_thread_pool_t *) base;

	if (UNEXPECTED(zend_atomic_int_load(&base->closed))) {
		return;
	}

	if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL)) {
		zend_throw_error(NULL, "ThreadPool::reload() must be called from within a coroutine");
		return;
	}

	/* The next rotation to START runs after this call entered — its completion
	 * delivers our freshness guarantee. */
	const uint64_t target = pool->rotations_started + 1;

	while (pool->reload_in_progress) {
		if (UNEXPECTED(false == thread_pool_reload_wait(pool))) {
			return; /* cancelled while queued — exception propagates */
		}

		if (pool->rotations_completed >= target) {
			return; /* coalesced onto a rotation that started after our entry */
		}

		if (UNEXPECTED(zend_atomic_int_load(&base->closed))) {
			return;
		}
	}

	const int32_t n = base->worker_count;

	if (UNEXPECTED(n <= 0)) {
		return;
	}

	pool->reload_in_progress = true;
	pool->rotations_started++;

	/* Leftovers of an aborted rotation: the atomic pair was cleared on abort,
	 * so no straggler can target them anymore — safe to release. */
	if (pool->orphan_notify != NULL) {
		async_thread_channel_close(pool->orphan_notify);
		pool->orphan_notify->channel.event.dispose(&pool->orphan_notify->channel.event);
		pool->orphan_notify = NULL;
	}

	if (pool->orphan_old != NULL) {
		pool->orphan_old->channel.event.dispose(&pool->orphan_old->channel.event);
		pool->orphan_old = NULL;
	}

	async_thread_channel_t *old_channel = POOL_TASK_CHANNEL(pool);
	async_thread_channel_t *new_channel = async_thread_channel_create(old_channel->capacity);
	async_thread_channel_t *notify = async_thread_channel_create(n);

	if (UNEXPECTED(new_channel == NULL || notify == NULL)) {
		if (new_channel != NULL) {
			new_channel->channel.event.dispose(&new_channel->channel.event);
		}

		if (notify != NULL) {
			notify->channel.event.dispose(&notify->channel.event);
		}

		pool->rotations_started--;
		pool->reload_in_progress = false;
		thread_pool_reload_fire_waiters(pool);
		zend_throw_exception(async_ce_thread_pool_exception, "Failed to allocate reload channels", 0);
		return;
	}

	/* Publish the identity pair (old first — a worker that sees notify != NULL
	 * must see a valid old), swap, then wake the old cohort by closing theirs. */
	zend_atomic_ptr_store_ex(&pool->reload_old, old_channel);
	zend_atomic_ptr_store_ex(&pool->reload_notify, notify);
	zend_atomic_ptr_store_ex(&pool->task_channel, new_channel);
	async_thread_channel_close(old_channel);

	bool aborted = false;

	for (int32_t i = 0; i < n; i++) {
		/* Spawn failure or a pool closed mid-rotation degrade the replacement
		 * count, never the token accounting — old exits are still collected. */
		if (!zend_atomic_int_load(&base->closed)) {
			thread_pool_start_worker(pool, i);
		}

		zval token;
		ZVAL_UNDEF(&token);

		if (notify->channel.receive(&notify->channel, &token, NULL)) {
			zval_ptr_dtor(&token);
			continue;
		}

		aborted = true;
		break;
	}

	/* Clear the pair before touching the channels: late loaders see NULL. */
	zend_atomic_ptr_store_ex(&pool->reload_notify, NULL);
	zend_atomic_ptr_store_ex(&pool->reload_old, NULL);

	if (UNEXPECTED(aborted)) {
		/* Cancelled mid-drain. Stragglers that loaded the pair before the clear
		 * may still send (buffered, cap == n) — park both channels until the
		 * next rotation / destroy. completed is NOT bumped: a woken waiter runs
		 * the follow-up rotation and heals the partial cohort. */
		pool->orphan_notify = notify;
		pool->orphan_old = old_channel;
	} else {
		/* Exact accounting: all n exit tokens received, so no sender touches
		 * the notify channel anymore and the old channel is fully drained. */
		async_thread_channel_close(notify);
		notify->channel.event.dispose(&notify->channel.event);
		old_channel->channel.event.dispose(&old_channel->channel.event);
		pool->rotations_completed++;
	}

	pool->reload_in_progress = false;
	thread_pool_reload_fire_waiters(pool);
	/* On abort the cancellation exception propagates to the caller. */
}

/**
 * C-level submit. Posts a TASK_KIND_INTERNAL onto the same task_channel
 * the PHP submit uses; the worker dispatches by kind. ctx is opaque to
 * the pool — caller owns its lifecycle (success: handler frees or
 * decrefs; failure: caller cleans up after seeing NULL return).
 */
static zend_async_event_t *thread_pool_submit_internal_impl(
	zend_async_thread_pool_t *base,
	zend_thread_pool_internal_handler_t handler,
	void *ctx)
{
	async_thread_pool_t *pool = (async_thread_pool_t *) base;

	if (UNEXPECTED(zend_atomic_int_load(&pool->base.closed))) {
		thread_pool_throw_closed(pool, "ThreadPool is closed");
		return NULL;
	}

	zend_future_shared_state_t *state = async_future_shared_state_create();
	zend_future_remote_t *remote = async_new_remote_future(state);
	if (UNEXPECTED(remote == NULL)) {
		async_future_shared_state_destroy(state);
		zend_throw_exception(async_ce_thread_pool_exception, "Failed to create future", 0);
		return NULL;
	}

	/* +1 ref for the task — worker delrefs after complete/reject. */
	async_future_shared_state_addref(state);

	/* Pack: [kind=1, handler_ptr, ctx_ptr, state_ptr] */
	zval task;
	array_init_size(&task, 4);
	zval slot;
	ZVAL_LONG(&slot, TASK_KIND_INTERNAL);
	zend_hash_next_index_insert_new(Z_ARRVAL(task), &slot);
	ZVAL_LONG(&slot, (zend_long)(uintptr_t) handler);
	zend_hash_next_index_insert_new(Z_ARRVAL(task), &slot);
	ZVAL_LONG(&slot, (zend_long)(uintptr_t) ctx);
	zend_hash_next_index_insert_new(Z_ARRVAL(task), &slot);
	ZVAL_LONG(&slot, (zend_long)(uintptr_t) state);
	zend_hash_next_index_insert_new(Z_ARRVAL(task), &slot);

	async_thread_channel_t *channel = POOL_TASK_CHANNEL(pool);

	if (UNEXPECTED(!channel->channel.send(&channel->channel, &task))) {
		zval_ptr_dtor(&task);
		async_future_shared_state_delref(state);
		ZEND_ASYNC_EVENT_RELEASE(&remote->future.event);
		thread_pool_throw_closed(pool, "ThreadPool channel is closed");
		return NULL;
	}

	zval_ptr_dtor(&task);
	zend_atomic_int_inc(&pool->base.pending_count);

	ZEND_FUTURE_SET_USED(&remote->future);
	return &remote->future.event;
}

zend_async_thread_pool_t *async_thread_pool_create(
	int32_t worker_count, int32_t queue_size, const zend_fcall_t *bootloader,
	bool coroutine_mode, int32_t concurrency)
{
	async_thread_pool_t *pool = pecalloc(1, sizeof(async_thread_pool_t), 1);
	pool->coroutine_mode = coroutine_mode;
	pool->concurrency = concurrency;
	ZEND_ATOMIC_INT_INIT(&pool->cancel_requested, 0);
	zend_atomic_ptr_init(&pool->task_channel, NULL);
	zend_atomic_ptr_init(&pool->reload_notify, NULL);
	zend_atomic_ptr_init(&pool->reload_old, NULL);
	zend_hash_init(&pool->reload_waiters, 0, NULL, NULL, 1);

	pool->base.worker_count = 0;
	ZEND_ATOMIC_INT_INIT(&pool->base.pending_count, 0);
	ZEND_ATOMIC_INT_INIT(&pool->base.running_count, 0);
	ZEND_ATOMIC_INT_INIT(&pool->base.completed_count, 0);
	ZEND_ATOMIC_INT_INIT(&pool->base.closed, 0);
	ZEND_ATOMIC_INT_INIT(&pool->base.ref_count, 1); /* PHP object holds 1 ref */

	/* Set method pointers */
	pool->base.close = thread_pool_close_base;
	pool->base.dispose = thread_pool_dispose_base;
	pool->base.submit_internal = thread_pool_submit_internal_impl;
	pool->base.reload = thread_pool_reload;

	/* Deep-copy bootloader once into a persistent snapshot reused by every
	 * worker. We stash it in `entry` because pool snapshots have no separate
	 * entry — each task brings its own. */
	pool->bootloader_snapshot = NULL;
	if (bootloader != NULL) {
		pool->bootloader_snapshot = async_thread_snapshot_create(bootloader, NULL, true);
		if (UNEXPECTED(pool->bootloader_snapshot == NULL)) {
			/* snapshot_create propagated an exception (e.g. captured value
			 * refused transfer). Fail construction. */
			pool->base.workers = NULL;
			return &pool->base;
		}
	}

	zend_atomic_ptr_store_ex(&pool->task_channel, async_thread_channel_create(queue_size));
	pool->base.workers = pecalloc(worker_count, sizeof(zend_async_thread_handle_t), 1);

	for (int32_t i = 0; i < worker_count; i++) {
		if (UNEXPECTED(false == thread_pool_start_worker(pool, i))) {
			pool->base.worker_count = i;
			thread_pool_close(pool);
			zend_throw_exception(async_ce_thread_pool_exception,"Failed to start worker thread", 0);
			return &pool->base;
		}

		pool->base.worker_count = i + 1;
	}

	return &pool->base;
}

static void thread_pool_close(async_thread_pool_t *pool)
{
	if (zend_atomic_int_load(&pool->base.closed)) {
		return;
	}

	zend_atomic_int_store(&pool->base.closed, 1);

	/* Close task channel — workers see closed on next recv and exit */
	async_thread_channel_t *channel = POOL_TASK_CHANNEL(pool);

	if (channel != NULL) {
		channel->channel.close(&channel->channel);
	}
}

static void thread_pool_close_base(zend_async_thread_pool_t *base)
{
	thread_pool_close((async_thread_pool_t *) base);
}

/**
 * Drain remaining tasks from channel buffer.
 * @param reject       If true, reject each task's future with an exception.
 *                     If false, just release the shared_state ref (for destroy path).
 * @param reject_with  Exception to reject with (borrowed). If NULL, a generic
 *                     "cancelled before execution" exception is synthesized per task.
 *                     Used to propagate the real bootloader-transfer error.
 */
static void thread_pool_drain_tasks(async_thread_pool_t *pool, bool reject, zend_object *reject_with)
{
	async_thread_channel_t *ch = POOL_TASK_CHANNEL(pool);
	if (ch == NULL) {
		return;
	}

	while (true) {
		/* Pop under the channel mutex: cancel() drains concurrently with
		 * worker threads still inside recv(), so a raw buffer access races
		 * them and corrupts the circular buffer. Process the task after
		 * unlocking — the body never re-enters the channel. */
		zval persistent_task;
		ASYNC_MUTEX_LOCK(ch->mutex);
		if (circular_buffer_is_not_empty(&ch->buffer) == false
			|| circular_buffer_pop(&ch->buffer, &persistent_task) != SUCCESS) {
			ASYNC_MUTEX_UNLOCK(ch->mutex);
			break;
		}
		ASYNC_MUTEX_UNLOCK(ch->mutex);

		/* Load task to extract pointers */
		zval task;
		async_thread_load_zval(&task, &persistent_task);
		async_thread_release_transferred_zval(&persistent_task);

		/* Branch on kind to free the right payload. Both kinds share
		 * slot[3] = state_ptr. */
		const zval *kind_zv = zend_hash_index_find(Z_ARRVAL(task), TASK_SLOT_KIND);
		const zend_long kind = (kind_zv != NULL && Z_TYPE_P(kind_zv) == IS_LONG)
			? Z_LVAL_P(kind_zv) : TASK_KIND_CLOSURE;

		if (kind == TASK_KIND_INTERNAL) {
			/* Internal task — handler never ran. ctx is caller-owned;
			 * pool doesn't touch it. The shared_state's callbacks fire
			 * via reject below so the caller observes cancellation. */
		} else {
			/* Closure task — slot[1] is the snapshot. */
			const zval *snapshot_zv =
				zend_hash_index_find(Z_ARRVAL(task), TASK_SLOT_PAYLOAD_A);
			if (snapshot_zv != NULL && Z_TYPE_P(snapshot_zv) == IS_LONG) {
				async_thread_snapshot_t *snapshot =
					(async_thread_snapshot_t *)(uintptr_t) Z_LVAL_P(snapshot_zv);
				async_thread_snapshot_destroy(snapshot);
			}
		}

		const zval *state_zv = zend_hash_index_find(Z_ARRVAL(task), TASK_SLOT_STATE);
		if (state_zv != NULL && Z_TYPE_P(state_zv) == IS_LONG) {
			zend_future_shared_state_t *state =
				(zend_future_shared_state_t *)(uintptr_t) Z_LVAL_P(state_zv);

			if (reject) {
				if (reject_with != NULL) {
					async_future_shared_state_reject(state, reject_with);
				} else {
					zend_object *exception = async_new_exception(
						async_ce_cancellation_exception,
						"ThreadPool task was cancelled before execution");
					async_future_shared_state_reject(state, exception);
					OBJ_RELEASE(exception);
				}
			}

			async_future_shared_state_delref(state);
		}

		zend_atomic_int_dec(&pool->base.pending_count);
		zval_ptr_dtor(&task);
	}
}

/**
 * Destroy the real pool. Called when ref_count reaches 0.
 * By this point all workers have exited and released their refs.
 */
static void thread_pool_destroy(async_thread_pool_t *pool)
{
	thread_pool_drain_tasks(pool, false, NULL);

	async_thread_channel_t *channel = POOL_TASK_CHANNEL(pool);

	if (channel != NULL) {
		channel->channel.event.dispose(&channel->channel.event);
		zend_atomic_ptr_store_ex(&pool->task_channel, NULL);
	}

	/* Channels parked by an aborted rotation (all their senders are gone:
	 * every worker holds a pool ref until after its token send). */
	if (pool->orphan_notify != NULL) {
		async_thread_channel_close(pool->orphan_notify);
		pool->orphan_notify->channel.event.dispose(&pool->orphan_notify->channel.event);
		pool->orphan_notify = NULL;
	}

	if (pool->orphan_old != NULL) {
		pool->orphan_old->channel.event.dispose(&pool->orphan_old->channel.event);
		pool->orphan_old = NULL;
	}

	zend_hash_destroy(&pool->reload_waiters);

	if (pool->base.workers != NULL) {
		pefree(pool->base.workers, 1);
		pool->base.workers = NULL;
	}

	if (pool->bootloader_snapshot != NULL) {
		async_thread_snapshot_destroy(pool->bootloader_snapshot);
		pool->bootloader_snapshot = NULL;
	}

	if (pool->bootloader_error != NULL) {
		pefree(pool->bootloader_error, 1);
		pool->bootloader_error = NULL;
	}

	pefree(pool, 1);
}

static zend_always_inline void thread_pool_dispose_base(zend_async_thread_pool_t *base)
{
	thread_pool_destroy((async_thread_pool_t *) base);
}

///////////////////////////////////////////////////////////
/// PHP object lifecycle
///////////////////////////////////////////////////////////

static zend_object *thread_pool_create_object(zend_class_entry *ce)
{
	thread_pool_object_t *obj = zend_object_alloc(sizeof(thread_pool_object_t), ce);
	zend_object_std_init(&obj->std, ce);
	obj->std.handlers = &thread_pool_handlers;
	obj->pool = NULL;
	return &obj->std;
}

static void thread_pool_free_object(zend_object *object)
{
	thread_pool_object_t *obj = ASYNC_THREAD_POOL_FROM_OBJ(object);

	if (obj->pool != NULL) {
		/* Close channel so workers exit their receive loop */
		thread_pool_close(obj->pool);

		/* Drop PHP object's ref on the pool.
		 * Workers hold their own refs — when the last worker finishes
		 * and does ZEND_THREAD_POOL_DELREF, pool refcount reaches 0 and
		 * thread_pool_destroy releases pool's refs on worker events. */
		ZEND_THREAD_POOL_DELREF(&obj->pool->base);
		obj->pool = NULL;
	}

	zend_object_std_dtor(object);
}

///////////////////////////////////////////////////////////
/// PHP Methods
///////////////////////////////////////////////////////////

METHOD(__construct)
{
	zend_long workers = 0;
	zend_long queue_size = 0;
	zval *bootloader_zv = NULL;
	bool coroutine_mode = false;
	zend_long concurrency = 0;

	ZEND_PARSE_PARAMETERS_START(0, 5)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(workers)
		Z_PARAM_LONG(queue_size)
		Z_PARAM_OBJECT_OF_CLASS_OR_NULL(bootloader_zv, zend_ce_closure)
		Z_PARAM_BOOL(coroutine_mode)
		Z_PARAM_LONG(concurrency)
	ZEND_PARSE_PARAMETERS_END();

#ifndef ZTS
	zend_throw_exception(async_ce_thread_pool_exception,
		"ThreadPool requires a Thread-Safe (ZTS) PHP build", 0);
	RETURN_THROWS();
#endif

	if (workers < 0 || workers > INT32_MAX) {
		zend_argument_value_error(1, "must be between 0 and %d", INT32_MAX);
		RETURN_THROWS();
	}

	if (workers == 0) {
		/* Auto-detect: same source as Async\available_parallelism(). Floor
		 * at 1 if the reactor reports 0 (defensive — should not happen). */
		zend_long detected = zend_async_available_parallelism_fn != NULL
			? (zend_long) ZEND_ASYNC_AVAILABLE_PARALLELISM() : 0;
		workers = detected > 0 ? detected : 1;
	}

	if (queue_size > INT32_MAX) {
		zend_argument_value_error(2, "must be between 0 and %d", INT32_MAX);
		RETURN_THROWS();
	}

	if (concurrency < 0 || concurrency > INT32_MAX) {
		zend_argument_value_error(5, "must be between 0 and %d", INT32_MAX);
		RETURN_THROWS();
	}

	if (queue_size <= 0) {
		/* Saturate default (4 * workers) instead of wrapping. */
		queue_size = (workers > INT32_MAX / 4) ? INT32_MAX : workers * 4;
	}

	zend_fcall_t boot, *boot_ptr = NULL;
	if (bootloader_zv != NULL
		&& zend_fcall_info_init(bootloader_zv, 0, &boot.fci, &boot.fci_cache, NULL, NULL) == SUCCESS) {
		boot_ptr = &boot;
	}

	thread_pool_object_t *obj = ASYNC_THREAD_POOL_FROM_OBJ(Z_OBJ_P(ZEND_THIS));
	obj->pool = (async_thread_pool_t *) async_thread_pool_create(
		(int32_t) workers, (int32_t) queue_size, boot_ptr, coroutine_mode,
		(int32_t) concurrency);

	if (UNEXPECTED(EG(exception))) {
		/* Factory failure (e.g. bootloader deep-copy refused a captured value).
		 * Pool wrapper is already wired into obj->pool, but in a partial state —
		 * thread_pool_free_object will tear it down via thread_pool_close +
		 * destroy, which is safe (close is idempotent; destroy frees what
		 * exists). */
		RETURN_THROWS();
	}
}

METHOD(submit)
{
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;
	zval *args = NULL;
	int args_count = 0;

	ZEND_PARSE_PARAMETERS_START(1, -1)
		Z_PARAM_FUNC(fci, fcc)
		Z_PARAM_VARIADIC('+', args, args_count)
	ZEND_PARSE_PARAMETERS_END();

	async_thread_pool_t *pool = THIS_POOL();

	if (UNEXPECTED(pool == NULL)) {
		zend_throw_exception(async_ce_thread_pool_exception, "ThreadPool not initialized", 0);
		RETURN_THROWS();
	}

	if (UNEXPECTED(zend_atomic_int_load(&pool->base.closed))) {
		thread_pool_throw_closed(pool, "ThreadPool is closed");
		RETURN_THROWS();
	}

	/* channel.send may suspend on a full buffer — needs a live coroutine. */
	ZEND_ASYNC_SCHEDULER_INIT();

	/* 1. Create snapshot — deep-copies closure op_array + bound vars */
	const zend_fcall_t fcall = { .fci = fci, .fci_cache = fcc };
	async_thread_snapshot_t *snapshot = async_thread_snapshot_create(&fcall, NULL, false);

	if (UNEXPECTED(snapshot == NULL)) {
		zend_throw_exception(async_ce_thread_pool_exception, "Failed to create task snapshot", 0);
		RETURN_THROWS();
	}

	/* 2. Create shared_state + remote_future (holds trigger in parent thread) */
	zend_future_shared_state_t *state = async_future_shared_state_create();
	zend_future_remote_t *remote = async_new_remote_future(state);

	if (UNEXPECTED(remote == NULL)) {
		async_thread_snapshot_destroy(snapshot);
		async_future_shared_state_destroy(state);
		zend_throw_exception(async_ce_thread_pool_exception, "Failed to create future", 0);
		RETURN_THROWS();
	}

	/* +1 ref for the task — worker will delref after complete/reject */
	async_future_shared_state_addref(state);

	/* 3. Pack task: [kind=0, snapshot_ptr, args_array, state_ptr] */
	zval task;
	array_init_size(&task, 4);

	zval kind_zv;
	ZVAL_LONG(&kind_zv, TASK_KIND_CLOSURE);
	zend_hash_next_index_insert_new(Z_ARRVAL(task), &kind_zv);

	zval snapshot_zv;
	ZVAL_LONG(&snapshot_zv, (zend_long)(uintptr_t) snapshot);
	zend_hash_next_index_insert_new(Z_ARRVAL(task), &snapshot_zv);

	zval args_arr;
	array_init_size(&args_arr, args_count);
	for (int i = 0; i < args_count; i++) {
		zval arg_copy;
		ZVAL_COPY(&arg_copy, &args[i]);
		zend_hash_next_index_insert_new(Z_ARRVAL(args_arr), &arg_copy);
	}
	zend_hash_next_index_insert_new(Z_ARRVAL(task), &args_arr);

	zval state_zv;
	ZVAL_LONG(&state_zv, (zend_long)(uintptr_t) state);
	zend_hash_next_index_insert_new(Z_ARRVAL(task), &state_zv);

	/* 4. Send through channel (may suspend if full — backpressure) */
	async_thread_channel_t *channel = POOL_TASK_CHANNEL(pool);

	if (!channel->channel.send(&channel->channel, &task)) {
		zval_ptr_dtor(&task);
		async_thread_snapshot_destroy(snapshot);
		async_future_shared_state_delref(state);
		ZEND_ASYNC_EVENT_RELEASE(&remote->future.event);
		thread_pool_throw_closed(pool, "ThreadPool channel is closed");
		RETURN_THROWS();
	}

	zval_ptr_dtor(&task);
	zend_atomic_int_inc(&pool->base.pending_count);

	/* 4. Return Future PHP object */
	ZEND_FUTURE_SET_USED(&remote->future);
	zend_object *future_obj = async_new_future_obj(&remote->future);
	RETURN_OBJ(future_obj);
}

METHOD(map)
{
	zval *items;
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_ARRAY(items)
		Z_PARAM_FUNC(fci, fcc)
	ZEND_PARSE_PARAMETERS_END();

	async_thread_pool_t *pool = THIS_POOL();

	if (UNEXPECTED(pool == NULL)) {
		zend_throw_exception(async_ce_thread_pool_exception, "ThreadPool not initialized", 0);
		RETURN_THROWS();
	}

	if (UNEXPECTED(zend_atomic_int_load(&pool->base.closed))) {
		thread_pool_throw_closed(pool, "ThreadPool is closed");
		RETURN_THROWS();
	}

	/* See submit() — channel.send may suspend on backpressure. */
	ZEND_ASYNC_SCHEDULER_INIT();

	HashTable *ht = Z_ARRVAL_P(items);
	uint32_t count = zend_hash_num_elements(ht);

	if (count == 0) {
		array_init(return_value);
		return;
	}

	/* Submit a task for each item, collecting futures keyed by original keys */
	zval futures_arr;
	array_init_size(&futures_arr, count);

	zend_string *str_key;
	zend_ulong num_key;
	zval *item;

	ZEND_HASH_FOREACH_KEY_VAL(ht, num_key, str_key, item) {
		const zend_fcall_t fcall = { .fci = fci, .fci_cache = fcc };
		async_thread_snapshot_t *snapshot = async_thread_snapshot_create(&fcall, NULL, false);

		if (UNEXPECTED(snapshot == NULL)) {
			zval_ptr_dtor(&futures_arr);
			zend_throw_exception(async_ce_thread_pool_exception, "Failed to create task snapshot", 0);
			RETURN_THROWS();
		}

		zend_future_shared_state_t *state = async_future_shared_state_create();
		zend_future_remote_t *remote = async_new_remote_future(state);

		if (UNEXPECTED(remote == NULL)) {
			async_thread_snapshot_destroy(snapshot);
			async_future_shared_state_destroy(state);
			zval_ptr_dtor(&futures_arr);
			zend_throw_exception(async_ce_thread_pool_exception, "Failed to create future", 0);
			RETURN_THROWS();
		}

		async_future_shared_state_addref(state);

		/* Pack task: [kind=0, snapshot_ptr, args_array(1 item), state_ptr] */
		zval task;
		array_init_size(&task, 4);

		zval kind_zv;
		ZVAL_LONG(&kind_zv, TASK_KIND_CLOSURE);
		zend_hash_next_index_insert_new(Z_ARRVAL(task), &kind_zv);

		zval snapshot_zv;
		ZVAL_LONG(&snapshot_zv, (zend_long)(uintptr_t) snapshot);
		zend_hash_next_index_insert_new(Z_ARRVAL(task), &snapshot_zv);

		zval args_arr;
		array_init_size(&args_arr, 1);
		zval arg_copy;
		ZVAL_COPY(&arg_copy, item);
		zend_hash_next_index_insert_new(Z_ARRVAL(args_arr), &arg_copy);
		zend_hash_next_index_insert_new(Z_ARRVAL(task), &args_arr);

		zval state_zv;
		ZVAL_LONG(&state_zv, (zend_long)(uintptr_t) state);
		zend_hash_next_index_insert_new(Z_ARRVAL(task), &state_zv);

		async_thread_channel_t *channel = POOL_TASK_CHANNEL(pool);

		if (!channel->channel.send(&channel->channel, &task)) {
			zval_ptr_dtor(&task);
			async_thread_snapshot_destroy(snapshot);
			async_future_shared_state_delref(state);
			ZEND_ASYNC_EVENT_RELEASE(&remote->future.event);
			zval_ptr_dtor(&futures_arr);
			thread_pool_throw_closed(pool, "ThreadPool channel is closed");
			RETURN_THROWS();
		}

		zval_ptr_dtor(&task);
		zend_atomic_int_inc(&pool->base.pending_count);

		ZEND_FUTURE_SET_USED(&remote->future);
		zend_object *future_obj = async_new_future_obj(&remote->future);

		zval future_zv;
		ZVAL_OBJ(&future_zv, future_obj);

		if (str_key) {
			zend_hash_add_new(Z_ARRVAL(futures_arr), str_key, &future_zv);
		} else {
			zend_hash_index_add_new(Z_ARRVAL(futures_arr), num_key, &future_zv);
		}
	} ZEND_HASH_FOREACH_END();

	/* Await all futures */
	HashTable *results = zend_new_array(count);

	async_await_futures(&futures_arr,
						(int) count,
						false,
						NULL,
						0,
						0,
						results,
						NULL,
						false,
						true,
						false);

	zval_ptr_dtor(&futures_arr);

	if (EG(exception)) {
		zend_array_release(results);
		RETURN_THROWS();
	}

	RETURN_ARR(results);
}

METHOD(close)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	if (pool != NULL) {
		thread_pool_close(pool);
	}
}

METHOD(cancel)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	if (pool == NULL) {
		return;
	}

	/* Order matters: flag before close so workers see it on wakeup. */
	zend_atomic_int_store(&pool->cancel_requested, 1);
	thread_pool_close(pool);
	/* Reject queued (not-yet-picked-up) tasks. In-flight: sync runs to
	 * completion (not preemptible), coroutine dies via scope cascade. */
	thread_pool_drain_tasks(pool, /*reject*/ true, NULL);
}

METHOD(isClosed)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	RETURN_BOOL(pool == NULL || zend_atomic_int_load(&pool->base.closed));
}

METHOD(getPendingCount)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	RETURN_LONG(pool ? zend_atomic_int_load(&pool->base.pending_count) : 0);
}

METHOD(getRunningCount)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	RETURN_LONG(pool ? zend_atomic_int_load(&pool->base.running_count) : 0);
}

METHOD(getCompletedCount)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	RETURN_LONG(pool ? zend_atomic_int_load(&pool->base.completed_count) : 0);
}

METHOD(getWorkerCount)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	RETURN_LONG(pool ? pool->base.worker_count : 0);
}

METHOD(reload)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();

	if (UNEXPECTED(pool == NULL)) {
		zend_throw_exception(async_ce_thread_pool_exception, "ThreadPool not initialized", 0);
		RETURN_THROWS();
	}

	if (UNEXPECTED(pool->base.reload == NULL)) {
		zend_throw_exception(async_ce_thread_pool_exception,
				"This thread pool does not support reload()", 0);
		RETURN_THROWS();
	}

	pool->base.reload(&pool->base);
}

///////////////////////////////////////////////////////////
/// Class registration
///////////////////////////////////////////////////////////

void async_register_thread_pool_ce(void)
{
	async_ce_thread_pool = register_class_Async_ThreadPool();
	async_ce_thread_pool->create_object = thread_pool_create_object;

	memcpy(&thread_pool_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	thread_pool_handlers.offset = offsetof(thread_pool_object_t, std);
	thread_pool_handlers.free_obj = thread_pool_free_object;
	async_ce_thread_pool->default_object_handlers = &thread_pool_handlers;

	async_ce_thread_pool_exception = register_class_Async_ThreadPoolException(zend_ce_exception);
}
