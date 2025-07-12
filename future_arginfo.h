/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 3da547a8570c93f495ee7893744425efcc4c0490 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_FutureState___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FutureState_complete, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, result, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FutureState_error, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, throwable, Throwable, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FutureState_isComplete, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FutureState_ignore, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FutureState_getInfo, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Future_complete, 0, 0, Async\\Future, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, value, IS_MIXED, 0, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Future_error, 0, 1, Async\\Future, 0)
	ZEND_ARG_OBJ_INFO(0, throwable, Throwable, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_Future___construct, 0, 0, 1)
	ZEND_ARG_OBJ_INFO(0, state, Async\\FutureState, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Future_isComplete arginfo_class_Async_FutureState_isComplete

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Future_ignore, 0, 0, Async\\Future, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Future_map, 0, 1, Async\\Future, 0)
	ZEND_ARG_TYPE_INFO(0, map, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Future_catch, 0, 1, Async\\Future, 0)
	ZEND_ARG_TYPE_INFO(0, catch, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Future_finally, 0, 1, Async\\Future, 0)
	ZEND_ARG_TYPE_INFO(0, finally, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Future_await, 0, 0, IS_MIXED, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Awaitable, 1, "null")
ZEND_END_ARG_INFO()

ZEND_METHOD(Async_FutureState, __construct);
ZEND_METHOD(Async_FutureState, complete);
ZEND_METHOD(Async_FutureState, error);
ZEND_METHOD(Async_FutureState, isComplete);
ZEND_METHOD(Async_FutureState, ignore);
ZEND_METHOD(Async_FutureState, getInfo);
ZEND_METHOD(Async_Future, complete);
ZEND_METHOD(Async_Future, error);
ZEND_METHOD(Async_Future, __construct);
ZEND_METHOD(Async_Future, isComplete);
ZEND_METHOD(Async_Future, ignore);
ZEND_METHOD(Async_Future, map);
ZEND_METHOD(Async_Future, catch);
ZEND_METHOD(Async_Future, finally);
ZEND_METHOD(Async_Future, await);

static const zend_function_entry class_Async_FutureState_methods[] = {
	ZEND_ME(Async_FutureState, __construct, arginfo_class_Async_FutureState___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, complete, arginfo_class_Async_FutureState_complete, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, error, arginfo_class_Async_FutureState_error, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, isComplete, arginfo_class_Async_FutureState_isComplete, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, ignore, arginfo_class_Async_FutureState_ignore, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, getInfo, arginfo_class_Async_FutureState_getInfo, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static const zend_function_entry class_Async_Future_methods[] = {
	ZEND_ME(Async_Future, complete, arginfo_class_Async_Future_complete, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Async_Future, error, arginfo_class_Async_Future_error, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Async_Future, __construct, arginfo_class_Async_Future___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, isComplete, arginfo_class_Async_Future_isComplete, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, ignore, arginfo_class_Async_Future_ignore, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, map, arginfo_class_Async_Future_map, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, catch, arginfo_class_Async_Future_catch, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, finally, arginfo_class_Async_Future_finally, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, await, arginfo_class_Async_Future_await, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_FutureState(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "FutureState", class_Async_FutureState_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);

	return class_entry;
}

static zend_class_entry *register_class_Async_Future(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Future", class_Async_Future_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL);

	return class_entry;
}
