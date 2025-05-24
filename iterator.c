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

void coroutine_extended_dispose(zend_coroutine_t * coroutine)
{
	if (coroutine->extended_data == NULL) {
		return;
	}

	async_iterator_t *iterator = coroutine->extended_data;
	coroutine->extended_data = NULL;

	if (iterator->microtask.ref_count > 0) {
		iterator->microtask.ref_count--;
	}

	if (iterator->microtask.ref_count == 0) {
		iterator->microtask.dtor(&iterator->microtask);
	}
}

static void coroutine_entry(void);

void iterator_microtask(zend_async_microtask_t *microtask)
{
	async_iterator_t *iterator = (async_iterator_t *) microtask;

	if (iterator->state == ASYNC_ITERATOR_FINISHED || iterator->active_coroutines >= iterator->concurrency) {
		return;
	}

	zend_coroutine_t * coroutine = ZEND_ASYNC_SPAWN();

	if (coroutine == NULL) {
		return;
	}

	iterator->active_coroutines++;
	coroutine->internal_entry = coroutine_entry;
	coroutine->extended_data = iterator;
	coroutine->extended_dispose = coroutine_extended_dispose;
}

void iterator_dtor(zend_async_microtask_t *microtask)
{
}

async_iterator_t * async_new_iterator(
		zval *array,
		zend_object_iterator *zend_iterator,
		zend_fcall_t *fcall,
		async_iterator_handler_t handler,
		unsigned int concurrency,
		size_t iterator_size
	)
{
	if (iterator_size == 0) {
		iterator_size = sizeof(async_iterator_t);
	}

	async_iterator_t * iterator = ecalloc(1, iterator_size);

	iterator->microtask.handler = iterator_microtask;
	iterator->microtask.dtor = iterator_dtor;
	iterator->microtask.ref_count = 1;
	iterator->state = ASYNC_ITERATOR_INIT;

	iterator->concurrency = concurrency;

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

static zend_always_inline void iterate(async_iterator_t *iterator)
{
	zend_result result = SUCCESS;
	zval retval;

	zend_fcall_info fci;

	// Copy the fci to avoid overwriting the original
	// Because the another coroutine may be started in the callback function
	if (iterator->fcall != NULL) {
		fci = iterator->fcall->fci;

		// Copy the args to avoid overwriting the original
		fci.params = safe_emalloc(iterator->fcall->fci.param_count, sizeof(zval), 0);

		for (uint32_t i = 0; i < iterator->fcall->fci.param_count; i++) {
			ZVAL_COPY(&fci.params[i], &iterator->fcall->fci.params[i]);
		}

		fci.retval = &retval;
	}

	if (iterator->zend_iterator == NULL) {
		iterator->position	= 0;
		iterator->hash_iterator	= -1;

		zend_hash_internal_pointer_reset_ex(Z_ARRVAL(iterator->array), &iterator->position);
		iterator->hash_iterator = zend_hash_iterator_add(Z_ARRVAL(iterator->array), iterator->position);

		// Reload target_hash and position if iterator->target_hash is not NULL
		if (iterator->target_hash != NULL) {
			iterator->position = zend_hash_iterator_pos_ex(iterator->hash_iterator, &iterator->array);
			iterator->target_hash = Z_ARRVAL(iterator->array);
		} else {
			// or just set it to the array
			iterator->target_hash = Z_ARRVAL(iterator->array);
		}
	}

	zval * current;
	zval key;

	while (iterator->state != ASYNC_ITERATOR_FINISHED) {

		if (iterator->target_hash != NULL) {
			current = zend_hash_get_current_data_ex(iterator->target_hash, &iterator->position);
		} else if (SUCCESS == iterator->zend_iterator->funcs->valid(iterator->zend_iterator)) {
			current = iterator->zend_iterator->funcs->get_current_data(iterator->zend_iterator);
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
			if (Z_TYPE_P(current) == IS_UNDEF) {
				if (iterator->zend_iterator == NULL) {
                    zend_hash_move_forward(Z_ARR(iterator->array));
                } else {
                    iterator->zend_iterator->funcs->move_forward(iterator->zend_iterator);
                }

				continue;
			}
		}

		/* Ensure the value is a reference. Otherwise, the location of the value may be freed. */
		ZVAL_MAKE_REF(current);

		/* Retrieve key */
		if (iterator->target_hash != NULL) {
			zend_hash_get_current_key_zval_ex(iterator->target_hash, &key, &iterator->position);
        } else {
            iterator->zend_iterator->funcs->get_current_key(iterator->zend_iterator, &key);
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
            iterator->zend_iterator->funcs->move_forward(iterator->zend_iterator);
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

		zval_ptr_dtor(&fci.params[0]);

		if (Z_TYPE(fci.params[1]) != IS_UNDEF) {
			zval_ptr_dtor(&fci.params[1]);
			ZVAL_UNDEF(&fci.params[1]);
		}

		if (UNEXPECTED(result == FAILURE || EG(exception) != NULL)) {
			iterator->state = ASYNC_ITERATOR_FINISHED;
			iterator->microtask.is_cancelled = true;
            break;
        }
	}
}

void async_run_iterator(async_iterator_t *iterator)
{
	if (UNEXPECTED(ZEND_ASYNC_IS_SCHEDULER_CONTEXT)) {
		async_throw_error("The iterator cannot be run in the scheduler context");
		return;
	}

	ZEND_ASYNC_ADD_MICROTASK(&iterator->microtask);

	iterate(iterator);

	if (iterator->microtask.ref_count > 0) {
		iterator->microtask.ref_count--;
	}

	if (iterator->microtask.ref_count == 0) {
		iterator->microtask.dtor(&iterator->microtask);
	}
}

static void coroutine_entry(void)
{
	if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL || ZEND_ASYNC_CURRENT_COROUTINE->extended_data == NULL)) {
		async_throw_error("Invalid coroutine context for concurrent iterator");
		return;
	}

	async_iterator_t *iterator = (async_iterator_t *) ZEND_ASYNC_CURRENT_COROUTINE->extended_data;

	async_run_iterator(iterator);

	if (iterator->active_coroutines > 1) {
		iterator->active_coroutines--;
	} else {
		iterator->active_coroutines = 0;
		iterator->state = ASYNC_ITERATOR_FINISHED;
	}
}