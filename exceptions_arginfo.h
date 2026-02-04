/* This is a generated file, edit exceptions.stub.php instead.
 * Stub hash: f74a2005b4886f0327c16e1a536d1a4e84a04855 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_CompositeException_addException, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, exception, Throwable, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_CompositeException_getExceptions, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_METHOD(Async_CompositeException, addException);
ZEND_METHOD(Async_CompositeException, getExceptions);

static const zend_function_entry class_Async_CompositeException_methods[] = {
	ZEND_ME(Async_CompositeException, addException, arginfo_class_Async_CompositeException_addException, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_CompositeException, getExceptions, arginfo_class_Async_CompositeException_getExceptions, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_CancellationError(zend_class_entry *class_entry_Error)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "CancellationError", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Error, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_AsyncException(zend_class_entry *class_entry_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "AsyncException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Exception, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_InputOutputException(zend_class_entry *class_entry_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "InputOutputException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Exception, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_DnsException(zend_class_entry *class_entry_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "DnsException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Exception, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_TimeoutException(zend_class_entry *class_entry_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "TimeoutException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Exception, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_PollException(zend_class_entry *class_entry_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "PollException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Exception, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_DeadlockError(zend_class_entry *class_entry_Error)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "DeadlockError", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Error, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_ServiceUnavailableException(zend_class_entry *class_entry_Async_AsyncException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "ServiceUnavailableException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Async_AsyncException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_CompositeException(zend_class_entry *class_entry_Exception)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "CompositeException", class_Async_CompositeException_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Exception, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES);

	zval property_exceptions_default_value;
	ZVAL_UNDEF(&property_exceptions_default_value);
	zend_string *property_exceptions_name = zend_string_init("exceptions", sizeof("exceptions") - 1, true);
	zend_declare_typed_property(class_entry, property_exceptions_name, &property_exceptions_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_ARRAY));
	zend_string_release_ex(property_exceptions_name, true);

	return class_entry;
}
