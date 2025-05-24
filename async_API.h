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
#ifndef ASYNC_API_H
#define ASYNC_API_H

#include <stdbool.h>

#include "iterator.h"
#include <Zend/zend_async_API.h>

typedef struct _async_await_context_t async_await_context_t;
typedef void (*async_await_context_dtor_t) (async_await_context_t *context);

struct _async_await_context_t
{
	unsigned int ref_count;
	unsigned int total;
	unsigned int waiting_count;
	unsigned int resolved_count;
	bool ignore_errors;
	unsigned int concurrency;
	async_await_context_dtor_t dtor;
	HashTable *futures;
	// Scope for the new coroutines
	zend_async_scope_t * scope;
	HashTable *results;
	HashTable *errors;
	bool fill_missing_with_null;
};

typedef struct
{
	zend_coroutine_event_callback_t callback;
	async_await_context_t *await_context;
	// The key index for the result
	zval key;
} async_await_callback_t;

typedef struct
{
	zend_object_iterator * zend_iterator;
	HashTable * futures;
	zend_coroutine_t * iterator_coroutine;
	zend_coroutine_t * waiting_coroutine;
	async_await_context_t * await_context;
} async_await_iterator_t;

typedef struct
{
	async_iterator_t iterator;
	async_await_iterator_t * await_iterator;
} async_await_iterator_iterator_t;

void async_api_register(void);

void async_await_futures(
	zval *iterable,
	int count,
	bool ignore_errors,
	zend_async_event_t *cancellation,
	zend_ulong timeout,
	unsigned int concurrency,
	HashTable *results,
	HashTable *errors,
	bool fill_missing_with_null
);

#endif //ASYNC_API_H
