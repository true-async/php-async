/* This is a generated file, edit thread_pool.stub.php instead.
 * Stub hash: d4d79484cbc779c18295ad0a963ac09389e202f4 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_ThreadPool___construct, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, workers, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, queueSize, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_ThreadPool_submit, 0, 1, Async\\Future, 0)
	ZEND_ARG_TYPE_INFO(0, task, IS_CALLABLE, 0)
	ZEND_ARG_VARIADIC_TYPE_INFO(0, args, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_ThreadPool_map, 0, 2, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, items, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, task, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_ThreadPool_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_ThreadPool_cancel arginfo_class_Async_ThreadPool_close

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_ThreadPool_isClosed, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_ThreadPool_getPendingCount, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_ThreadPool_getRunningCount arginfo_class_Async_ThreadPool_getPendingCount

#define arginfo_class_Async_ThreadPool_getCompletedCount arginfo_class_Async_ThreadPool_getPendingCount

#define arginfo_class_Async_ThreadPool_getWorkerCount arginfo_class_Async_ThreadPool_getPendingCount

ZEND_METHOD(Async_ThreadPool, __construct);
ZEND_METHOD(Async_ThreadPool, submit);
ZEND_METHOD(Async_ThreadPool, map);
ZEND_METHOD(Async_ThreadPool, close);
ZEND_METHOD(Async_ThreadPool, cancel);
ZEND_METHOD(Async_ThreadPool, isClosed);
ZEND_METHOD(Async_ThreadPool, getPendingCount);
ZEND_METHOD(Async_ThreadPool, getRunningCount);
ZEND_METHOD(Async_ThreadPool, getCompletedCount);
ZEND_METHOD(Async_ThreadPool, getWorkerCount);

static const zend_function_entry class_Async_ThreadPool_methods[] = {
	ZEND_ME(Async_ThreadPool, __construct, arginfo_class_Async_ThreadPool___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadPool, submit, arginfo_class_Async_ThreadPool_submit, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadPool, map, arginfo_class_Async_ThreadPool_map, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadPool, close, arginfo_class_Async_ThreadPool_close, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadPool, cancel, arginfo_class_Async_ThreadPool_cancel, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadPool, isClosed, arginfo_class_Async_ThreadPool_isClosed, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadPool, getPendingCount, arginfo_class_Async_ThreadPool_getPendingCount, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadPool, getRunningCount, arginfo_class_Async_ThreadPool_getRunningCount, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadPool, getCompletedCount, arginfo_class_Async_ThreadPool_getCompletedCount, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadPool, getWorkerCount, arginfo_class_Async_ThreadPool_getWorkerCount, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_ThreadPool(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "ThreadPool", class_Async_ThreadPool_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);

	return class_entry;
}

static zend_class_entry *register_class_Async_ThreadPoolException(zend_class_entry *class_entry_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "ThreadPoolException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Exception, 0);

	return class_entry;
}
