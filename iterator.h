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
#ifndef ITERATOR_H
#define ITERATOR_H

#include <Zend/zend_async_API.h>

typedef struct _async_iterator_t async_iterator_t;

typedef void (*async_iterator_spawn_next_t)(async_iterator_t *iterator);
typedef void (*async_iterator_dtor_t)(async_iterator_t *iterator);
typedef zend_result (*async_iterator_handler_t)(async_iterator_t *iterator, zval *current, zval *key);

typedef enum
{
	ASYNC_ITERATOR_INIT = 0,
	ASYNC_ITERATOR_STARTED = 1,
	ASYNC_ITERATOR_FINISHED = 2,
} async_iterator_state_t;

async_iterator_t * async_iterator_new(
	zval *array,
	zend_object_iterator *zend_iterator,
	zend_fcall_t *fcall,
	async_iterator_handler_t handler,
	unsigned int concurrency,
	int32_t priority,
	size_t iterator_size
);

#define ASYNC_ITERATOR_DTOR zend_async_microtask_handler_t

void async_iterator_run(async_iterator_t *iterator);
void async_iterator_run_in_coroutine(async_iterator_t *iterator, int32_t priority);

struct _async_iterator_t {
	zend_async_microtask_t microtask;
	void (*run)(zend_async_iterator_t *iterator);
	void (*run_in_coroutine)(zend_async_iterator_t *iterator, int32_t priority);
	/* The current state of the iterator. See async_iterator_state_t */
	async_iterator_state_t state;
	/* The maximum number of concurrent tasks that can be executed at the same time */
	unsigned int concurrency;
	/* The number of active coroutines that are currently executing */
	unsigned int active_coroutines;
	/* Priority for coroutines created by this iterator */
	int32_t priority;
	/* The coroutine scope */
	zend_async_scope_t *scope;
	/* The internal handler */
	async_iterator_handler_t handler;
	/* Callback and info / cache to be used when coroutine is started. */
	zend_fcall_t *fcall;
	/* An array. */
	zval array;
	HashTable *target_hash;
	HashPosition position;
	uint32_t hash_iterator;
	/* The iterator object, which may be NULL if there is no iterator. */
	zend_object_iterator *zend_iterator;
	/* NULLABLE. Custom data for the iterator, can be used to store additional information. */
	void *extended_data;
	/* NULLABLE. An additional destructor that will be called. */
	ASYNC_ITERATOR_DTOR extended_dtor;
};

#endif //ITERATOR_H
