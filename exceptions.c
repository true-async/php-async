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
#include "php_async.h"
#include "exceptions.h"

#include <zend_API.h>
#include <zend_exceptions.h>
#include "php.h"

#include "exceptions_arginfo.h"
#include "zend_common.h"

zend_class_entry *async_ce_async_exception = NULL;
zend_class_entry *async_ce_cancellation_exception = NULL;
zend_class_entry *async_ce_input_output_exception = NULL;
zend_class_entry *async_ce_timeout_exception = NULL;
zend_class_entry *async_ce_poll_exception = NULL;
zend_class_entry *async_ce_dns_exception = NULL;
zend_class_entry *async_ce_deadlock_error = NULL;
zend_class_entry *async_ce_composite_exception = NULL;

PHP_METHOD(Async_CompositeException, addException)
{
	zval *exception;

	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_OBJECT_OF_CLASS(exception, zend_ce_throwable)
	ZEND_PARSE_PARAMETERS_END();

	zval *object = ZEND_THIS;
	async_composite_exception_add_exception(Z_OBJ_P(object), Z_OBJ_P(exception), true);
}

PHP_METHOD(Async_CompositeException, getExceptions)
{
	ZEND_PARSE_PARAMETERS_NONE();

	zval *object = ZEND_THIS;
	zval *exceptions_prop = zend_read_property(
			async_ce_composite_exception, Z_OBJ_P(object), "exceptions", sizeof("exceptions") - 1, 0, NULL);

	if (Z_TYPE_P(exceptions_prop) == IS_ARRAY) {
		RETURN_ZVAL(exceptions_prop, 1, 0);
	} else {
		array_init(return_value);
	}
}

void async_register_exceptions_ce(void)
{
	async_ce_async_exception = register_class_Async_AsyncException(zend_ce_exception);
	async_ce_cancellation_exception = register_class_Async_CancellationError(zend_ce_error);
	async_ce_input_output_exception = register_class_Async_InputOutputException(zend_ce_exception);
	async_ce_timeout_exception = register_class_Async_TimeoutException(zend_ce_exception);
	async_ce_poll_exception = register_class_Async_PollException(zend_ce_exception);
	async_ce_dns_exception = register_class_Async_DnsException(zend_ce_exception);
	async_ce_deadlock_error = register_class_Async_DeadlockError(zend_ce_error);
	async_ce_composite_exception = register_class_Async_CompositeException(zend_ce_exception);
}

PHP_ASYNC_API zend_object *async_new_exception(zend_class_entry *exception_ce, const char *format, ...)
{
	zval exception, message_val;

	if (!exception_ce) {
		exception_ce = zend_ce_exception;
	}

	ZEND_ASSERT(instanceof_function(exception_ce, zend_ce_throwable) && "Exceptions must implement Throwable");

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

PHP_ASYNC_API ZEND_COLD zend_object *async_throw_error(const char *format, ...)
{
	va_list args;
	va_start(args, format);
	zend_string *message = zend_vstrpprintf(0, format, args);
	va_end(args);

	zend_object *obj = NULL;

	if (EXPECTED(EG(current_execute_data))) {
		obj = zend_throw_exception(async_ce_async_exception, ZSTR_VAL(message), 0);
	} else {
		obj = async_new_exception(async_ce_async_exception, ZSTR_VAL(message));
		async_apply_exception_to_context(obj);
	}

	zend_string_release(message);
	return obj;
}

PHP_ASYNC_API ZEND_COLD zend_object *async_throw_cancellation(const char *format, ...)
{
	const zend_object *previous_exception = EG(exception);

	if (format == NULL && previous_exception != NULL &&
		instanceof_function(previous_exception->ce, async_ce_cancellation_exception)) {
		format = "The operation was canceled by timeout";
	} else {
		format = format ? format : "The operation was canceled";
	}

	va_list args;
	va_start(args, format);

	zend_object *obj = NULL;

	if (EXPECTED(EG(current_execute_data))) {
		obj = zend_throw_exception_ex(async_ce_cancellation_exception, 0, format, args);
	} else {
		obj = async_new_exception(async_ce_cancellation_exception, format, args);
		async_apply_exception_to_context(obj);
	}

	va_end(args);
	return obj;
}

PHP_ASYNC_API ZEND_COLD zend_object *async_throw_input_output(const char *format, ...)
{
	format = format ? format : "An input/output error occurred.";

	va_list args;
	va_start(args, format);

	zend_object *obj = NULL;

	if (EXPECTED(EG(current_execute_data))) {
		obj = zend_throw_exception_ex(async_ce_input_output_exception, 0, format, args);
	} else {
		obj = async_new_exception(async_ce_input_output_exception, format, args);
		async_apply_exception_to_context(obj);
	}

	va_end(args);
	return obj;
}

PHP_ASYNC_API ZEND_COLD zend_object *async_throw_timeout(const char *format, const zend_long timeout)
{
	format = format ? format : "A timeout of %u microseconds occurred";

	if (EXPECTED(EG(current_execute_data))) {
		return zend_throw_exception_ex(async_ce_timeout_exception, 0, format, timeout);
	} else {
		zend_object *obj = async_new_exception(async_ce_timeout_exception, format, timeout);
		async_apply_exception_to_context(obj);
		return obj;
	}
}

PHP_ASYNC_API ZEND_COLD zend_object *async_throw_poll(const char *format, ...)
{
	va_list args;
	va_start(args, format);

	zend_object *obj = NULL;

	if (EXPECTED(EG(current_execute_data))) {
		obj = zend_throw_exception_ex(async_ce_poll_exception, 0, format, args);
	} else {
		obj = async_new_exception(async_ce_poll_exception, format, args);
		async_apply_exception_to_context(obj);
	}

	va_end(args);
	return obj;
}

PHP_ASYNC_API ZEND_COLD zend_object *async_throw_deadlock(const char *format, ...)
{
	format = format ? format : "A deadlock was detected";

	va_list args;
	va_start(args, format);

	zend_object *obj = NULL;

	if (EXPECTED(EG(current_execute_data))) {
		obj = zend_throw_exception_ex(async_ce_deadlock_error, 0, format, args);
	} else {
		obj = async_new_exception(async_ce_deadlock_error, format, args);
		async_apply_exception_to_context(obj);
	}

	va_end(args);
	return obj;
}

PHP_ASYNC_API ZEND_COLD zend_object *async_new_composite_exception(void)
{
	zval composite;
	object_init_ex(&composite, async_ce_composite_exception);
	return Z_OBJ(composite);
}

PHP_ASYNC_API void
async_composite_exception_add_exception(zend_object *composite, zend_object *exception, bool transfer)
{
	if (composite == NULL || exception == NULL) {
		return;
	}

	zval *exceptions_prop = &composite->properties_table[7];

	if (Z_TYPE_P(exceptions_prop) == IS_UNDEF) {
		array_init(exceptions_prop);
	}

	zval exception_zval;
	ZVAL_OBJ(&exception_zval, exception);

	if (UNEXPECTED(zend_hash_next_index_insert(Z_ARRVAL_P(exceptions_prop), &exception_zval) == NULL)) {
		zend_error(E_CORE_WARNING, "Failed to add exception to composite exception");
		if (transfer) {
			OBJ_RELEASE(exception);
		}
	} else if (false == transfer) {
		GC_ADDREF(exception);
	}
}

static void exception_coroutine_dispose(zend_coroutine_t *coroutine)
{
	if (coroutine->extended_data != NULL) {
		zend_object *exception_obj = coroutine->extended_data;
		coroutine->extended_data = NULL;
		async_rethrow_exception(exception_obj);
	}
}

static void exception_coroutine_entry(void)
{
	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL || coroutine->extended_data == NULL)) {
		return;
	}

	zend_object *exception = coroutine->extended_data;
	coroutine->extended_data = NULL;

	async_rethrow_exception(exception);
}

