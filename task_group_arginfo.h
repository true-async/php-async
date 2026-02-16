/* This is a generated file, edit task_group.stub.php instead.
 * Stub hash: d0b7254192ac64fc999eeb5e245ad92faa4d589a */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_TaskGroup___construct, 0, 0, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, concurrency, IS_LONG, 1, "null")
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, scope, Async\\Scope, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_TaskGroup_spawn, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, task, Closure, 0)
	ZEND_ARG_TYPE_MASK(0, key, MAY_BE_STRING|MAY_BE_LONG|MAY_BE_NULL, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_TaskGroup_all, 0, 0, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, ignoreErrors, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_TaskGroup_race, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_TaskGroup_any arginfo_class_Async_TaskGroup_race

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_TaskGroup_getResults, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_TaskGroup_getErrors arginfo_class_Async_TaskGroup_getResults

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_TaskGroup_suppressErrors, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_TaskGroup_cancel, 0, 0, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellation, Async\\AsyncCancellation, 1, "null")
ZEND_END_ARG_INFO()

#define arginfo_class_Async_TaskGroup_seal arginfo_class_Async_TaskGroup_suppressErrors

#define arginfo_class_Async_TaskGroup_dispose arginfo_class_Async_TaskGroup_suppressErrors

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_TaskGroup_isFinished, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_TaskGroup_isSealed arginfo_class_Async_TaskGroup_isFinished

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_TaskGroup_count, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_TaskGroup_onFinally, 0, 1, IS_VOID, 0)
	ZEND_ARG_OBJ_INFO(0, callback, Closure, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_TaskGroup_getIterator, 0, 0, Iterator, 0)
ZEND_END_ARG_INFO()

ZEND_METHOD(Async_TaskGroup, __construct);
ZEND_METHOD(Async_TaskGroup, spawn);
ZEND_METHOD(Async_TaskGroup, all);
ZEND_METHOD(Async_TaskGroup, race);
ZEND_METHOD(Async_TaskGroup, any);
ZEND_METHOD(Async_TaskGroup, getResults);
ZEND_METHOD(Async_TaskGroup, getErrors);
ZEND_METHOD(Async_TaskGroup, suppressErrors);
ZEND_METHOD(Async_TaskGroup, cancel);
ZEND_METHOD(Async_TaskGroup, seal);
ZEND_METHOD(Async_TaskGroup, dispose);
ZEND_METHOD(Async_TaskGroup, isFinished);
ZEND_METHOD(Async_TaskGroup, isSealed);
ZEND_METHOD(Async_TaskGroup, count);
ZEND_METHOD(Async_TaskGroup, onFinally);
ZEND_METHOD(Async_TaskGroup, getIterator);

static const zend_function_entry class_Async_TaskGroup_methods[] = {
	ZEND_ME(Async_TaskGroup, __construct, arginfo_class_Async_TaskGroup___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, spawn, arginfo_class_Async_TaskGroup_spawn, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, all, arginfo_class_Async_TaskGroup_all, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, race, arginfo_class_Async_TaskGroup_race, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, any, arginfo_class_Async_TaskGroup_any, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, getResults, arginfo_class_Async_TaskGroup_getResults, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, getErrors, arginfo_class_Async_TaskGroup_getErrors, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, suppressErrors, arginfo_class_Async_TaskGroup_suppressErrors, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, cancel, arginfo_class_Async_TaskGroup_cancel, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, seal, arginfo_class_Async_TaskGroup_seal, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, dispose, arginfo_class_Async_TaskGroup_dispose, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, isFinished, arginfo_class_Async_TaskGroup_isFinished, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, isSealed, arginfo_class_Async_TaskGroup_isSealed, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, count, arginfo_class_Async_TaskGroup_count, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, onFinally, arginfo_class_Async_TaskGroup_onFinally, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_TaskGroup, getIterator, arginfo_class_Async_TaskGroup_getIterator, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_TaskGroup(zend_class_entry *class_entry_Async_Awaitable, zend_class_entry *class_entry_Countable, zend_class_entry *class_entry_IteratorAggregate)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "TaskGroup", class_Async_TaskGroup_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);
	zend_class_implements(class_entry, 3, class_entry_Async_Awaitable, class_entry_Countable, class_entry_IteratorAggregate);

	return class_entry;
}
