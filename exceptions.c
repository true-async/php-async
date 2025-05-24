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
#include "exceptions.h"

#include <zend_API.h>
#include <zend_exceptions.h>

#include "exceptions_arginfo.h"

zend_class_entry * async_ce_async_exception = NULL;
zend_class_entry * async_ce_cancellation_exception = NULL;
zend_class_entry * async_ce_input_output_exception = NULL;
zend_class_entry * async_ce_timeout_exception = NULL;
zend_class_entry * async_ce_poll_exception = NULL;
zend_class_entry * async_ce_dns_exception = NULL;

void async_register_exceptions_ce(void)
{
	async_ce_async_exception = register_class_Async_AsyncException(zend_ce_exception);
	async_ce_cancellation_exception = register_class_Async_CancellationException(zend_ce_cancellation_exception);
	async_ce_input_output_exception = register_class_Async_InputOutputException(zend_ce_exception);
	async_ce_timeout_exception = register_class_Async_TimeoutException(zend_ce_exception);
	async_ce_poll_exception = register_class_Async_PollException(zend_ce_exception);
	async_ce_dns_exception = register_class_Async_DnsException(zend_ce_exception);
}

zend_object * async_new_exception(zend_class_entry *exception_ce, const char *format, ...)
{
	zval exception, message_val;

	if (!exception_ce) {
		exception_ce = zend_ce_exception;
	}

	ZEND_ASSERT(instanceof_function(exception_ce, zend_ce_throwable)
		&& "Exceptions must implement Throwable");

	object_init_ex(&exception, exception_ce);

	va_list args;
	va_start(args, format);
	zend_string *message = zend_vstrpprintf(0, format, args);
	va_end(args);

	if (message) {
		ZVAL_STR(&message_val, message);
		zend_update_property_ex(exception_ce, Z_OBJ(exception), ZSTR_KNOWN(ZEND_STR_MESSAGE), &message_val);
	}

	zend_string_release(message);

	return Z_OBJ(exception);
}

ZEND_API ZEND_COLD zend_object * async_throw_error(const char *format, ...)
{
	va_list args;
	va_start(args, format);
	zend_string *message = zend_vstrpprintf(0, format, args);
	va_end(args);

	zend_object *obj = zend_throw_exception(async_ce_async_exception, ZSTR_VAL(message), 0);
	zend_string_release(message);
	return obj;
}

ZEND_API ZEND_COLD zend_object * async_throw_cancellation(const char *format, ...)
{
	const zend_object *previous_exception = EG(exception);

	if (format == NULL
		&& previous_exception != NULL
		&& instanceof_function(previous_exception->ce, async_ce_cancellation_exception)) {
		format = "The operation was canceled by timeout";
	} else {
		format = format ? format : "The operation was canceled";
	}

	va_list args;
	va_start(args, format);

	zend_object *obj = zend_throw_exception_ex(async_ce_cancellation_exception, 0, format, args);

	va_end(args);
	return obj;
}

ZEND_API ZEND_COLD zend_object * async_throw_input_output(const char *format, ...)
{
	format = format ? format : "An input/output error occurred.";

	va_list args;
	va_start(args, format);

	zend_object *obj = zend_throw_exception_ex(async_ce_input_output_exception, 0, format, args);

	va_end(args);
	return obj;
}

ZEND_API ZEND_COLD zend_object * async_throw_timeout(const char *format, const zend_long timeout)
{
	format = format ? format : "A timeout of %u microseconds occurred";

	return zend_throw_exception_ex(async_ce_timeout_exception, 0, format, timeout);
}

ZEND_API ZEND_COLD zend_object * async_throw_poll(const char *format, ...)
{
	va_list args;
	va_start(args, format);

	zend_object *obj = zend_throw_exception_ex(async_ce_poll_exception, 0, format, args);

	va_end(args);
	return obj;
}