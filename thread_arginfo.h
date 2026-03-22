/* This is a generated file, edit thread.stub.php instead.
 * Stub hash: 41d0c15ccc86426976277a135dc8c8e821e6f8ee */

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_RemoteException_getRemoteException, 0, 0, Throwable, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_RemoteException_getRemoteClass, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

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

ZEND_METHOD(Async_RemoteException, getRemoteException);
ZEND_METHOD(Async_RemoteException, getRemoteClass);
ZEND_METHOD(Async_Thread, __construct);
ZEND_METHOD(Async_Thread, isRunning);
ZEND_METHOD(Async_Thread, isCompleted);
ZEND_METHOD(Async_Thread, isCancelled);
ZEND_METHOD(Async_Thread, getResult);
ZEND_METHOD(Async_Thread, getException);
ZEND_METHOD(Async_Thread, cancel);
ZEND_METHOD(Async_Thread, finally);

static const zend_function_entry class_Async_RemoteException_methods[] = {
	ZEND_ME(Async_RemoteException, getRemoteException, arginfo_class_Async_RemoteException_getRemoteException, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_RemoteException, getRemoteClass, arginfo_class_Async_RemoteException_getRemoteClass, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

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

static zend_class_entry *register_class_Async_RemoteException(zend_class_entry *class_entry_Async_AsyncException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "RemoteException", class_Async_RemoteException_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Async_AsyncException, ZEND_ACC_NO_DYNAMIC_PROPERTIES);

	zval property_remoteException_default_value;
	ZVAL_NULL(&property_remoteException_default_value);
	zend_string *property_remoteException_name = zend_string_init("remoteException", sizeof("remoteException") - 1, true);
	zend_string *property_remoteException_class_Throwable = zend_string_init("Throwable", sizeof("Throwable")-1, 1);
	zend_declare_typed_property(class_entry, property_remoteException_name, &property_remoteException_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_CLASS(property_remoteException_class_Throwable, 0, MAY_BE_NULL));
	zend_string_release_ex(property_remoteException_name, true);

	zval property_remoteClass_default_value;
	ZVAL_EMPTY_STRING(&property_remoteClass_default_value);
	zend_string *property_remoteClass_name = zend_string_init("remoteClass", sizeof("remoteClass") - 1, true);
	zend_declare_typed_property(class_entry, property_remoteClass_name, &property_remoteClass_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));
	zend_string_release_ex(property_remoteClass_name, true);

	return class_entry;
}

static zend_class_entry *register_class_Async_ThreadTransferException(zend_class_entry *class_entry_Async_AsyncException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "ThreadTransferException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Async_AsyncException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_Thread(zend_class_entry *class_entry_Async_Completable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Thread", class_Async_Thread_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL);
	zend_class_implements(class_entry, 1, class_entry_Async_Completable);

	return class_entry;
}