bool async_spawn_and_throw(zend_object *exception, zend_async_scope_t *scope, int32_t priority)
{
	if (scope == NULL) {
		scope = ZEND_ASYNC_CURRENT_SCOPE;
	}

	// If the current Scope can no longer create coroutines, we use a trick.
	// We create a child Scope with a single coroutine.
	if (ZEND_ASYNC_SCOPE_IS_CANCELLED(scope) || ZEND_ASYNC_SCOPE_IS_CLOSED(scope)) {
		scope = ZEND_ASYNC_NEW_SCOPE(scope);
		if (UNEXPECTED(scope == NULL)) {
			async_warning("Failed to create a new Scope for throwing an exception in a coroutine.");
			return false;
		}
	}

	zend_coroutine_t *coroutine = ZEND_ASYNC_SPAWN_WITH_SCOPE_EX(scope, priority);
	if (UNEXPECTED(coroutine == NULL)) {
		async_warning("Failed to spawn a coroutine for throwing an exception.");
		return false;
	}

	coroutine->internal_entry = exception_coroutine_entry;
	coroutine->extended_data = exception;
	coroutine->extended_dispose = exception_coroutine_dispose;
	GC_ADDREF(exception);

	return true;
}

/**
 * Extracts the current exception from the global state, saves it, and clears it.
 *
 * @return The extracted exception object with an increased reference count.
 */
zend_object *async_extract_exception(void)
{
	zend_object *exception = EG(exception);
	GC_ADDREF(exception);
	zend_clear_exception();

	return exception;
}

/**
 * Applies the current exception to the provided exception pointer.
 *
 * If the current exception is not a cancellation exception or a graceful/unwind exit,
 * it extracts the current exception and sets it as the new exception.
 * If `to_exception` is not NULL, it sets the previous exception to the extracted one.
 *
 * @param to_exception Pointer to a pointer where the new exception will be set.
 */
void async_apply_exception(zend_object **to_exception)
{
	if (UNEXPECTED(
				EG(exception) &&
				false ==
						(instanceof_function(EG(exception)->ce, ZEND_ASYNC_GET_CE(ZEND_ASYNC_EXCEPTION_CANCELLATION)) ||
						 zend_is_graceful_exit(EG(exception)) || zend_is_unwind_exit(EG(exception))))) {

		zend_object *exception = async_extract_exception();

		if (*to_exception != NULL) {
			zend_exception_set_previous(exception, *to_exception);
		}

		*to_exception = exception;
	}
}

PHP_ASYNC_API void async_rethrow_exception(zend_object *exception)
{
	if (EG(current_execute_data)) {
		zend_throw_exception_internal(exception);
	} else {
		async_apply_exception_to_context(exception);
	}
}

void async_apply_exception_to_context(zend_object *exception)
{
	if (UNEXPECTED(exception == NULL)) {
		return;
	}

	zend_object *previous = EG(exception);

	if (previous && zend_is_unwind_exit(previous)) {
		/* Don't replace unwinding exception with different exception. */
		OBJ_RELEASE(exception);
		return;
	}

	zend_exception_set_previous(exception, EG(exception));

	EG(exception) = exception;

	if (previous) {
		return;
	}
}