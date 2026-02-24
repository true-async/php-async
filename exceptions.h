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
#ifndef ASYNC_EXCEPTIONS_H
#define ASYNC_EXCEPTIONS_H

#include <zend_portability.h>
#include <zend_property_hooks.h>
#include <Zend/zend_async_API.h>
#include "php_async_api.h"

BEGIN_EXTERN_C()

PHP_ASYNC_API extern zend_class_entry *async_ce_async_exception;
PHP_ASYNC_API extern zend_class_entry *async_ce_cancellation_exception;
PHP_ASYNC_API extern zend_class_entry *async_ce_operation_cancelled_exception;
PHP_ASYNC_API extern zend_class_entry *async_ce_input_output_exception;
PHP_ASYNC_API extern zend_class_entry *async_ce_timeout_exception;
PHP_ASYNC_API extern zend_class_entry *async_ce_poll_exception;
PHP_ASYNC_API extern zend_class_entry *async_ce_dns_exception;
PHP_ASYNC_API extern zend_class_entry *async_ce_deadlock_error;
PHP_ASYNC_API extern zend_class_entry *async_ce_composite_exception;
PHP_ASYNC_API extern zend_class_entry *async_ce_service_unavailable_exception;

void async_register_exceptions_ce(void);
PHP_ASYNC_API ZEND_COLD zend_object *async_new_exception(zend_class_entry *exception_ce, const char *format, ...);
PHP_ASYNC_API ZEND_COLD zend_object *async_throw_error(const char *format, ...);
PHP_ASYNC_API ZEND_COLD zend_object *async_throw_cancellation(const char *format, ...);
PHP_ASYNC_API ZEND_COLD zend_object *async_throw_input_output(const char *format, ...);
PHP_ASYNC_API ZEND_COLD zend_object *async_throw_timeout(const char *format, const zend_long timeout);
PHP_ASYNC_API ZEND_COLD zend_object *async_throw_poll(const char *format, ...);
PHP_ASYNC_API ZEND_COLD zend_object *async_throw_deadlock(const char *format, ...);
PHP_ASYNC_API ZEND_COLD zend_object *async_throw_service_unavailable(const char *format, ...);
PHP_ASYNC_API ZEND_COLD zend_object *async_new_composite_exception(void);
PHP_ASYNC_API void
async_composite_exception_add_exception(zend_object *composite, zend_object *exception, bool transfer);
bool async_spawn_and_throw(zend_object *exception, zend_async_scope_t *scope, int32_t priority);
void async_apply_exception_to_context(zend_object *exception);
zend_object *async_extract_exception(void);
PHP_ASYNC_API void async_rethrow_exception(zend_object *exception);
void async_apply_exception(zend_object **to_exception);

END_EXTERN_C()

#endif // ASYNC_EXCEPTIONS_H
