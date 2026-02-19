/* This is a generated file, edit async.stub.php instead.
 * Stub hash: 9b18d85e6c92049f215d48af683c006544604498 */

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_Async_spawn, 0, 1, Async\\Coroutine, 0)
	ZEND_ARG_TYPE_INFO(0, task, IS_CALLABLE, 0)
	ZEND_ARG_VARIADIC_TYPE_INFO(0, args, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_Async_spawn_with, 0, 2, Async\\Coroutine, 0)
	ZEND_ARG_OBJ_INFO(0, provider, Async\\ScopeProvider, 0)
	ZEND_ARG_TYPE_INFO(0, task, IS_CALLABLE, 0)
	ZEND_ARG_VARIADIC_TYPE_INFO(0, args, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_suspend, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_protect, 0, 1, IS_MIXED, 0)
	ZEND_ARG_OBJ_INFO(0, closure, Closure, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_await, 0, 1, IS_MIXED, 0)
	ZEND_ARG_OBJ_INFO(0, awaitable, Async\\Completable, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Completable, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_await_any_or_fail, 0, 1, IS_MIXED, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, triggers, Traversable, MAY_BE_ARRAY, NULL)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Awaitable, 1, "null")
ZEND_END_ARG_INFO()

#define arginfo_Async_await_first_success arginfo_Async_await_any_or_fail

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_await_all_or_fail, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, triggers, Traversable, MAY_BE_ARRAY, NULL)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Awaitable, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, preserveKeyOrder, _IS_BOOL, 0, "true")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_await_all, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, triggers, Traversable, MAY_BE_ARRAY, NULL)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Awaitable, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, preserveKeyOrder, _IS_BOOL, 0, "true")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, fillNull, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_await_any_of_or_fail, 0, 2, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, count, IS_LONG, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, triggers, Traversable, MAY_BE_ARRAY, NULL)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Awaitable, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, preserveKeyOrder, _IS_BOOL, 0, "true")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_await_any_of, 0, 2, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, count, IS_LONG, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, triggers, Traversable, MAY_BE_ARRAY, NULL)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Awaitable, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, preserveKeyOrder, _IS_BOOL, 0, "true")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, fillNull, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_delay, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, ms, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_Async_timeout, 0, 1, Async\\Awaitable, 0)
	ZEND_ARG_TYPE_INFO(0, ms, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_Async_current_context, 0, 0, Async\\Context, 0)
ZEND_END_ARG_INFO()

#define arginfo_Async_coroutine_context arginfo_Async_current_context

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_Async_current_coroutine, 0, 0, Async\\Coroutine, 0)
ZEND_END_ARG_INFO()

#define arginfo_Async_root_context arginfo_Async_current_context

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_get_coroutines, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_iterate, 0, 2, IS_VOID, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, iterable, Traversable, MAY_BE_ARRAY, NULL)
	ZEND_ARG_TYPE_INFO(0, callback, IS_CALLABLE, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, concurrency, IS_LONG, 0, "0")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, cancelPending, _IS_BOOL, 0, "true")
ZEND_END_ARG_INFO()


ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_graceful_shutdown, 0, 0, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellationError, Async\\AsyncCancellation, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Completable_cancel, 0, 0, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\AsyncCancellation, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Completable_isCompleted, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Completable_isCancelled arginfo_class_Async_Completable_isCompleted

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_Timeout___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Timeout_cancel arginfo_class_Async_Completable_cancel

#define arginfo_class_Async_Timeout_isCompleted arginfo_class_Async_Completable_isCompleted

#define arginfo_class_Async_Timeout_isCancelled arginfo_class_Async_Completable_isCompleted

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_CircuitBreaker_getState, 0, 0, Async\\CircuitBreakerState, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_CircuitBreaker_activate arginfo_Async_suspend

#define arginfo_class_Async_CircuitBreaker_deactivate arginfo_Async_suspend

