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

#ifdef HAVE_CONFIG_H
#include <config.h>
#endif

#include "php_async.h"
#include "ext/standard/info.h"
#include "scheduler.h"
#include "exceptions.h"
#include "scope.h"
#include "async_API.h"
#include "async_arginfo.h"
#include "zend_interfaces.h"
#ifdef PHP_ASYNC_LIBUV
#include "libuv_reactor.h"
#endif

zend_class_entry * async_ce_awaitable = NULL;
zend_class_entry * async_ce_timeout = NULL;

///////////////////////////////////////////////////////////////
/// Module functions
///////////////////////////////////////////////////////////////

static zend_object *async_timeout_create(zend_ulong ms, bool is_periodic);

#define THROW_IF_SCHEDULER_CONTEXT if (UNEXPECTED(ZEND_ASYNC_IS_SCHEDULER_CONTEXT)) {				\
		async_throw_error("The operation cannot be executed in the scheduler context");		\
		RETURN_THROWS();																	\
	}

#define THROW_IF_ASYNC_OFF if (UNEXPECTED(ZEND_ASYNC_OFF)) {								\
		async_throw_error("The operation cannot be executed while async is off");			\
		RETURN_THROWS();																	\
	}

PHP_FUNCTION(Async_spawn)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	zval *args = NULL;
	int args_count = 0;
	HashTable *named_args = NULL;

	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(1, -1)
		Z_PARAM_FUNC(fci, fcc);
		Z_PARAM_VARIADIC_WITH_NAMED(args, args_count, named_args);
	ZEND_PARSE_PARAMETERS_END();

	async_coroutine_t * coroutine = (async_coroutine_t *) ZEND_ASYNC_SPAWN();

	if (UNEXPECTED(EG(exception))) {
		return;
	}

	zend_fcall_t * fcall = ecalloc(1, sizeof(zend_fcall_t));
	fcall->fci = fci;
	fcall->fci_cache = fcc;

	if (args_count) {
		fcall->fci.param_count = args_count;
		fcall->fci.params = safe_emalloc(args_count, sizeof(zval), 0);

		for (uint32_t i = 0; i < args_count; i++) {
			ZVAL_COPY(&fcall->fci.params[i], &args[i]);
		}
	}

	if (named_args) {
		fcall->fci.named_params = named_args;
		GC_ADDREF(named_args);
	}

	coroutine->coroutine.fcall = fcall;

	// Keep a reference to closures or callable objects while the coroutine is running.
	Z_TRY_ADDREF(fcall->fci.function_name);

	RETURN_OBJ_COPY(&coroutine->std);
}

PHP_FUNCTION(Async_spawnWith)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	zval *args = NULL;
	int args_count = 0;
	HashTable *named_args = NULL;

	zend_async_scope_t * scope = NULL;
	zend_object * scope_provider = NULL;
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(2, -1)
		Z_PARAM_OBJ_OF_CLASS(scope_provider, async_ce_scope_provider)
		Z_PARAM_FUNC(fci, fcc);
		Z_PARAM_VARIADIC_WITH_NAMED(args, args_count, named_args);
	ZEND_PARSE_PARAMETERS_END();

	// If scope_provider is an instance of async_ce_scope
	if (instanceof_function(scope_provider->ce, async_ce_scope)) {
		scope = &((async_scope_object_t *)scope_provider)->scope->scope;
	}

	async_coroutine_t * coroutine = (async_coroutine_t *)(scope_provider ? ZEND_ASYNC_SPAWN_WITH_PROVIDER(scope_provider)
									: ZEND_ASYNC_SPAWN_WITH(scope));

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	zend_fcall_t * fcall = ecalloc(1, sizeof(zend_fcall_t));
	fcall->fci = fci;
	fcall->fci_cache = fcc;

	if (args_count) {
		fcall->fci.param_count = args_count;
		fcall->fci.params = safe_emalloc(args_count, sizeof(zval), 0);

		for (uint32_t i = 0; i < args_count; i++) {
			ZVAL_COPY(&fcall->fci.params[i], &args[i]);
		}
	}

	if (named_args) {
		fcall->fci.named_params = named_args;
		GC_ADDREF(named_args);
	}

	coroutine->coroutine.fcall = fcall;

	RETURN_OBJ(&coroutine->std);
}

PHP_FUNCTION(Async_suspend)
{
	if (UNEXPECTED(ZEND_ASYNC_OFF)) {
		return;
	}

	THROW_IF_SCHEDULER_CONTEXT;
	ZEND_ASYNC_SUSPEND();
}

