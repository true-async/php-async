/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 19ab3215c678150b9e0c8bd5cff9bf984e820066 */

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_Async_spawn, 0, 1, Async\\Coroutine, 0)
	ZEND_ARG_TYPE_INFO(0, task, IS_CALLABLE, 0)
	ZEND_ARG_VARIADIC_TYPE_INFO(0, args, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_Async_spawnWith, 0, 2, Async\\Coroutine, 0)
	ZEND_ARG_OBJ_INFO(0, provider, Async\\ScopeProvider, 0)
	ZEND_ARG_TYPE_INFO(0, task, IS_CALLABLE, 0)
	ZEND_ARG_VARIADIC_TYPE_INFO(0, args, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_suspend, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_protect, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, closure, Closure, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_await, 0, 1, IS_MIXED, 0)
	ZEND_ARG_OBJ_INFO(0, awaitable, Async\\Awaitable, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Awaitable, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_awaitAny, 0, 1, IS_MIXED, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, triggers, Traversable, MAY_BE_ARRAY, NULL)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Awaitable, 1, "null")
ZEND_END_ARG_INFO()

#define arginfo_Async_awaitFirstSuccess arginfo_Async_awaitAny

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_awaitAll, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, triggers, Traversable, MAY_BE_ARRAY, NULL)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Awaitable, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, fillNull, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

#define arginfo_Async_awaitAllWithErrors arginfo_Async_awaitAll

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_awaitAnyOf, 0, 2, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, count, IS_LONG, 0)
	ZEND_ARG_OBJ_TYPE_MASK(0, triggers, Traversable, MAY_BE_ARRAY, NULL)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Awaitable, 1, "null")
ZEND_END_ARG_INFO()

#define arginfo_Async_awaitAnyOfWithErrors arginfo_Async_awaitAnyOf

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_delay, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, ms, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_Async_timeout, 0, 1, Async\\Awaitable, 0)
	ZEND_ARG_TYPE_INFO(0, ms, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_Async_currentContext, 0, 0, Async\\Context, 0)
ZEND_END_ARG_INFO()

#define arginfo_Async_coroutineContext arginfo_Async_currentContext

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_Async_currentCoroutine, 0, 0, Async\\Coroutine, 0)
ZEND_END_ARG_INFO()

#define arginfo_Async_rootContext arginfo_Async_currentContext

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_getCoroutines, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_Async_gracefulShutdown, 0, 0, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellationException, Async\\CancellationException, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_Timeout___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_FUNCTION(Async_spawn);
ZEND_FUNCTION(Async_spawnWith);
ZEND_FUNCTION(Async_suspend);
ZEND_FUNCTION(Async_protect);
ZEND_FUNCTION(Async_await);
ZEND_FUNCTION(Async_awaitAny);
ZEND_FUNCTION(Async_awaitFirstSuccess);
ZEND_FUNCTION(Async_awaitAll);
ZEND_FUNCTION(Async_awaitAllWithErrors);
ZEND_FUNCTION(Async_awaitAnyOf);
ZEND_FUNCTION(Async_awaitAnyOfWithErrors);
ZEND_FUNCTION(Async_delay);
ZEND_FUNCTION(Async_timeout);
ZEND_FUNCTION(Async_currentContext);
ZEND_FUNCTION(Async_coroutineContext);
ZEND_FUNCTION(Async_currentCoroutine);
ZEND_FUNCTION(Async_rootContext);
ZEND_FUNCTION(Async_getCoroutines);
ZEND_FUNCTION(Async_gracefulShutdown);
ZEND_METHOD(Async_Timeout, __construct);

static const zend_function_entry ext_functions[] = {
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "spawn"), zif_Async_spawn, arginfo_Async_spawn, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "spawnWith"), zif_Async_spawnWith, arginfo_Async_spawnWith, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "suspend"), zif_Async_suspend, arginfo_Async_suspend, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "protect"), zif_Async_protect, arginfo_Async_protect, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "await"), zif_Async_await, arginfo_Async_await, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "awaitAny"), zif_Async_awaitAny, arginfo_Async_awaitAny, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "awaitFirstSuccess"), zif_Async_awaitFirstSuccess, arginfo_Async_awaitFirstSuccess, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "awaitAll"), zif_Async_awaitAll, arginfo_Async_awaitAll, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "awaitAllWithErrors"), zif_Async_awaitAllWithErrors, arginfo_Async_awaitAllWithErrors, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "awaitAnyOf"), zif_Async_awaitAnyOf, arginfo_Async_awaitAnyOf, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "awaitAnyOfWithErrors"), zif_Async_awaitAnyOfWithErrors, arginfo_Async_awaitAnyOfWithErrors, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "delay"), zif_Async_delay, arginfo_Async_delay, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "timeout"), zif_Async_timeout, arginfo_Async_timeout, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "currentContext"), zif_Async_currentContext, arginfo_Async_currentContext, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "coroutineContext"), zif_Async_coroutineContext, arginfo_Async_coroutineContext, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "currentCoroutine"), zif_Async_currentCoroutine, arginfo_Async_currentCoroutine, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "rootContext"), zif_Async_rootContext, arginfo_Async_rootContext, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "getCoroutines"), zif_Async_getCoroutines, arginfo_Async_getCoroutines, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("Async", "gracefulShutdown"), zif_Async_gracefulShutdown, arginfo_Async_gracefulShutdown, 0, NULL, NULL)
	ZEND_FE_END
};

static const zend_function_entry class_Async_Timeout_methods[] = {
	ZEND_ME(Async_Timeout, __construct, arginfo_class_Async_Timeout___construct, ZEND_ACC_PRIVATE)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_Awaitable(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Awaitable", NULL);
	class_entry = zend_register_internal_interface(&ce);

	return class_entry;
}

static zend_class_entry *register_class_Async_Timeout(zend_class_entry *class_entry_Async_Awaitable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Timeout", class_Async_Timeout_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL);
	zend_class_implements(class_entry, 1, class_entry_Async_Awaitable);

	return class_entry;
}