#define arginfo_class_Async_CircuitBreaker_recover arginfo_Async_suspend

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_CircuitBreakerStrategy_reportSuccess, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, source, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_CircuitBreakerStrategy_reportFailure, 0, 2, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, source, IS_MIXED, 0)
	ZEND_ARG_OBJ_INFO(0, error, Throwable, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_CircuitBreakerStrategy_shouldRecover arginfo_class_Async_Completable_isCompleted

ZEND_FUNCTION(Async_spawn);
ZEND_FUNCTION(Async_spawn_with);
ZEND_FUNCTION(Async_suspend);
ZEND_FUNCTION(Async_protect);
ZEND_FUNCTION(Async_await);
ZEND_FUNCTION(Async_await_any_or_fail);
ZEND_FUNCTION(Async_await_first_success);
ZEND_FUNCTION(Async_await_all_or_fail);
ZEND_FUNCTION(Async_await_all);
ZEND_FUNCTION(Async_await_any_of_or_fail);
ZEND_FUNCTION(Async_await_any_of);
ZEND_FUNCTION(Async_delay);
ZEND_FUNCTION(Async_timeout);
ZEND_FUNCTION(Async_current_context);
ZEND_FUNCTION(Async_coroutine_context);
ZEND_FUNCTION(Async_current_coroutine);
ZEND_FUNCTION(Async_root_context);
ZEND_FUNCTION(Async_get_coroutines);
ZEND_FUNCTION(Async_iterate);
ZEND_FUNCTION(Async_graceful_shutdown);
ZEND_METHOD(Async_Timeout, __construct);
ZEND_METHOD(Async_Timeout, cancel);
ZEND_METHOD(Async_Timeout, isCompleted);
ZEND_METHOD(Async_Timeout, isCancelled);

static const zend_function_entry ext_functions[] = {
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "spawn"), zif_Async_spawn, arginfo_Async_spawn, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "spawn_with"), zif_Async_spawn_with, arginfo_Async_spawn_with, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "suspend"), zif_Async_suspend, arginfo_Async_suspend, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "protect"), zif_Async_protect, arginfo_Async_protect, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "await"), zif_Async_await, arginfo_Async_await, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "await_any_or_fail"), zif_Async_await_any_or_fail, arginfo_Async_await_any_or_fail, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "await_first_success"), zif_Async_await_first_success, arginfo_Async_await_first_success, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "await_all_or_fail"), zif_Async_await_all_or_fail, arginfo_Async_await_all_or_fail, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "await_all"), zif_Async_await_all, arginfo_Async_await_all, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "await_any_of_or_fail"), zif_Async_await_any_of_or_fail, arginfo_Async_await_any_of_or_fail, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "await_any_of"), zif_Async_await_any_of, arginfo_Async_await_any_of, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "delay"), zif_Async_delay, arginfo_Async_delay, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "timeout"), zif_Async_timeout, arginfo_Async_timeout, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "current_context"), zif_Async_current_context, arginfo_Async_current_context, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "coroutine_context"), zif_Async_coroutine_context, arginfo_Async_coroutine_context, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "current_coroutine"), zif_Async_current_coroutine, arginfo_Async_current_coroutine, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "root_context"), zif_Async_root_context, arginfo_Async_root_context, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "get_coroutines"), zif_Async_get_coroutines, arginfo_Async_get_coroutines, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "iterate"), zif_Async_iterate, arginfo_Async_iterate, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "graceful_shutdown"), zif_Async_graceful_shutdown, arginfo_Async_graceful_shutdown, 0, NULL, NULL)
	ZEND_FE_END
};

static const zend_function_entry class_Async_Completable_methods[] = {
	ZEND_RAW_FENTRY("cancel", NULL, arginfo_class_Async_Completable_cancel, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_RAW_FENTRY("isCompleted", NULL, arginfo_class_Async_Completable_isCompleted, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_RAW_FENTRY("isCancelled", NULL, arginfo_class_Async_Completable_isCancelled, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_FE_END
};

static const zend_function_entry class_Async_Timeout_methods[] = {
	ZEND_ME(Async_Timeout, __construct, arginfo_class_Async_Timeout___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(Async_Timeout, cancel, arginfo_class_Async_Timeout_cancel, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Timeout, isCompleted, arginfo_class_Async_Timeout_isCompleted, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Timeout, isCancelled, arginfo_class_Async_Timeout_isCancelled, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static const zend_function_entry class_Async_CircuitBreaker_methods[] = {
	ZEND_RAW_FENTRY("getState", NULL, arginfo_class_Async_CircuitBreaker_getState, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_RAW_FENTRY("activate", NULL, arginfo_class_Async_CircuitBreaker_activate, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_RAW_FENTRY("deactivate", NULL, arginfo_class_Async_CircuitBreaker_deactivate, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_RAW_FENTRY("recover", NULL, arginfo_class_Async_CircuitBreaker_recover, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_FE_END
};

static const zend_function_entry class_Async_CircuitBreakerStrategy_methods[] = {
	ZEND_RAW_FENTRY("reportSuccess", NULL, arginfo_class_Async_CircuitBreakerStrategy_reportSuccess, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_RAW_FENTRY("reportFailure", NULL, arginfo_class_Async_CircuitBreakerStrategy_reportFailure, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_RAW_FENTRY("shouldRecover", NULL, arginfo_class_Async_CircuitBreakerStrategy_shouldRecover, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_Awaitable(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Awaitable", NULL);
	class_entry = zend_register_internal_interface(&ce);

	return class_entry;
}

static zend_class_entry *register_class_Async_Completable(zend_class_entry *class_entry_Async_Awaitable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Completable", class_Async_Completable_methods);
	class_entry = zend_register_internal_interface(&ce);
	zend_class_implements(class_entry, 1, class_entry_Async_Awaitable);

	return class_entry;
}

static zend_class_entry *register_class_Async_Timeout(zend_class_entry *class_entry_Async_Completable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Timeout", class_Async_Timeout_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL);
	zend_class_implements(class_entry, 1, class_entry_Async_Completable);

	return class_entry;
}


static zend_class_entry *register_class_Async_CircuitBreakerState(void)
{
	zend_class_entry *class_entry = zend_register_internal_enum("Async\\CircuitBreakerState", IS_UNDEF, NULL);

	zend_enum_add_case_cstr(class_entry, "ACTIVE", NULL);

	zend_enum_add_case_cstr(class_entry, "INACTIVE", NULL);

	zend_enum_add_case_cstr(class_entry, "RECOVERING", NULL);

	return class_entry;
}

static zend_class_entry *register_class_Async_CircuitBreaker(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "CircuitBreaker", class_Async_CircuitBreaker_methods);
	class_entry = zend_register_internal_interface(&ce);

	return class_entry;
}

static zend_class_entry *register_class_Async_CircuitBreakerStrategy(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "CircuitBreakerStrategy", class_Async_CircuitBreakerStrategy_methods);
	class_entry = zend_register_internal_interface(&ce);

	return class_entry;
}