PHP_FUNCTION(Async_protect)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;
}

PHP_FUNCTION(Async_await)
{
	zend_object * awaitable = NULL;
	zend_object * cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_OBJ_OF_CLASS(awaitable, async_ce_awaitable);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		return;
	}

	zend_async_waker_new(coroutine);

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	zend_async_resume_when(
		coroutine,
		ZEND_ASYNC_OBJECT_TO_EVENT(awaitable),
		false,
		zend_async_waker_callback_resolve,
		NULL
	);

	if (cancellation != NULL) {
		zend_async_resume_when(
			coroutine,
			ZEND_ASYNC_OBJECT_TO_EVENT(cancellation),
			false,
			zend_async_waker_callback_cancel,
			NULL
		);
	}

	ZEND_ASYNC_SUSPEND();

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	if (Z_TYPE(coroutine->waker->result) == IS_UNDEF) {
		ZVAL_NULL(return_value);
	} else {
		ZVAL_COPY(return_value, &coroutine->waker->result);
	}

	coroutine->waker->dtor(coroutine);
}

PHP_FUNCTION(Async_awaitAny)
{
	zval * futures;
	zend_object * cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_ZVAL(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	HashTable * results = zend_new_array(8);

	async_await_futures(futures,
		1,
		false,
		ZEND_ASYNC_OBJECT_TO_EVENT(cancellation),
		0,
		0,
		results,
		NULL,
		false
	);

	if (EG(exception)) {
		zend_array_release(results);
		RETURN_THROWS();
	}

	if (zend_hash_num_elements(results) == 0) {
		zend_array_release(results);
		RETURN_NULL();
	}

	zval result;
	ZVAL_COPY(&result, zend_hash_index_find(results, 0));
	zend_array_release(results);

	RETURN_ZVAL(&result, 0, 0);
}

PHP_FUNCTION(Async_awaitFirstSuccess)
{
	zval * futures;
	zend_object * cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_ZVAL(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	HashTable * results = zend_new_array(8);
	HashTable * errors = zend_new_array(8);

	async_await_futures(futures,
		1,
		true,
		ZEND_ASYNC_OBJECT_TO_EVENT(cancellation),
		0,
		0,
		results,
		errors,
		false
	);

	if (EG(exception)) {
		zend_array_release(results);
		zend_array_release(errors);
		RETURN_THROWS();
	}

	HashTable * return_array = zend_new_array(2);

	zval val;

	if (zend_hash_num_elements(results) == 0) {
		ZVAL_NULL(&val);
	} else {
		ZVAL_COPY(&val, zend_hash_index_find(results, 0));
	}

	zend_hash_next_index_insert_new(return_array, &val);

	ZVAL_ARR(&val, errors);
	zend_hash_next_index_insert_new(return_array, &val);

	RETURN_ARR(return_array);
}

PHP_FUNCTION(Async_awaitAll)
{
	zval * futures;
	zend_object * cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_ZVAL(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	HashTable * results = zend_new_array(8);

	async_await_futures(futures,
		0,
		false,
		ZEND_ASYNC_OBJECT_TO_EVENT(cancellation),
		0,
		0,
		results,
		NULL,
		false
		);

	if (EG(exception)) {
		zend_array_release(results);
		RETURN_THROWS();
	}

	RETURN_ARR(results);
}

PHP_FUNCTION(Async_awaitAllWithErrors)
{
	zval * futures;
	zend_object * cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_ZVAL(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	HashTable * results = zend_new_array(8);
	HashTable * errors = zend_new_array(8);

	async_await_futures(futures,
		0,
		true,
		ZEND_ASYNC_OBJECT_TO_EVENT(cancellation),
		0,
		0,
		results,
		errors,
		false
		);

	if (EG(exception)) {
		zend_array_release(results);
		zend_array_release(errors);
		RETURN_THROWS();
	}

	HashTable * return_array = zend_new_array(2);

	zval val;
	ZVAL_ARR(&val, results);
	zend_hash_next_index_insert_new(return_array, &val);

	ZVAL_ARR(&val, errors);
	zend_hash_next_index_insert_new(return_array, &val);

	RETURN_ARR(return_array);
}

PHP_FUNCTION(Async_awaitAnyOf)
{
	zval * futures;
	zend_object * cancellation = NULL;
	zend_long count = 0;

	ZEND_PARSE_PARAMETERS_START(2, 3)
		Z_PARAM_LONG(count)
		Z_PARAM_ZVAL(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	HashTable * results = zend_new_array(8);

	async_await_futures(futures,
		(int)count,
		false,
		ZEND_ASYNC_OBJECT_TO_EVENT(cancellation),
		0,
		0,
		results,
		NULL,
		false
		);

	if (EG(exception)) {
		zend_array_release(results);
		RETURN_THROWS();
	}

	RETURN_ARR(results);
}

PHP_FUNCTION(Async_awaitAnyOfWithErrors)
{
	zval * futures;
	zend_object * cancellation = NULL;
	zend_long count = 0;

	ZEND_PARSE_PARAMETERS_START(2, 3)
		Z_PARAM_LONG(count)
		Z_PARAM_ZVAL(futures);
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable);
	ZEND_PARSE_PARAMETERS_END();

	HashTable * results = zend_new_array(8);
	HashTable * errors = zend_new_array(8);

	async_await_futures(futures,
		(int)count,
		true,
		ZEND_ASYNC_OBJECT_TO_EVENT(cancellation),
		0,
		0,
		results,
		errors,
		false
		);

	if (EG(exception)) {
		zend_array_release(results);
		zend_array_release(errors);
		RETURN_THROWS();
	}

	HashTable * return_array = zend_new_array(2);

	zval val;
	ZVAL_ARR(&val, results);
	zend_hash_next_index_insert_new(return_array, &val);

	ZVAL_ARR(&val, errors);
	zend_hash_next_index_insert_new(return_array, &val);

	RETURN_ARR(return_array);
}

PHP_FUNCTION(Async_delay)
{
	zend_long ms = 0;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(ms)
	ZEND_PARSE_PARAMETERS_END();

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		return;
	}

	zend_async_waker_new_with_timeout(coroutine, ms, NULL);

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	ZEND_ASYNC_SUSPEND();

	coroutine->waker->dtor(coroutine);
}

PHP_FUNCTION(Async_timeout)
{
	zend_long ms = 0;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(ms)
	ZEND_PARSE_PARAMETERS_END();

	if (ms <= 0) {
		zend_value_error("Timeout value must be greater than 0");
		RETURN_THROWS();
	}

	zend_object * zend_object = async_timeout_create(ms, false);

	if (UNEXPECTED(EG(exception) != NULL)) {
		RETURN_THROWS();
	}

	RETURN_OBJ(zend_object);
}

PHP_FUNCTION(Async_currentContext)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;
}

PHP_FUNCTION(Async_coroutineContext)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

}

PHP_FUNCTION(Async_currentCoroutine)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		zend_async_throw(
			ZEND_ASYNC_EXCEPTION_DEFAULT,
			"The current coroutine is not defined"
		);

		RETURN_THROWS();
	}

	RETURN_OBJ_COPY(&coroutine->std);
}

