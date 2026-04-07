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
#include "thread_channel.h"
#include "zend_interfaces.h"
#include "zend_exceptions.h"

zend_class_entry *async_ce_thread_pool = NULL;
zend_class_entry *async_ce_thread_pool_exception = NULL;

static zend_object_handlers thread_pool_handlers;

#define METHOD(name) PHP_METHOD(Async_ThreadPool, name)
#define THIS_POOL() (ASYNC_THREAD_POOL_FROM_OBJ(Z_OBJ_P(ZEND_THIS))->pool)

///////////////////////////////////////////////////////////
/// Worker entry — C handler called inside spawned thread
///////////////////////////////////////////////////////////

/**
 * @brief Worker loop — receives tasks from channel, executes, completes.
 *
 * ctx = async_thread_channel_t* (shared task channel)
 * Each task is an array: [callable, FutureState, args_array]
 * transferred through ThreadChannel automatically.
 */
static void thread_pool_worker_handler(zend_async_thread_event_t *event, void *ctx)
{
	async_thread_channel_t *channel = (async_thread_channel_t *) ctx;

	/* Create local PHP wrapper for the channel */
	/* TODO: implement worker recv loop using channel */
	(void)channel;
	(void)event;
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
	ZEND_ATOMIC_INT_INIT(&pool->ref_count, 1);

	/* Create shared task channel */
	pool->task_channel = async_thread_channel_create(queue_size);

	/* Create worker threads */
	pool->workers = ecalloc(worker_count, sizeof(zend_async_thread_event_t *));

	for (int32_t i = 0; i < worker_count; i++) {
		/* Create internal entry for this worker */
		zend_async_thread_internal_entry_t *entry = pecalloc(1, sizeof(zend_async_thread_internal_entry_t), 1);
		entry->handler = thread_pool_worker_handler;
		entry->ctx = pool->task_channel;

		zend_async_thread_event_t *thread_event = ZEND_ASYNC_NEW_THREAD_EVENT(NULL, NULL);

		if (UNEXPECTED(thread_event == NULL)) {
			pefree(entry, 1);
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

	/* Close task channel — workers will get exception on recv and exit */
	if (pool->task_channel != NULL) {
		/* TODO: close channel */
	}
}

static void thread_pool_destroy(async_thread_pool_t *pool)
{
	thread_pool_close(pool);

	/* TODO: drain remaining tasks from channel */

	if (pool->task_channel != NULL) {
		/* TODO: release channel */
		pool->task_channel = NULL;
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
		thread_pool_close(obj->pool);

		/* Stop and dispose worker thread events */
		for (int32_t i = 0; i < obj->pool->worker_count; i++) {
			if (obj->pool->workers[i] != NULL) {
				zend_async_event_t *event = &obj->pool->workers[i]->base;
				if (event->dispose) {
					event->dispose(event);
				}
			}
		}
		efree(obj->pool->workers);

		thread_pool_destroy(obj->pool);
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
	HashTable *named_args = NULL;

	ZEND_PARSE_PARAMETERS_START(1, -1)
		Z_PARAM_FUNC(fci, fcc)
		Z_PARAM_VARIADIC_WITH_NAMED(args, args_count, named_args)
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

	/* Create local future + shared state */
	zend_future_t *future = async_new_future(false, 0);
	zend_future_shared_state_t *state = async_future_shared_state_create();
	async_future_shared_state_bind(state, future);

	/* Pack task as array: [callable, FutureState, args] */
	/* TODO: send through channel */

	ZEND_FUTURE_SET_USED(future);
	zend_object *future_obj = async_new_future_obj(future);
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

	HashTable *ht = Z_ARRVAL_P(items);
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
