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
#include "iterator.h"

#include "exceptions.h"
#include "php_async.h"
#include "zend_exceptions.h"

///////////////////////////////////////////////////////////////////
/// Iterator completion event
///////////////////////////////////////////////////////////////////

static bool completion_event_add_callback(
		zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_push(event, callback);
}

static bool completion_event_del_callback(
		zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_remove(event, callback);
}

static bool completion_event_start(zend_async_event_t *event)
{
	return true;
}

static bool completion_event_stop(zend_async_event_t *event)
{
	return true;
}

static bool completion_event_dispose(zend_async_event_t *event)
{
	zend_async_callbacks_free(event);
	efree(event);
	return true;
}

static zend_string *completion_event_info(zend_async_event_t *event)
{
	return zend_string_init("iterator-completion", sizeof("iterator-completion") - 1, 0);
}

zend_async_event_t *async_iterator_completion_event_create(void)
{
	zend_async_event_t *event = ecalloc(1, sizeof(zend_async_event_t));

	event->ref_count = 1;
	event->add_callback = completion_event_add_callback;
	event->del_callback = completion_event_del_callback;
	event->start = completion_event_start;
	event->stop = completion_event_stop;
	event->dispose = completion_event_dispose;
	event->info = completion_event_info;

	return event;
}

///////////////////////////////////////////////////////////////////

/**
 *  An additional coroutine destructor that frees the iterator if the coroutine never started.
 */
void coroutine_extended_dispose(zend_coroutine_t *coroutine)
{
	if (coroutine->extended_data == NULL) {
		return;
	}

	async_iterator_t *iterator = coroutine->extended_data;
	coroutine->extended_data = NULL;
	iterator->microtask.dtor(&iterator->microtask);
}

/**
 * Coroutine destructor that rethrows the iterator exception before disposal.
 */
static void coroutine_extended_dispose_with_exception(zend_coroutine_t *coroutine)
{
	if (coroutine->extended_data == NULL) {
		return;
	}

	async_iterator_t *iterator = coroutine->extended_data;

	if (iterator->exception != NULL) {
		zend_object *exception = iterator->exception;
		iterator->exception = NULL;
		async_rethrow_exception(exception);
	}

	coroutine_extended_dispose(coroutine);
}

static void coroutine_entry(void);

void iterator_microtask(zend_async_microtask_t *microtask)
{
	async_iterator_t *iterator = (async_iterator_t *) microtask;

	if (iterator->state == ASYNC_ITERATOR_FINISHED ||
		(iterator->concurrency > 0 && iterator->active_coroutines >= iterator->concurrency)) {
		return;
	}

	zend_coroutine_t *coroutine = ZEND_ASYNC_SPAWN_WITH_SCOPE_EX(iterator->scope, iterator->priority);

	if (coroutine == NULL) {
		return;
	}

	ZEND_ASYNC_MICROTASK_ADD_REF(microtask);
	iterator->active_coroutines++;
	coroutine->internal_entry = coroutine_entry;
	coroutine->extended_data = iterator;
	coroutine->extended_dispose = coroutine_extended_dispose;
}

void iterator_dtor(zend_async_microtask_t *microtask)
{
	if (microtask->ref_count > 1) {
		microtask->ref_count--;
		return;
	}

	microtask->ref_count = 0;

	async_iterator_t *iterator = (async_iterator_t *) microtask;

	if (iterator->extended_dtor != NULL) {
		// Call the extended destructor if it exists
		ASYNC_ITERATOR_DTOR extended_dtor = iterator->extended_dtor;
		iterator->extended_dtor = NULL;
		extended_dtor((zend_async_iterator_t *) iterator);
	}

	// Free hash iterator if it was allocated
	if (iterator->hash_iterator != -1) {
		zend_hash_iterator_del(iterator->hash_iterator);
	}

	// Free copied array if it was copied
	if (Z_TYPE(iterator->array) != IS_UNDEF) {
		zval_ptr_dtor(&iterator->array);
	}

	if (iterator->fcall != NULL) {
		zend_fcall_release(iterator->fcall);
		iterator->fcall = NULL;
	}

	if (iterator->completion_event != NULL) {
		zend_async_event_t *event = iterator->completion_event;
		iterator->completion_event = NULL;
		ZEND_ASYNC_CALLBACKS_NOTIFY_AND_CLOSE(event, NULL, iterator->exception);
		ZEND_ASYNC_EVENT_RELEASE(event);
	}

	if (iterator->exception != NULL) {
		zend_object *exception = iterator->exception;
		iterator->exception = NULL;
		OBJ_RELEASE(exception);
	}

	efree(microtask);
}

