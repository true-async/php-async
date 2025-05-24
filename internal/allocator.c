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
#include "allocator.h"

#include <zend_alloc.h>

void *zend_std_alloc(size_t size)
{
#ifdef ASYNC_UNIT_TESTS
	return malloc(size);
#else
	return emalloc(size);
#endif
}

void *zend_std_calloc(const size_t num, const size_t size)
{
#ifdef ASYNC_UNIT_TESTS
	return calloc(num, size);
#else
	return ecalloc(num, size);
#endif
}

void *zend_std_realloc(void *ptr, const size_t size)
{
#ifdef ASYNC_UNIT_TESTS
	return realloc(ptr, size);
#else
	return erealloc(ptr, size);
#endif
}

void zend_std_free(void *ptr)
{
#ifdef ASYNC_UNIT_TESTS
	free(ptr);
#else
	efree(ptr);
#endif
}

allocator_t zend_std_allocator = {
	zend_std_alloc,
	zend_std_calloc,
	zend_std_realloc,
	zend_std_free
};

void *zend_std_palloc(const size_t size)
{
#ifdef ASYNC_UNIT_TESTS
	return malloc(size);
#else
	return pemalloc(size, 1);
#endif
}

void *zend_std_pcalloc(const size_t num, const size_t size)
{
#ifdef ASYNC_UNIT_TESTS
	return calloc(num, size);
#else
	return pecalloc(num, size, 1);
#endif
}

void *zend_std_prealloc(void *ptr, const size_t size)
{
#ifdef ASYNC_UNIT_TESTS
	return realloc(ptr, size);
#else
	return perealloc(ptr, size, 1);
#endif
}

void zend_std_pfree(void *ptr)
{
#ifdef ASYNC_UNIT_TESTS
	free(ptr);
#else
	pefree(ptr, 1);
#endif
}

allocator_t zend_std_persistent_allocator = {
	zend_std_palloc,
	zend_std_pcalloc,
	zend_std_prealloc,
	zend_std_pfree
};