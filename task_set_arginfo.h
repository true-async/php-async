/* This is a generated file, edit task_set.stub.php instead.
 * Stub hash: task_set_arginfo */

/* Reuse arginfo definitions from TaskGroup — identical signatures */
#define arginfo_class_Async_TaskSet___construct     arginfo_class_Async_TaskGroup___construct
#define arginfo_class_Async_TaskSet_spawn           arginfo_class_Async_TaskGroup_spawn
#define arginfo_class_Async_TaskSet_spawnWithKey    arginfo_class_Async_TaskGroup_spawnWithKey
#define arginfo_class_Async_TaskSet_joinNext        arginfo_class_Async_TaskGroup_race
#define arginfo_class_Async_TaskSet_joinAny         arginfo_class_Async_TaskGroup_any
#define arginfo_class_Async_TaskSet_joinAll         arginfo_class_Async_TaskGroup_all
#define arginfo_class_Async_TaskSet_cancel          arginfo_class_Async_TaskGroup_cancel
#define arginfo_class_Async_TaskSet_seal            arginfo_class_Async_TaskGroup_seal
#define arginfo_class_Async_TaskSet_dispose         arginfo_class_Async_TaskGroup_dispose
#define arginfo_class_Async_TaskSet_isFinished      arginfo_class_Async_TaskGroup_isFinished
#define arginfo_class_Async_TaskSet_isSealed        arginfo_class_Async_TaskGroup_isSealed
#define arginfo_class_Async_TaskSet_count           arginfo_class_Async_TaskGroup_count
#define arginfo_class_Async_TaskSet_awaitCompletion arginfo_class_Async_TaskGroup_awaitCompletion
#define arginfo_class_Async_TaskSet_finally         arginfo_class_Async_TaskGroup_finally
#define arginfo_class_Async_TaskSet_getIterator     arginfo_class_Async_TaskGroup_getIterator

/* Map TaskSet methods to TaskGroup implementations via ZEND_MALIAS */
static const zend_function_entry class_Async_TaskSet_methods[] = {
	ZEND_MALIAS(Async_TaskGroup, __construct, __construct, arginfo_class_Async_TaskSet___construct, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, spawn, spawn, arginfo_class_Async_TaskSet_spawn, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, spawnWithKey, spawnWithKey, arginfo_class_Async_TaskSet_spawnWithKey, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, joinNext, race, arginfo_class_Async_TaskSet_joinNext, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, joinAny, any, arginfo_class_Async_TaskSet_joinAny, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, joinAll, all, arginfo_class_Async_TaskSet_joinAll, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, cancel, cancel, arginfo_class_Async_TaskSet_cancel, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, seal, seal, arginfo_class_Async_TaskSet_seal, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, dispose, dispose, arginfo_class_Async_TaskSet_dispose, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, isFinished, isFinished, arginfo_class_Async_TaskSet_isFinished, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, isSealed, isSealed, arginfo_class_Async_TaskSet_isSealed, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, count, count, arginfo_class_Async_TaskSet_count, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, awaitCompletion, awaitCompletion, arginfo_class_Async_TaskSet_awaitCompletion, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, finally, finally, arginfo_class_Async_TaskSet_finally, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(Async_TaskGroup, getIterator, getIterator, arginfo_class_Async_TaskSet_getIterator, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_TaskSet(zend_class_entry *class_entry_Async_Awaitable, zend_class_entry *class_entry_Countable, zend_class_entry *class_entry_IteratorAggregate)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "TaskSet", class_Async_TaskSet_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);
	zend_class_implements(class_entry, 3, class_entry_Async_Awaitable, class_entry_Countable, class_entry_IteratorAggregate);

	return class_entry;
}