PHP_FUNCTION(Async_rootContext)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

}

PHP_FUNCTION(Async_getCoroutines)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

}

PHP_FUNCTION(Async_gracefulShutdown)
{

}

/*
PHP_FUNCTION(Async_exec)
{

}
*/

PHP_METHOD(Async_Timeout, __construct)
{
	async_throw_error("Timeout cannot be constructed directly");
}

///////////////////////////////////////////////////////////////
/// Register Async Module
///////////////////////////////////////////////////////////////

ZEND_DECLARE_MODULE_GLOBALS(async)

void async_register_awaitable_ce(void)
{
	async_ce_awaitable = register_class_Async_Awaitable();
}

static zend_object_handlers async_timeout_handlers;

static void async_timeout_free(zend_object *object)
{
	async_timeout_t *t = ASYNC_TIMEOUT_FROM_OBJ(object);

	zend_object_std_dtor(&t->std);
}

static void async_timeout_dispose(zend_async_event_t *event)
{
	async_timeout_t *timeout = (async_timeout_t *) event;

	if (timeout->reactor_dispose == NULL) {
		return;
	}

	if (GC_REFCOUNT(&timeout->std) > 1) {
		OBJ_RELEASE(&timeout->std);
		return;
	}

	ZEND_ASYNC_EVENT_SET_NO_FREE_MEMORY(&timeout->event);

	timeout->reactor_dispose(event);
	timeout->reactor_dispose = NULL;
	OBJ_RELEASE(&timeout->std);
}

