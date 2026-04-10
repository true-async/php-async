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
#include "php_async.h"
#include "scheduler.h"
#include "thread_channel.h"
#include "future.h"
#include "zend_interfaces.h"
#include "zend_exceptions.h"

zend_class_entry *async_ce_thread_pool = NULL;
zend_class_entry *async_ce_thread_pool_exception = NULL;

static zend_object_handlers thread_pool_handlers;

#define METHOD(name) PHP_METHOD(Async_ThreadPool, name)
#define THIS_POOL() (ASYNC_THREAD_POOL_FROM_OBJ(Z_OBJ_P(ZEND_THIS))->pool)

///////////////////////////////////////////////////////////
/// Pool refcount
///////////////////////////////////////////////////////////

static void thread_pool_destroy(async_thread_pool_t *pool);

static void thread_pool_addref(async_thread_pool_t *pool)
{
	zend_atomic_int_inc(&pool->ref_count);
}

static void thread_pool_delref(async_thread_pool_t *pool)
{
	int old = zend_atomic_int_dec(&pool->ref_count);
	if (old == 1) {
		thread_pool_destroy(pool);
	}
}

///////////////////////////////////////////////////////////
/// Worker entry — C handler called inside spawned thread
///////////////////////////////////////////////////////////

/**
 * @brief Worker loop — receives tasks from channel, executes, completes.
 *
 * ctx = async_thread_pool_t* (shared pool).
 * Each task is an array: [callable, args_array, shared_state_ptr_as_long]
 * transferred through ThreadChannel automatically.
 */
static zend_function worker_root_function = { ZEND_INTERNAL_FUNCTION };

static void thread_pool_worker_handler(zend_async_thread_event_t *event, void *ctx)
{
	async_thread_pool_t *pool = (async_thread_pool_t *) ctx;
	async_thread_channel_t *channel = pool->task_channel;
	int bailout = 0;

	(void)event;

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

		zval task;
		while (channel->channel.receive(&channel->channel, &task)) {
			ZEND_ASSERT(Z_TYPE(task) == IS_ARRAY);

			/* Extract: [snapshot_ptr, args_array, state_ptr] */
			async_thread_snapshot_t *snapshot =
				(async_thread_snapshot_t *)(uintptr_t) Z_LVAL_P(zend_hash_index_find(Z_ARRVAL(task), 0));
			const zval *args_zv = zend_hash_index_find(Z_ARRVAL(task), 1);
			zend_future_shared_state_t *state =
				(zend_future_shared_state_t *)(uintptr_t) Z_LVAL_P(zend_hash_index_find(Z_ARRVAL(task), 2));

			zval callable, retval;
			zval *params = NULL;
			uint32_t param_count = 0;
			ZVAL_UNDEF(&retval);
			ZVAL_UNDEF(&callable);

			zend_atomic_int_dec(&pool->pending_count);
			zend_atomic_int_inc(&pool->running_count);

			async_thread_create_closure(&snapshot->entry, &callable);

			if (UNEXPECTED(EG(exception))) {
				async_future_shared_state_reject(state, EG(exception));
				zend_clear_exception();
				goto task_cleanup;
			}

			zend_fcall_info fci;
			zend_fcall_info_cache fcc;

			if (UNEXPECTED(zend_fcall_info_init(&callable, 0, &fci, &fcc, NULL, NULL) != SUCCESS)) {
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

			if (zend_call_function(&fci, &fcc) == SUCCESS && !EG(exception)) {
				async_future_shared_state_complete(state, &retval);
			} else if (EG(exception)) {
				async_future_shared_state_reject(state, EG(exception));
				zend_clear_exception();
			}

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

			zend_atomic_int_dec(&pool->running_count);
		}

		if (EG(exception)) {
			if (!instanceof_function(EG(exception)->ce, async_ce_thread_channel_exception)) {
				zend_exception_error(EG(exception), E_WARNING);
			}
			zend_clear_exception();
		}

	done:
		ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false);

	} zend_catch {
		bailout = 1;
	} zend_end_try();

	/* Restore execute_data */
	EG(current_execute_data) = fake_frame.prev_execute_data;

	/* Release worker's ref on pool */
	thread_pool_delref(pool);

	if (bailout) {
		zend_bailout();
	}
}

///////////////////////////////////////////////////////////
/// Pool lifecycle
///////////////////////////////////////////////////////////

