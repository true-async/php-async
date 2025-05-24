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
#ifndef ASYNC_CIRCULAR_BUFFER_H
#define ASYNC_CIRCULAR_BUFFER_H

#include <zend_types.h>
#include "allocator.h"

typedef struct _circular_buffer_s circular_buffer_t;

struct _circular_buffer_s {
    size_t item_size;
    size_t min_size;
	/**
	 * Decrease threshold.
	 *
	 * The number of elements to decrease the buffer size.
	 *
	 * This value is recalculated each time the buffer is resized or its size is increased.
	 * It is usually calculated using the formula: current_size / 2.5,
	 * meaning the buffer will be reduced when its current size is equal to or
	 * less than 2.5 times its current capacity.
	 * This approach prevents frequent buffer reductions without explicit necessity.
	 */
	size_t decrease_t;
	/* allocator handlers */
	const allocator_t *allocator;
	/* point to the first element */
	void *start;
	/* point to the last valid element */
	void *end;
	/**
	 * The point to the next element to be written.
	 * Equal NULL means the buffer is empty.
	 */
	void *head;
	/**
	 * The point to the next element to be read.
	 * Equal NULL means the buffer is empty.
	 */
	void *tail;
};

zend_result circular_buffer_ctor(circular_buffer_t * buffer, size_t count, const size_t item_size, const allocator_t *allocator);
void circular_buffer_dtor(circular_buffer_t *buffer);
circular_buffer_t *circular_buffer_new(const size_t count, const size_t item_size, const allocator_t *allocator);
void circular_buffer_destroy(circular_buffer_t *buffer);

bool circular_buffer_is_full(const circular_buffer_t *buffer);
bool circular_buffer_is_empty(const circular_buffer_t *buffer);
bool circular_buffer_is_not_empty(const circular_buffer_t *buffer);
zend_result circular_buffer_push(circular_buffer_t *buffer, const void *value, bool should_resize);
zend_result circular_buffer_pop(circular_buffer_t *buffer, void *value);
size_t circular_buffer_count(const circular_buffer_t *buffer);
size_t circular_buffer_capacity(const circular_buffer_t *buffer);
zend_result circular_buffer_realloc(circular_buffer_t *buffer, size_t new_count);

circular_buffer_t *zval_circular_buffer_new(const size_t count, const allocator_t *allocator);
zend_result zval_circular_buffer_push(circular_buffer_t *buffer, zval *value, bool should_resize);
zend_result zval_circular_buffer_pop(circular_buffer_t *buffer, zval *value);

#endif //ASYNC_CIRCULAR_BUFFER_H
