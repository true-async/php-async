/* This is a generated file, edit future.stub.php instead.
 * Stub hash: 8cb54d0ce1804e21443634c3bde6d07c6ebdd754 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_FutureState___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FutureState_complete, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, result, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FutureState_error, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, throwable, Throwable, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FutureState_isCompleted, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FutureState_ignore, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FutureState_getAwaitingInfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_FutureState_getCreatedFileAndLine arginfo_class_Async_FutureState_getAwaitingInfo

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FutureState_getCreatedLocation, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_FutureState_getCompletedFileAndLine arginfo_class_Async_FutureState_getAwaitingInfo

#define arginfo_class_Async_FutureState_getCompletedLocation arginfo_class_Async_FutureState_getCreatedLocation

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Future_completed, 0, 0, Async\\Future, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, value, IS_MIXED, 0, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Future_failed, 0, 1, Async\\Future, 0)
	ZEND_ARG_OBJ_INFO(0, throwable, Throwable, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_Future___construct, 0, 0, 1)
	ZEND_ARG_OBJ_INFO(0, state, Async\\FutureState, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Future_isCompleted arginfo_class_Async_FutureState_isCompleted

#define arginfo_class_Async_Future_isCancelled arginfo_class_Async_FutureState_isCompleted

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Future_cancel, 0, 0, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\CancellationError, 1, "null")
ZEND_END_ARG_INFO()

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
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\Completable, 1, "null")
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Future_getAwaitingInfo arginfo_class_Async_FutureState_getAwaitingInfo

#define arginfo_class_Async_Future_getCreatedFileAndLine arginfo_class_Async_FutureState_getAwaitingInfo

#define arginfo_class_Async_Future_getCreatedLocation arginfo_class_Async_FutureState_getCreatedLocation

#define arginfo_class_Async_Future_getCompletedFileAndLine arginfo_class_Async_FutureState_getAwaitingInfo

#define arginfo_class_Async_Future_getCompletedLocation arginfo_class_Async_FutureState_getCreatedLocation

ZEND_METHOD(Async_FutureState, __construct);
ZEND_METHOD(Async_FutureState, complete);
ZEND_METHOD(Async_FutureState, error);
ZEND_METHOD(Async_FutureState, isCompleted);
ZEND_METHOD(Async_FutureState, ignore);
ZEND_METHOD(Async_FutureState, getAwaitingInfo);
ZEND_METHOD(Async_FutureState, getCreatedFileAndLine);
ZEND_METHOD(Async_FutureState, getCreatedLocation);
ZEND_METHOD(Async_FutureState, getCompletedFileAndLine);
ZEND_METHOD(Async_FutureState, getCompletedLocation);
ZEND_METHOD(Async_Future, completed);
ZEND_METHOD(Async_Future, failed);
ZEND_METHOD(Async_Future, __construct);
ZEND_METHOD(Async_Future, isCompleted);
ZEND_METHOD(Async_Future, isCancelled);
ZEND_METHOD(Async_Future, cancel);
ZEND_METHOD(Async_Future, ignore);
ZEND_METHOD(Async_Future, map);
ZEND_METHOD(Async_Future, catch);
ZEND_METHOD(Async_Future, finally);
ZEND_METHOD(Async_Future, await);
ZEND_METHOD(Async_Future, getAwaitingInfo);
ZEND_METHOD(Async_Future, getCreatedFileAndLine);
ZEND_METHOD(Async_Future, getCreatedLocation);
ZEND_METHOD(Async_Future, getCompletedFileAndLine);
ZEND_METHOD(Async_Future, getCompletedLocation);

static const zend_function_entry class_Async_FutureState_methods[] = {
	ZEND_ME(Async_FutureState, __construct, arginfo_class_Async_FutureState___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, complete, arginfo_class_Async_FutureState_complete, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, error, arginfo_class_Async_FutureState_error, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, isCompleted, arginfo_class_Async_FutureState_isCompleted, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, ignore, arginfo_class_Async_FutureState_ignore, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, getAwaitingInfo, arginfo_class_Async_FutureState_getAwaitingInfo, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, getCreatedFileAndLine, arginfo_class_Async_FutureState_getCreatedFileAndLine, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, getCreatedLocation, arginfo_class_Async_FutureState_getCreatedLocation, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, getCompletedFileAndLine, arginfo_class_Async_FutureState_getCompletedFileAndLine, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FutureState, getCompletedLocation, arginfo_class_Async_FutureState_getCompletedLocation, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static const zend_function_entry class_Async_Future_methods[] = {
	ZEND_ME(Async_Future, completed, arginfo_class_Async_Future_completed, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Async_Future, failed, arginfo_class_Async_Future_failed, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Async_Future, __construct, arginfo_class_Async_Future___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, isCompleted, arginfo_class_Async_Future_isCompleted, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, isCancelled, arginfo_class_Async_Future_isCancelled, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, cancel, arginfo_class_Async_Future_cancel, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, ignore, arginfo_class_Async_Future_ignore, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, map, arginfo_class_Async_Future_map, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, catch, arginfo_class_Async_Future_catch, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, finally, arginfo_class_Async_Future_finally, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, await, arginfo_class_Async_Future_await, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, getAwaitingInfo, arginfo_class_Async_Future_getAwaitingInfo, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, getCreatedFileAndLine, arginfo_class_Async_Future_getCreatedFileAndLine, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, getCreatedLocation, arginfo_class_Async_Future_getCreatedLocation, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, getCompletedFileAndLine, arginfo_class_Async_Future_getCompletedFileAndLine, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Future, getCompletedLocation, arginfo_class_Async_Future_getCompletedLocation, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_FutureState(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "FutureState", class_Async_FutureState_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);

	return class_entry;
}

static zend_class_entry *register_class_Async_Future(zend_class_entry *class_entry_Async_Completable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Future", class_Async_Future_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL);
	zend_class_implements(class_entry, 1, class_entry_Async_Completable);

	return class_entry;
}