static async_thread_pool_t *thread_pool_create(int32_t worker_count, int32_t queue_size)
{
	async_thread_pool_t *pool = pecalloc(1, sizeof(async_thread_pool_t), 1);

	pool->worker_count = worker_count;
	ZEND_ATOMIC_INT_INIT(&pool->pending_count, 0);
	ZEND_ATOMIC_INT_INIT(&pool->running_count, 0);
	ZEND_ATOMIC_INT_INIT(&pool->closed, 0);
	ZEND_ATOMIC_INT_INIT(&pool->ref_count, 1); /* PHP object holds 1 ref */

	/* Create shared task channel */
	pool->task_channel = async_thread_channel_create(queue_size);

	/* Create worker threads (pemalloc — shared between threads) */
	pool->workers = pecalloc(worker_count, sizeof(zend_async_thread_event_t *), 1);

	for (int32_t i = 0; i < worker_count; i++) {
		/* +1 ref for this worker */
		thread_pool_addref(pool);

		/* Create internal entry for this worker */
		zend_async_thread_internal_entry_t *entry = pecalloc(1, sizeof(zend_async_thread_internal_entry_t), 1);
		entry->handler = thread_pool_worker_handler;
		entry->ctx = pool;

		zend_async_thread_event_t *thread_event = ZEND_ASYNC_NEW_THREAD_EVENT(NULL, NULL);

		if (UNEXPECTED(thread_event == NULL)) {
			pefree(entry, 1);
			thread_pool_delref(pool); /* undo addref for this worker */
			zend_atomic_int_store(&pool->closed, 1);
			pool->worker_count = i;
			return pool;
		}

		thread_event->internal_entry = entry;
		pool->workers[i] = thread_event;

		/* Start the thread */
		thread_event->base.start(&thread_event->base);
	}

	return pool;
}

static void thread_pool_close(async_thread_pool_t *pool)
{
	if (zend_atomic_int_load(&pool->closed)) {
		return;
	}

	zend_atomic_int_store(&pool->closed, 1);

	/* Close task channel — workers see closed on next recv and exit */
	if (pool->task_channel != NULL) {
		pool->task_channel->channel.close(&pool->task_channel->channel);
	}
}

/**
 * Drain remaining tasks from channel buffer.
 * For each undispatched task, release the worker's shared_state ref.
 */
static void thread_pool_drain_tasks(async_thread_pool_t *pool)
{
	async_thread_channel_t *ch = pool->task_channel;
	if (ch == NULL) {
		return;
	}

	zval persistent_task;
	pthread_mutex_lock(&ch->mutex);
	while (circular_buffer_is_not_empty(&ch->buffer) &&
		   circular_buffer_pop(&ch->buffer, &persistent_task) == SUCCESS) {
		pthread_mutex_unlock(&ch->mutex);

		/* Load task to extract pointers */
		zval task;
		async_thread_load_zval(&task, &persistent_task);
		async_thread_release_transferred_zval(&persistent_task);

		/* [0] = snapshot_ptr, [1] = args, [2] = state_ptr */
		const zval *snapshot_zv = zend_hash_index_find(Z_ARRVAL(task), 0);
		if (snapshot_zv != NULL && Z_TYPE_P(snapshot_zv) == IS_LONG) {
			async_thread_snapshot_t *snapshot =
				(async_thread_snapshot_t *)(uintptr_t) Z_LVAL_P(snapshot_zv);
			async_thread_snapshot_destroy(snapshot);
		}

		const zval *state_zv = zend_hash_index_find(Z_ARRVAL(task), 2);
		if (state_zv != NULL && Z_TYPE_P(state_zv) == IS_LONG) {
			zend_future_shared_state_t *state =
				(zend_future_shared_state_t *)(uintptr_t) Z_LVAL_P(state_zv);
			async_future_shared_state_delref(state);
		}

		zval_ptr_dtor(&task);
		pthread_mutex_lock(&ch->mutex);
	}
	pthread_mutex_unlock(&ch->mutex);
}

/**
 * Destroy the real pool. Called when ref_count reaches 0.
 * By this point all workers have exited and released their refs.
 */