//
// Start of the block for safe iterator modification.
//
// Safe iterator modification means making changes during which no new iterator coroutines will be created,
// because the iterator’s state is undefined.
//
#define ITERATOR_SAFE_MOVING_START(iterator) \
	(iterator)->state = ASYNC_ITERATOR_MOVING; \
	(iterator)->microtask.is_cancelled = true; \
	uint32_t prev_ref_count = (iterator)->microtask.ref_count;

//
// End of the block for safe iterator modification.
//
#define ITERATOR_SAFE_MOVING_END(iterator) \
	(iterator)->state = ASYNC_ITERATOR_STARTED; \
	if (prev_ref_count != (iterator)->microtask.ref_count) { \
		(iterator)->microtask.is_cancelled = false; \
		ZEND_ASYNC_ADD_MICROTASK(&(iterator)->microtask); \
	}

async_iterator_t *async_iterator_new(zval *array,
									 zend_object_iterator *zend_iterator,
									 zend_fcall_t *fcall,
									 async_iterator_handler_t handler,
									 zend_async_scope_t *scope,
									 unsigned int concurrency,
									 int32_t priority,
									 size_t iterator_size)
{
	if (iterator_size == 0) {
		iterator_size = sizeof(async_iterator_t);
	}

	async_iterator_t *iterator = ecalloc(1, iterator_size);

	iterator->microtask.handler = iterator_microtask;
	iterator->microtask.dtor = iterator_dtor;
	iterator->microtask.ref_count = 1;
	iterator->hash_iterator = -1;

	iterator->run = (void (*)(zend_async_iterator_t *)) async_iterator_run;
	iterator->run_in_coroutine = (void (*)(zend_async_iterator_t *, int32_t, bool)) async_iterator_run_in_coroutine;

	iterator->state = ASYNC_ITERATOR_INIT;

	iterator->concurrency = concurrency;
	iterator->priority = priority;

	if (scope == NULL) {
		scope = ZEND_ASYNC_CURRENT_SCOPE;
	}

	iterator->scope = scope;

	if (zend_iterator == NULL) {
		ZVAL_COPY(&iterator->array, array);
	} else if (zend_iterator) {
		iterator->zend_iterator = zend_iterator;
	} else {
		zend_error(E_ERROR, "Invalid iterator: futures and iterator are NULL");
		return NULL;
	}

	if (fcall != NULL) {
		iterator->fcall = fcall;
	} else if (handler != NULL) {
		iterator->handler = handler;
	} else {
		zend_error(E_ERROR, "Invalid iterator function: fcall and internal are NULL");
		return NULL;
	}

	return iterator;
}

#define RETURN_IF_EXCEPTION(iterator) \
	if (UNEXPECTED(EG(exception))) { \
		iterator->state = ASYNC_ITERATOR_FINISHED; \
		iterator->microtask.is_cancelled = true; \
		return; \
	}

#define RETURN_IF_EXCEPTION_AND(iterator, and) \
	if (UNEXPECTED(EG(exception))) { \
		iterator->state = ASYNC_ITERATOR_FINISHED; \
		iterator->microtask.is_cancelled = true; \
		and; \
		return; \
	}

