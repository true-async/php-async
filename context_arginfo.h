/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: bb8f3ff840be0001815129aa778f2c91b5701873 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Context_find, 0, 1, IS_MIXED, 0)
ZEND_ARG_TYPE_MASK(0, key, MAY_BE_STRING | MAY_BE_OBJECT, NULL)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Context_get arginfo_class_Async_Context_find

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Context_has, 0, 1, _IS_BOOL, 0)
ZEND_ARG_TYPE_MASK(0, key, MAY_BE_STRING | MAY_BE_OBJECT, NULL)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Context_findLocal arginfo_class_Async_Context_find

#define arginfo_class_Async_Context_getLocal arginfo_class_Async_Context_find

#define arginfo_class_Async_Context_hasLocal arginfo_class_Async_Context_has

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Context_set, 0, 2, Async\\Context, 0)
ZEND_ARG_TYPE_MASK(0, key, MAY_BE_STRING | MAY_BE_OBJECT, NULL)
ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, replace, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Context_unset, 0, 1, Async\\Context, 0)
ZEND_ARG_TYPE_MASK(0, key, MAY_BE_STRING | MAY_BE_OBJECT, NULL)
ZEND_END_ARG_INFO()

ZEND_METHOD(Async_Context, find);
ZEND_METHOD(Async_Context, get);
ZEND_METHOD(Async_Context, has);
ZEND_METHOD(Async_Context, findLocal);
ZEND_METHOD(Async_Context, getLocal);
ZEND_METHOD(Async_Context, hasLocal);
ZEND_METHOD(Async_Context, set);
ZEND_METHOD(Async_Context, unset);

static const zend_function_entry class_Async_Context_methods[] = {
	ZEND_ME(Async_Context, find, arginfo_class_Async_Context_find, ZEND_ACC_PUBLIC) ZEND_ME(
			Async_Context, get, arginfo_class_Async_Context_get, ZEND_ACC_PUBLIC)
			ZEND_ME(Async_Context, has, arginfo_class_Async_Context_has, ZEND_ACC_PUBLIC) ZEND_ME(
					Async_Context, findLocal, arginfo_class_Async_Context_findLocal, ZEND_ACC_PUBLIC)
					ZEND_ME(Async_Context, getLocal, arginfo_class_Async_Context_getLocal, ZEND_ACC_PUBLIC) ZEND_ME(
							Async_Context, hasLocal, arginfo_class_Async_Context_hasLocal, ZEND_ACC_PUBLIC)
							ZEND_ME(Async_Context, set, arginfo_class_Async_Context_set, ZEND_ACC_PUBLIC)
									ZEND_ME(Async_Context, unset, arginfo_class_Async_Context_unset, ZEND_ACC_PUBLIC)
											ZEND_FE_END
};

static zend_class_entry *register_class_Async_Context(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Context", class_Async_Context_methods);
	class_entry = zend_register_internal_class_with_flags(
			&ce, NULL, ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES | ZEND_ACC_NOT_SERIALIZABLE);

	return class_entry;
}
