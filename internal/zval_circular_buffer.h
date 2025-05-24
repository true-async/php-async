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
#ifndef ZVAL_CIRCULAR_BUFFER_H
#define ZVAL_CIRCULAR_BUFFER_H

#include "circular_buffer.h"
#include "zend_exceptions.h"

zend_always_inline static void zval_c_buffer_new(size_t count, const allocator_t *allocator)
{
	if(circular_buffer_new(count, sizeof(zval), allocator) == NULL) {
		zend_throw_error(NULL, "Failed to allocate memory for zval circular buffer");
	}
}

zend_always_inline static void zval_c_buffer_push_with_resize(circular_buffer_t *buffer, zval *value)
{
	if(circular_buffer_push(buffer, value, true) == FAILURE) {
		zend_throw_error(NULL, "Failed to push zval into circular buffer");
	} else {
		Z_TRY_ADDREF_P(value);
	}
}

zend_always_inline static void zval_c_buffer_push(circular_buffer_t *buffer, zval *value)
{
    if(circular_buffer_is_full(buffer)) {
    	zend_throw_error(NULL, "Failed to push zval into circular buffer: buffer is full");
		return;
	}

	if(circular_buffer_push(buffer, value, false) == FAILURE) {
		zend_throw_error(NULL, "Failed to push zval into circular buffer");
	} else {
		Z_TRY_ADDREF_P(value);
	}
}

zend_always_inline static void zval_c_buffer_pop(circular_buffer_t *buffer, zval *value)
{
	ZVAL_UNDEF(value);
	circular_buffer_pop(buffer, value);
}

zend_always_inline static void zval_c_buffer_cleanup(circular_buffer_t *buffer)
{
	while (false == circular_buffer_is_empty(buffer)) {
		zval item;
		zval_c_buffer_pop(buffer, &item);
		zval_ptr_dtor(&item);
	}
}

#endif //ZVAL_CIRCULAR_BUFFER_H