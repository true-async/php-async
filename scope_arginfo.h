/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 6106b3351f8094535fee7310e1d096ed06fb813d */

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_ScopeProvider_provideScope, 0, 0, Async\\Scope, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_SpawnStrategy_beforeCoroutineEnqueue, 0, 2, IS_ARRAY, 0)
	ZEND_ARG_OBJ_INFO(0, coroutine, Async\\Coroutine, 0)
	ZEND_ARG_OBJ_INFO(0, scope, Async\\Scope, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_SpawnStrategy_afterCoroutineEnqueue, 0, 2, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, coroutine, Async\\Coroutine, 0)
	ZEND_ARG_OBJ_INFO(0, scope, Async\\Scope, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Scope_inherit, 0, 0, Async\\Scope, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, parentScope, Async\\Scope, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Scope_provideScope, 0, 0, Async\\Scope, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_Scope___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Scope_asNotSafely arginfo_class_Async_Scope_provideScope

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Scope_spawn, 0, 1, Async\\Coroutine, 0)
	ZEND_ARG_OBJ_INFO(0, callable, Closure, 0)
	ZEND_ARG_VARIADIC_TYPE_INFO(0, params, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Scope_cancel, 0, 0, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellationException, Async\\CancellationException, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Scope_awaitCompletion, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, cancellation, Async\\Awaitable, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Scope_awaitAfterCancellation, 0, 0, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, errorHandler, IS_CALLABLE, 1, "null")
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Awaitable, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Scope_isFinished, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Scope_isClosed arginfo_class_Async_Scope_isFinished

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Scope_setExceptionHandler, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, exceptionHandler, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Scope_setChildScopeExceptionHandler arginfo_class_Async_Scope_setExceptionHandler

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Scope_onFinally, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, callback, Closure, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Scope_dispose, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Scope_disposeSafely arginfo_class_Async_Scope_dispose

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Scope_disposeAfterTimeout, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, timeout, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Scope_getChildScopes, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_METHOD(Async_Scope, inherit);
ZEND_METHOD(Async_Scope, provideScope);
ZEND_METHOD(Async_Scope, __construct);
ZEND_METHOD(Async_Scope, asNotSafely);
ZEND_METHOD(Async_Scope, spawn);
ZEND_METHOD(Async_Scope, cancel);
ZEND_METHOD(Async_Scope, awaitCompletion);
ZEND_METHOD(Async_Scope, awaitAfterCancellation);
ZEND_METHOD(Async_Scope, isFinished);
ZEND_METHOD(Async_Scope, isClosed);
ZEND_METHOD(Async_Scope, setExceptionHandler);
ZEND_METHOD(Async_Scope, setChildScopeExceptionHandler);
ZEND_METHOD(Async_Scope, onFinally);
ZEND_METHOD(Async_Scope, dispose);
ZEND_METHOD(Async_Scope, disposeSafely);
ZEND_METHOD(Async_Scope, disposeAfterTimeout);
ZEND_METHOD(Async_Scope, getChildScopes);

static const zend_function_entry class_Async_ScopeProvider_methods[] = {
	ZEND_RAW_FENTRY("provideScope", NULL, arginfo_class_Async_ScopeProvider_provideScope, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_FE_END
};

static const zend_function_entry class_Async_SpawnStrategy_methods[] = {
	ZEND_RAW_FENTRY("beforeCoroutineEnqueue", NULL, arginfo_class_Async_SpawnStrategy_beforeCoroutineEnqueue, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_RAW_FENTRY("afterCoroutineEnqueue", NULL, arginfo_class_Async_SpawnStrategy_afterCoroutineEnqueue, ZEND_ACC_PUBLIC|ZEND_ACC_ABSTRACT, NULL, NULL)
	ZEND_FE_END
};

static const zend_function_entry class_Async_Scope_methods[] = {
	ZEND_ME(Async_Scope, inherit, arginfo_class_Async_Scope_inherit, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Async_Scope, provideScope, arginfo_class_Async_Scope_provideScope, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, __construct, arginfo_class_Async_Scope___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, asNotSafely, arginfo_class_Async_Scope_asNotSafely, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, spawn, arginfo_class_Async_Scope_spawn, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, cancel, arginfo_class_Async_Scope_cancel, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, awaitCompletion, arginfo_class_Async_Scope_awaitCompletion, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, awaitAfterCancellation, arginfo_class_Async_Scope_awaitAfterCancellation, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, isFinished, arginfo_class_Async_Scope_isFinished, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, isClosed, arginfo_class_Async_Scope_isClosed, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, setExceptionHandler, arginfo_class_Async_Scope_setExceptionHandler, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, setChildScopeExceptionHandler, arginfo_class_Async_Scope_setChildScopeExceptionHandler, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, onFinally, arginfo_class_Async_Scope_onFinally, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, dispose, arginfo_class_Async_Scope_dispose, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, disposeSafely, arginfo_class_Async_Scope_disposeSafely, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, disposeAfterTimeout, arginfo_class_Async_Scope_disposeAfterTimeout, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Scope, getChildScopes, arginfo_class_Async_Scope_getChildScopes, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_ScopeProvider(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "ScopeProvider", class_Async_ScopeProvider_methods);
	class_entry = zend_register_internal_interface(&ce);

	return class_entry;
}

static zend_class_entry *register_class_Async_SpawnStrategy(zend_class_entry *class_entry_Async_ScopeProvider)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "SpawnStrategy", class_Async_SpawnStrategy_methods);
	class_entry = zend_register_internal_interface(&ce);
	zend_class_implements(class_entry, 1, class_entry_Async_ScopeProvider);

	return class_entry;
}

static zend_class_entry *register_class_Async_Scope(zend_class_entry *class_entry_Async_ScopeProvider)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Scope", class_Async_Scope_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);
	zend_class_implements(class_entry, 1, class_entry_Async_ScopeProvider);


	zend_string *attribute_name_Override_func_providescope_0 = zend_string_init_interned("Override", sizeof("Override") - 1, 1);
	zend_add_function_attribute(zend_hash_str_find_ptr(&class_entry->function_table, "providescope", sizeof("providescope") - 1), attribute_name_Override_func_providescope_0, 0);
	zend_string_release(attribute_name_Override_func_providescope_0);

	return class_entry;
}
