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
  | Author: TrueAsync API Implementation                                 |
  +----------------------------------------------------------------------+
*/

#ifndef ASYNC_INTERNAL_CONTEXT_H
#define ASYNC_INTERNAL_CONTEXT_H

#include "php_async.h"
#include "zend_async_API.h"

BEGIN_EXTERN_C()

// Key management functions
uint32_t async_internal_context_key_alloc(const char *key_name);
const char* async_internal_context_key_name(uint32_t key);

// Internal context functions
bool async_internal_context_get(zend_coroutine_t *coroutine, uint32_t key, zval *result);
void async_internal_context_set(zend_coroutine_t *coroutine, uint32_t key, zval *value);
bool async_internal_context_unset(zend_coroutine_t *coroutine, uint32_t key);

// Cleanup functions
void async_coroutine_dispose_internal_context(zend_coroutine_t *coroutine);
void async_shutdown_internal_context_api(void);

// Initialize Internal Context for new coroutines
void async_coroutine_init_internal_context(zend_coroutine_t *coroutine);

END_EXTERN_C()

#endif /* ASYNC_INTERNAL_CONTEXT_H */