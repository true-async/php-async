/* This is a generated file, edit fs_watcher.stub.php instead. */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_FileSystemWatcher___construct, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, recursive, _IS_BOOL, 0, "false")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, coalesce, _IS_BOOL, 0, "true")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FileSystemWatcher_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_FileSystemWatcher_isClosed, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_FileSystemWatcher_getIterator, 0, 0, Iterator, 0)
ZEND_END_ARG_INFO()

ZEND_METHOD(Async_FileSystemWatcher, __construct);
ZEND_METHOD(Async_FileSystemWatcher, close);
ZEND_METHOD(Async_FileSystemWatcher, isClosed);
ZEND_METHOD(Async_FileSystemWatcher, getIterator);

static const zend_function_entry class_Async_FileSystemWatcher_methods[] = {
	ZEND_ME(Async_FileSystemWatcher, __construct, arginfo_class_Async_FileSystemWatcher___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FileSystemWatcher, close, arginfo_class_Async_FileSystemWatcher_close, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FileSystemWatcher, isClosed, arginfo_class_Async_FileSystemWatcher_isClosed, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_FileSystemWatcher, getIterator, arginfo_class_Async_FileSystemWatcher_getIterator, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_FileSystemWatcher(zend_class_entry *class_entry_Async_Awaitable, zend_class_entry *class_entry_IteratorAggregate)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "FileSystemWatcher", class_Async_FileSystemWatcher_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);
	zend_class_implements(class_entry, 2, class_entry_Async_Awaitable, class_entry_IteratorAggregate);

	return class_entry;
}

static zend_class_entry *register_class_Async_FileSystemEvent(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "FileSystemEvent", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_READONLY_CLASS|ZEND_ACC_NO_DYNAMIC_PROPERTIES);

	zval property_path_default_value;
	ZVAL_UNDEF(&property_path_default_value);
	zend_string *property_path_name = zend_string_init("path", sizeof("path") - 1, 1);
	zend_declare_typed_property(class_entry, property_path_name, &property_path_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));
	zend_string_release(property_path_name);

	zval property_filename_default_value;
	ZVAL_UNDEF(&property_filename_default_value);
	zend_string *property_filename_name = zend_string_init("filename", sizeof("filename") - 1, 1);
	zend_declare_typed_property(class_entry, property_filename_name, &property_filename_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING|MAY_BE_NULL));
	zend_string_release(property_filename_name);

	zval property_renamed_default_value;
	ZVAL_UNDEF(&property_renamed_default_value);
	zend_string *property_renamed_name = zend_string_init("renamed", sizeof("renamed") - 1, 1);
	zend_declare_typed_property(class_entry, property_renamed_name, &property_renamed_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_BOOL));
	zend_string_release(property_renamed_name);

	zval property_changed_default_value;
	ZVAL_UNDEF(&property_changed_default_value);
	zend_string *property_changed_name = zend_string_init("changed", sizeof("changed") - 1, 1);
	zend_declare_typed_property(class_entry, property_changed_name, &property_changed_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_BOOL));
	zend_string_release(property_changed_name);

	return class_entry;
}