static void thread_pool_destroy(async_thread_pool_t *pool)
{
	thread_pool_drain_tasks(pool);

	if (pool->task_channel != NULL) {
		pool->task_channel->channel.event.dispose(&pool->task_channel->channel.event);
		pool->task_channel = NULL;
	}

	if (pool->workers != NULL) {
		pefree(pool->workers, 1);
		pool->workers = NULL;
	}

	pefree(pool, 1);
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
		/* Close channel so workers exit */
		thread_pool_close(obj->pool);

		/* Wait for workers to finish (join) and free thread events */
		for (int32_t i = 0; i < obj->pool->worker_count; i++) {
			if (obj->pool->workers[i] != NULL) {
				zend_async_event_t *ev = &obj->pool->workers[i]->base;
				if (ev->dispose) {
					ev->dispose(ev);
				}
				obj->pool->workers[i] = NULL;
			}
		}

		thread_pool_delref(obj->pool);
		obj->pool = NULL;
	}

	zend_object_std_dtor(object);
}

///////////////////////////////////////////////////////////
/// PHP Methods
///////////////////////////////////////////////////////////

METHOD(__construct)
{
	zend_long workers;
	zend_long queue_size = 0;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_LONG(workers)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(queue_size)
	ZEND_PARSE_PARAMETERS_END();

	if (workers < 1) {
		zend_argument_value_error(1, "must be >= 1");
		RETURN_THROWS();
	}

	if (queue_size <= 0) {
		queue_size = workers * 4;
	}

	thread_pool_object_t *obj = ASYNC_THREAD_POOL_FROM_OBJ(Z_OBJ_P(ZEND_THIS));
	obj->pool = thread_pool_create((int32_t) workers, (int32_t) queue_size);
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

	if (UNEXPECTED(zend_atomic_int_load(&pool->closed))) {
		zend_throw_exception(async_ce_thread_pool_exception, "ThreadPool is closed", 0);
		RETURN_THROWS();
	}

	/* 1. Create snapshot — deep-copies closure op_array + bound vars */
	const zend_fcall_t fcall = { .fci = fci, .fci_cache = fcc };
	async_thread_snapshot_t *snapshot = async_thread_snapshot_create(&fcall, NULL);

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

	/* 3. Pack task: [snapshot_ptr, args_array, state_ptr] */
	zval task;
	array_init_size(&task, 3);

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
	if (!pool->task_channel->channel.send(&pool->task_channel->channel, &task)) {
		zval_ptr_dtor(&task);
		async_thread_snapshot_destroy(snapshot);
		async_future_shared_state_delref(state);
		ZEND_ASYNC_EVENT_RELEASE(&remote->future.event);
		if (!EG(exception)) {
			zend_throw_exception(async_ce_thread_pool_exception, "ThreadPool channel is closed", 0);
		}
		RETURN_THROWS();
	}

	zval_ptr_dtor(&task);
	zend_atomic_int_inc(&pool->pending_count);

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

	if (UNEXPECTED(zend_atomic_int_load(&pool->closed))) {
		zend_throw_exception(async_ce_thread_pool_exception, "ThreadPool is closed", 0);
		RETURN_THROWS();
	}

	const HashTable *ht = Z_ARRVAL_P(items);
	uint32_t count = zend_hash_num_elements(ht);

	if (count == 0) {
		array_init(return_value);
		return;
	}

	/* TODO: submit all + await_all */
	array_init(return_value);
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

	thread_pool_close(pool);
}

METHOD(isClosed)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	RETURN_BOOL(pool == NULL || zend_atomic_int_load(&pool->closed));
}

METHOD(getPendingCount)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	RETURN_LONG(pool ? zend_atomic_int_load(&pool->pending_count) : 0);
}

METHOD(getRunningCount)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	RETURN_LONG(pool ? zend_atomic_int_load(&pool->running_count) : 0);
}

METHOD(count)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	if (pool == NULL) {
		RETURN_LONG(0);
	}
	RETURN_LONG(zend_atomic_int_load(&pool->pending_count) + zend_atomic_int_load(&pool->running_count));
}

METHOD(getWorkerCount)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_thread_pool_t *pool = THIS_POOL();
	RETURN_LONG(pool ? pool->worker_count : 0);
}

///////////////////////////////////////////////////////////
/// Class registration
///////////////////////////////////////////////////////////

void async_register_thread_pool_ce(void)
{
	async_ce_thread_pool = register_class_Async_ThreadPool(zend_ce_countable);
	async_ce_thread_pool->create_object = thread_pool_create_object;

	memcpy(&thread_pool_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	thread_pool_handlers.offset = XtOffsetOf(thread_pool_object_t, std);
	thread_pool_handlers.free_obj = thread_pool_free_object;
	async_ce_thread_pool->default_object_handlers = &thread_pool_handlers;

	async_ce_thread_pool_exception = register_class_Async_ThreadPoolException(zend_ce_exception);
}
