/* This is a generated file, edit thread.stub.php instead. */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_Thread___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Thread_isRunning, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Thread_isCompleted arginfo_class_Async_Thread_isRunning

#define arginfo_class_Async_Thread_isCancelled arginfo_class_Async_Thread_isRunning

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Thread_getResult, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Thread_getException arginfo_class_Async_Thread_getResult

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Thread_cancel, 0, 0, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\AsyncCancellation, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Thread_finally, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, callback, Closure, 0)
ZEND_END_ARG_INFO()

ZEND_METHOD(Async_Thread, __construct);
ZEND_METHOD(Async_Thread, isRunning);
ZEND_METHOD(Async_Thread, isCompleted);
ZEND_METHOD(Async_Thread, isCancelled);
ZEND_METHOD(Async_Thread, getResult);
ZEND_METHOD(Async_Thread, getException);
ZEND_METHOD(Async_Thread, cancel);
ZEND_METHOD(Async_Thread, finally);

static const zend_function_entry class_Async_Thread_methods[] = {
	ZEND_ME(Async_Thread, __construct, arginfo_class_Async_Thread___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(Async_Thread, isRunning, arginfo_class_Async_Thread_isRunning, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Thread, isCompleted, arginfo_class_Async_Thread_isCompleted, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Thread, isCancelled, arginfo_class_Async_Thread_isCancelled, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Thread, getResult, arginfo_class_Async_Thread_getResult, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Thread, getException, arginfo_class_Async_Thread_getException, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Thread, cancel, arginfo_class_Async_Thread_cancel, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Thread, finally, arginfo_class_Async_Thread_finally, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_Thread(zend_class_entry *class_entry_Async_Completable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Thread", class_Async_Thread_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE);
	zend_class_implements(class_entry, 1, class_entry_Async_Completable);

	return class_entry;
}