static zend_always_inline void iterate(async_iterator_t *iterator)
{
	zend_result result = SUCCESS;
	zval retval;
	ZVAL_UNDEF(&retval);

	if (UNEXPECTED(iterator->state == ASYNC_ITERATOR_MOVING)) {
		// The iterator is in a state of waiting for a position change.
		// The coroutine cannot continue execution because
		// it cannot move the iterator to the next position.
		// We exit immediately.
		return;
	}

	zend_fcall_info fci;
	fci.params = NULL;

	// Copy the fci to avoid overwriting the original
	// Because the another coroutine may be started in the callback function
	if (iterator->fcall != NULL) {
		fci = iterator->fcall->fci;

		// Copy the args to avoid overwriting the original
		fci.params = safe_emalloc(fci.param_count, sizeof(zval), 0);

		for (uint32_t i = 0; i < fci.param_count; i++) {
			ZVAL_COPY(&fci.params[i], &iterator->fcall->fci.params[i]);
		}

		fci.retval = &retval;
	}

	if (iterator->zend_iterator == NULL) {

		if (iterator->hash_iterator == -1) {
			iterator->position = 0;
			ZEND_ASSERT(!(GC_FLAGS(Z_ARRVAL(iterator->array)) & GC_IMMUTABLE) &&
				"Iterator array must not be immutable; caller must SEPARATE_ARRAY before passing");
			zend_hash_internal_pointer_reset_ex(Z_ARRVAL(iterator->array), &iterator->position);
			iterator->hash_iterator = zend_hash_iterator_add(Z_ARRVAL(iterator->array), iterator->position);
		}

		// Reload target_hash and position if iterator->target_hash is not NULL
		if (iterator->target_hash != NULL) {
			iterator->position = zend_hash_iterator_pos_ex(iterator->hash_iterator, &iterator->array);
			iterator->target_hash = Z_ARRVAL(iterator->array);
		} else {
			// or just set it to the array
			iterator->target_hash = Z_ARRVAL(iterator->array);
		}
	} else if (iterator->state == ASYNC_ITERATOR_INIT) {
		iterator->state = ASYNC_ITERATOR_STARTED;
		iterator->position = 0;
		iterator->hash_iterator = -1;

		if (iterator->zend_iterator->funcs->rewind) {
			ITERATOR_SAFE_MOVING_START(iterator)
			{
				iterator->zend_iterator->funcs->rewind(iterator->zend_iterator);
			}
			ITERATOR_SAFE_MOVING_END(iterator);
		}

		RETURN_IF_EXCEPTION(iterator);
	}

	zval *current;
	zval current_item;
	zval key;
	ZVAL_UNDEF(&current_item);

	while (iterator->state != ASYNC_ITERATOR_FINISHED) {

		if (iterator->state == ASYNC_ITERATOR_MOVING) {
			// The iterator is in a state of waiting for a position change.
			// The coroutine cannot continue execution because
			// it cannot move the iterator to the next position.
			break;
		}

		if (iterator->target_hash != NULL) {
			current = zend_hash_get_current_data_ex(iterator->target_hash, &iterator->position);
		} else if (SUCCESS == iterator->zend_iterator->funcs->valid(iterator->zend_iterator)) {

			RETURN_IF_EXCEPTION(iterator);
			ITERATOR_SAFE_MOVING_START(iterator)
			{
				current = iterator->zend_iterator->funcs->get_current_data(iterator->zend_iterator);
			}
			ITERATOR_SAFE_MOVING_END(iterator);
			RETURN_IF_EXCEPTION(iterator);

			if (current != NULL) {
				ZVAL_COPY(&current_item, current);
				current = &current_item;
			}
		} else {
			current = NULL;
		}

		if (current == NULL) {
			iterator->state = ASYNC_ITERATOR_FINISHED;
			iterator->microtask.is_cancelled = true;
			break;
		}

		/* Skip undefined indirect elements */
		if (Z_TYPE_P(current) == IS_INDIRECT) {

			current = Z_INDIRECT_P(current);
			zval_ptr_dtor(&current_item);

			if (Z_TYPE_P(current) == IS_UNDEF) {
				if (iterator->zend_iterator == NULL) {
					zend_hash_move_forward(Z_ARR(iterator->array));
				} else {

					if (iterator->state == ASYNC_ITERATOR_MOVING) {
						return;
					}

					ITERATOR_SAFE_MOVING_START(iterator)
					{
						iterator->zend_iterator->funcs->move_forward(iterator->zend_iterator);
					}
					ITERATOR_SAFE_MOVING_END(iterator);

					RETURN_IF_EXCEPTION(iterator);
				}

				continue;
			}
		}

		/* Retrieve key */
		if (iterator->target_hash != NULL) {
			zend_hash_get_current_key_zval_ex(iterator->target_hash, &key, &iterator->position);
		} else {
			ITERATOR_SAFE_MOVING_START(iterator)
			{
				iterator->zend_iterator->funcs->get_current_key(iterator->zend_iterator, &key);
			}
			ITERATOR_SAFE_MOVING_END(iterator);

			RETURN_IF_EXCEPTION_AND(iterator, zval_ptr_dtor(&current_item));
		}

		/*
		 * Move to next element already now -- this mirrors the approach used by foreach
		 * and ensures proper behavior with regard to modifications.
		 */
		if (iterator->target_hash != NULL) {
			zend_hash_move_forward_ex(iterator->target_hash, &iterator->position);
			// And update the iterator position
			EG(ht_iterators)[iterator->hash_iterator].pos = iterator->position;
		} else {

			ITERATOR_SAFE_MOVING_START(iterator)
			{
				iterator->zend_iterator->funcs->move_forward(iterator->zend_iterator);
			}
			ITERATOR_SAFE_MOVING_END(iterator);

			RETURN_IF_EXCEPTION_AND(iterator, zval_ptr_dtor(&current_item); zval_ptr_dtor(&key));
		}

		if (iterator->fcall != NULL) {
			/* Call the userland function */
			ZVAL_COPY(&fci.params[0], current);
			ZVAL_COPY_VALUE(&fci.params[1], &key);
			result = zend_call_function(&fci, &iterator->fcall->fci_cache);
		} else {
			/* Call the internal function */
			result = iterator->handler(iterator, current, &key);
		}

		zval_ptr_dtor(&current_item);
		zval_ptr_dtor(&key);

		if (result == SUCCESS) {

			if (Z_TYPE(retval) == IS_FALSE) {
				iterator->state = ASYNC_ITERATOR_FINISHED;
				iterator->microtask.is_cancelled = true;
			}

			zval_ptr_dtor(&retval);

			/* Reload array and position */
			if (iterator->target_hash != NULL) {
				iterator->position = zend_hash_iterator_pos_ex(iterator->hash_iterator, &iterator->array);
				iterator->target_hash = Z_ARRVAL(iterator->array);
			}
		}

		if (iterator->fcall != NULL) {
			zval_ptr_dtor(&fci.params[0]);

			if (Z_TYPE(fci.params[1]) != IS_UNDEF) {
				zval_ptr_dtor(&fci.params[1]);
				ZVAL_UNDEF(&fci.params[1]);
			}
		}

		if (UNEXPECTED(result == FAILURE || EG(exception) != NULL)) {
			iterator->state = ASYNC_ITERATOR_FINISHED;
			iterator->microtask.is_cancelled = true;
			break;
		}
	}

	if (fci.params != NULL) {

		for (uint32_t i = 0; i < fci.param_count; i++) {
			zval_ptr_dtor(&fci.params[i]);
		}

		efree(fci.params);
	}
}

