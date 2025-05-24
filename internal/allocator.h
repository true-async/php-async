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
#ifndef ASYNC_ALLOCATOR_H
#define ASYNC_ALLOCATOR_H

#include <stddef.h>

typedef struct _allocator_s allocator_t;

struct _allocator_s {
	void *(*m_alloc)(size_t size);
	void *(*m_calloc)(size_t num, size_t size);
	void *(*m_realloc)(void *ptr, size_t size);
	void (*m_free)(void *ptr);
};

extern allocator_t zend_std_allocator;
extern allocator_t zend_std_persistent_allocator;

#endif //ASYNC_ALLOCATOR_H
