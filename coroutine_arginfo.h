/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: e241f73a62d9d48b7924045d76a5ef81b5cb3980 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Coroutine_getId, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Coroutine_asHiPriority, 0, 0, Async\\Coroutine, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Coroutine_getContext, 0, 0, Async\\Context, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Coroutine_getTrace, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Coroutine_getSpawnFileAndLine arginfo_class_Async_Coroutine_getTrace

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Coroutine_getSpawnLocation, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Coroutine_getSuspendFileAndLine arginfo_class_Async_Coroutine_getTrace

#define arginfo_class_Async_Coroutine_getSuspendLocation arginfo_class_Async_Coroutine_getSpawnLocation

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Coroutine_isStarted, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Coroutine_isQueued arginfo_class_Async_Coroutine_isStarted

#define arginfo_class_Async_Coroutine_isRunning arginfo_class_Async_Coroutine_isStarted

#define arginfo_class_Async_Coroutine_isSuspended arginfo_class_Async_Coroutine_isStarted

#define arginfo_class_Async_Coroutine_isCancelled arginfo_class_Async_Coroutine_isStarted

#define arginfo_class_Async_Coroutine_isCancellationRequested arginfo_class_Async_Coroutine_isStarted

#define arginfo_class_Async_Coroutine_isFinished arginfo_class_Async_Coroutine_isStarted

#define arginfo_class_Async_Coroutine_getAwaitingInfo arginfo_class_Async_Coroutine_getTrace

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Coroutine_cancel, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, cancellationException, Async\\CancellationException, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Coroutine_onFinally, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, callback, Closure, 0)
ZEND_END_ARG_INFO()

ZEND_METHOD(Async_Coroutine, getId);
ZEND_METHOD(Async_Coroutine, asHiPriority);
ZEND_METHOD(Async_Coroutine, getContext);
ZEND_METHOD(Async_Coroutine, getTrace);
ZEND_METHOD(Async_Coroutine, getSpawnFileAndLine);
ZEND_METHOD(Async_Coroutine, getSpawnLocation);
ZEND_METHOD(Async_Coroutine, getSuspendFileAndLine);
ZEND_METHOD(Async_Coroutine, getSuspendLocation);
ZEND_METHOD(Async_Coroutine, isStarted);
ZEND_METHOD(Async_Coroutine, isQueued);
ZEND_METHOD(Async_Coroutine, isRunning);
ZEND_METHOD(Async_Coroutine, isSuspended);
ZEND_METHOD(Async_Coroutine, isCancelled);
ZEND_METHOD(Async_Coroutine, isCancellationRequested);
ZEND_METHOD(Async_Coroutine, isFinished);
ZEND_METHOD(Async_Coroutine, getAwaitingInfo);
ZEND_METHOD(Async_Coroutine, cancel);
ZEND_METHOD(Async_Coroutine, onFinally);

static const zend_function_entry class_Async_Coroutine_methods[] = {
	ZEND_ME(Async_Coroutine, getId, arginfo_class_Async_Coroutine_getId, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, asHiPriority, arginfo_class_Async_Coroutine_asHiPriority, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, getContext, arginfo_class_Async_Coroutine_getContext, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, getTrace, arginfo_class_Async_Coroutine_getTrace, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, getSpawnFileAndLine, arginfo_class_Async_Coroutine_getSpawnFileAndLine, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, getSpawnLocation, arginfo_class_Async_Coroutine_getSpawnLocation, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, getSuspendFileAndLine, arginfo_class_Async_Coroutine_getSuspendFileAndLine, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, getSuspendLocation, arginfo_class_Async_Coroutine_getSuspendLocation, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, isStarted, arginfo_class_Async_Coroutine_isStarted, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, isQueued, arginfo_class_Async_Coroutine_isQueued, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, isRunning, arginfo_class_Async_Coroutine_isRunning, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, isSuspended, arginfo_class_Async_Coroutine_isSuspended, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, isCancelled, arginfo_class_Async_Coroutine_isCancelled, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, isCancellationRequested, arginfo_class_Async_Coroutine_isCancellationRequested, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, isFinished, arginfo_class_Async_Coroutine_isFinished, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, getAwaitingInfo, arginfo_class_Async_Coroutine_getAwaitingInfo, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, cancel, arginfo_class_Async_Coroutine_cancel, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Coroutine, onFinally, arginfo_class_Async_Coroutine_onFinally, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_Coroutine(zend_class_entry *class_entry_Async_Awaitable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Coroutine", class_Async_Coroutine_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);
	zend_class_implements(class_entry, 1, class_entry_Async_Awaitable);

	return class_entry;
}