static void coroutine_entry(void)
{
	if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL || ZEND_ASYNC_CURRENT_COROUTINE->extended_data == NULL)) {
		async_throw_error("Invalid coroutine context for concurrent iterator");
		return;
	}

	async_iterator_t *iterator = ZEND_ASYNC_CURRENT_COROUTINE->extended_data;
	ZEND_ASYNC_CURRENT_COROUTINE->extended_data = NULL;

	async_iterator_run(iterator);

	if (iterator->active_coroutines > 1) {
		iterator->active_coroutines--;
	} else {
		iterator->active_coroutines = 0;
		iterator->state = ASYNC_ITERATOR_FINISHED;

		if (iterator->completion_event != NULL) {
			zend_async_event_t *event = iterator->completion_event;
			iterator->completion_event = NULL;
			ZEND_ASYNC_CALLBACKS_NOTIFY_AND_CLOSE(event, NULL, iterator->exception);
			ZEND_ASYNC_EVENT_RELEASE(event);
		}
	}

	iterator->microtask.dtor(&iterator->microtask);
}

/**
 * Starts the iteration process in the current coroutine.
 *
 * @param iterator
 */
void async_iterator_run(async_iterator_t *iterator)
{
	if (UNEXPECTED(ZEND_ASYNC_IS_SCHEDULER_CONTEXT)) {
		async_throw_error("The iterator cannot be run in the scheduler context");
		return;
	}

	if (iterator->scope == NULL) {
		iterator->scope = ZEND_ASYNC_CURRENT_SCOPE;
	}

	ZEND_ASYNC_ADD_MICROTASK(&iterator->microtask);

	iterate(iterator);
	async_iterator_apply_exception(iterator);

	if (iterator->state == ASYNC_ITERATOR_FINISHED
		&& iterator->active_coroutines == 0
		&& iterator->completion_event != NULL) {

		zend_async_event_t *event = iterator->completion_event;
		iterator->completion_event = NULL;
		ZEND_ASYNC_CALLBACKS_NOTIFY_AND_CLOSE(event, NULL, iterator->exception);
		ZEND_ASYNC_EVENT_RELEASE(event);
	}
}

/**
 * Starts the iterator in a separate coroutine.
 * @param iterator
 * @param priority
 * @param throw_exception If true, rethrow the iterator exception on dispose
 */
void async_iterator_run_in_coroutine(async_iterator_t *iterator, int32_t priority, bool throw_exception)
{
	if (iterator->scope == NULL) {
		iterator->scope = ZEND_ASYNC_CURRENT_SCOPE;
	}

	zend_coroutine_t *iterator_coroutine = ZEND_ASYNC_SPAWN_WITH_SCOPE_EX(iterator->scope, priority);
	if (UNEXPECTED(iterator_coroutine == NULL || EG(exception))) {
		return;
	}

	iterator_coroutine->extended_data = iterator;
	iterator_coroutine->internal_entry = coroutine_entry;
	iterator_coroutine->extended_dispose = throw_exception
		? coroutine_extended_dispose_with_exception
		: coroutine_extended_dispose;
}

void async_iterator_apply_exception(async_iterator_t *iterator)
{
	async_apply_exception(&iterator->exception);

	if (iterator->exception == NULL || ZEND_ASYNC_SCOPE_IS_CANCELLED(iterator->scope)) {
		return;
	}

	ZEND_ASYNC_SCOPE_CANCEL(
			iterator->scope,
			async_new_exception(async_ce_cancellation_exception, "Cancellation of the iterator due to an exception"),
			true,
			ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(iterator->scope));
}