static zend_object *async_timeout_create(const zend_ulong ms, const bool is_periodic)
{
	async_timeout_t *timeout = (async_timeout_t *) ZEND_ASYNC_NEW_TIMER_EVENT_EX(
		ms, is_periodic, sizeof(async_timeout_t) + zend_object_properties_size(async_ce_timeout)
	);

	zend_object_std_init(&timeout->std, async_ce_timeout);
	object_properties_init(&timeout->std, async_ce_timeout);

	ZEND_ASYNC_EVENT_SET_ZEND_OBJ(&timeout->event);
	ZEND_ASYNC_EVENT_SET_NO_FREE_MEMORY(&timeout->event);
	ZEND_ASYNC_EVENT_SET_ZEND_OBJ_OFFSET(&timeout->event, XtOffsetOf(async_timeout_t, std));

	timeout->reactor_dispose = timeout->event.dispose;
	timeout->event.dispose = async_timeout_dispose;

	timeout->std.handlers = &async_timeout_handlers;
	return &timeout->std;
}

void async_register_timeout_ce(void)
{
	async_ce_timeout = register_class_Async_Timeout(async_ce_awaitable);

	async_ce_timeout->create_object = NULL;

	async_timeout_handlers = std_object_handlers;

	async_timeout_handlers.offset   = XtOffsetOf(async_timeout_t, std);
	async_timeout_handlers.free_obj = async_timeout_free;
}

static PHP_GINIT_FUNCTION(async)
{
#if defined(COMPILE_DL_ASYNC) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif

	circular_buffer_ctor(&async_globals->microtasks, 64, sizeof(zend_async_microtask_t *), &zend_std_allocator);
	circular_buffer_ctor(&async_globals->coroutine_queue, 128, sizeof(zend_coroutine_t *), &zend_std_allocator);
	zend_hash_init(&async_globals->coroutines, 128, NULL, NULL, 0);

	async_globals->reactor = NULL;
}

/* {{{ PHP_GSHUTDOWN_FUNCTION */
static PHP_GSHUTDOWN_FUNCTION(async)
{
	circular_buffer_dtor(&async_globals->microtasks);
	circular_buffer_dtor(&async_globals->coroutine_queue);
	zend_hash_destroy(&async_globals->coroutines);

#ifdef PHP_WIN32
#endif
}
/* }}} */

/* Module registration */

ZEND_MINIT_FUNCTION(async)
{
	async_register_awaitable_ce();
	async_register_timeout_ce();
	async_register_scope_ce();
	async_register_coroutine_ce();
	async_register_exceptions_ce();
	//async_register_notifier_ce();
	//async_register_handlers_ce();
	//async_register_channel_ce();
	//async_register_iterator_ce();
	//async_register_context_ce();
	//async_register_future_ce();

	async_scheduler_startup();

	async_api_register();

#ifdef PHP_ASYNC_LIBUV
	async_libuv_reactor_register();
#endif

	return SUCCESS;
}

ZEND_MSHUTDOWN_FUNCTION(async)
{
	//async_scheduler_shutdown();

#ifdef PHP_ASYNC_LIBUV
	//async_libuv_shutdown();
#endif
	return SUCCESS;
}

PHP_MINFO_FUNCTION(async) {
	php_info_print_table_start();
	php_info_print_table_header(2, "Module", PHP_ASYNC_NAME);
	php_info_print_table_row(2, "Version", PHP_ASYNC_VERSION);
	php_info_print_table_row(2, "Support", "Enabled");
#ifdef PHP_ASYNC_LIBUV
	php_info_print_table_row(2, "LibUv Reactor", "Enabled");
#else
	php_info_print_table_row(2, "LibUv Reactor", "Disabled");
#endif
	php_info_print_table_end();
}

PHP_RINIT_FUNCTION(async) /* {{{ */
{
	//async_host_name_list_ctor();
	ZEND_ASYNC_READY;
	return SUCCESS;
} /* }}} */

PHP_RSHUTDOWN_FUNCTION(async) /* {{{ */
{
	//async_host_name_list_dtor();
	return SUCCESS;
} /* }}} */

zend_module_entry async_module_entry = {
	STANDARD_MODULE_HEADER,
	PHP_ASYNC_NAME,
	ext_functions,
	PHP_MINIT(async),
	PHP_MSHUTDOWN(async),
	PHP_RINIT(async),
	PHP_RSHUTDOWN(async),
	PHP_MINFO(async),
	PHP_ASYNC_VERSION,
	PHP_MODULE_GLOBALS(async),
	PHP_GINIT(async),
	PHP_GSHUTDOWN(async),
	NULL,
	STANDARD_MODULE_PROPERTIES_EX
};