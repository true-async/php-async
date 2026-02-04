/* This is a generated file, edit pool.stub.php instead.
 * Stub hash: 4932099dff0084988155d4c1a1a2ef6be1beddeb */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_Pool___construct, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, factory, IS_CALLABLE, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, destructor, IS_CALLABLE, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, healthcheck, IS_CALLABLE, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, beforeAcquire, IS_CALLABLE, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, beforeRelease, IS_CALLABLE, 1, "null")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, min, IS_LONG, 0, "0")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, max, IS_LONG, 0, "10")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, healthcheckInterval, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Pool_acquire, 0, 0, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Pool_tryAcquire, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Pool_release, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, resource, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Pool_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Pool_isClosed, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Pool_count, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Pool_idleCount arginfo_class_Async_Pool_count

#define arginfo_class_Async_Pool_activeCount arginfo_class_Async_Pool_count

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Pool_setCircuitBreakerStrategy, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, strategy, Async\\CircuitBreakerStrategy, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Pool_getState, 0, 0, Async\\CircuitBreakerState, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Pool_activate arginfo_class_Async_Pool_close

#define arginfo_class_Async_Pool_deactivate arginfo_class_Async_Pool_close

#define arginfo_class_Async_Pool_recover arginfo_class_Async_Pool_close

ZEND_METHOD(Async_Pool, __construct);
ZEND_METHOD(Async_Pool, acquire);
ZEND_METHOD(Async_Pool, tryAcquire);
ZEND_METHOD(Async_Pool, release);
ZEND_METHOD(Async_Pool, close);
ZEND_METHOD(Async_Pool, isClosed);
ZEND_METHOD(Async_Pool, count);
ZEND_METHOD(Async_Pool, idleCount);
ZEND_METHOD(Async_Pool, activeCount);
ZEND_METHOD(Async_Pool, setCircuitBreakerStrategy);
ZEND_METHOD(Async_Pool, getState);
ZEND_METHOD(Async_Pool, activate);
ZEND_METHOD(Async_Pool, deactivate);
ZEND_METHOD(Async_Pool, recover);

static const zend_function_entry class_Async_Pool_methods[] = {
	ZEND_ME(Async_Pool, __construct, arginfo_class_Async_Pool___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, acquire, arginfo_class_Async_Pool_acquire, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, tryAcquire, arginfo_class_Async_Pool_tryAcquire, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, release, arginfo_class_Async_Pool_release, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, close, arginfo_class_Async_Pool_close, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, isClosed, arginfo_class_Async_Pool_isClosed, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, count, arginfo_class_Async_Pool_count, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, idleCount, arginfo_class_Async_Pool_idleCount, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, activeCount, arginfo_class_Async_Pool_activeCount, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, setCircuitBreakerStrategy, arginfo_class_Async_Pool_setCircuitBreakerStrategy, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, getState, arginfo_class_Async_Pool_getState, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, activate, arginfo_class_Async_Pool_activate, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, deactivate, arginfo_class_Async_Pool_deactivate, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Pool, recover, arginfo_class_Async_Pool_recover, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_PoolException(zend_class_entry *class_entry_Async_AsyncException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "PoolException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Async_AsyncException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_Pool(zend_class_entry *class_entry_Countable, zend_class_entry *class_entry_Async_CircuitBreaker)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Pool", class_Async_Pool_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL);
	zend_class_implements(class_entry, 2, class_entry_Countable, class_entry_Async_CircuitBreaker);

	return class_entry;
}
