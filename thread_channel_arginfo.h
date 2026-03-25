/* This is a generated file, edit thread_channel.stub.php instead.
 * Stub hash: 9ce6bb2923998be923492ffcf9b40054d6a0b3fb */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_ThreadChannel___construct, 0, 0, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, capacity, IS_LONG, 0, "16")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_ThreadChannel_send, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellationToken, Async\\Completable, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_ThreadChannel_recv, 0, 0, IS_MIXED, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellationToken, Async\\Completable, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_ThreadChannel_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_ThreadChannel_isClosed, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_ThreadChannel_capacity, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_ThreadChannel_count arginfo_class_Async_ThreadChannel_capacity

#define arginfo_class_Async_ThreadChannel_isEmpty arginfo_class_Async_ThreadChannel_isClosed

#define arginfo_class_Async_ThreadChannel_isFull arginfo_class_Async_ThreadChannel_isClosed

ZEND_METHOD(Async_ThreadChannel, __construct);
ZEND_METHOD(Async_ThreadChannel, send);
ZEND_METHOD(Async_ThreadChannel, recv);
ZEND_METHOD(Async_ThreadChannel, close);
ZEND_METHOD(Async_ThreadChannel, isClosed);
ZEND_METHOD(Async_ThreadChannel, capacity);
ZEND_METHOD(Async_ThreadChannel, count);
ZEND_METHOD(Async_ThreadChannel, isEmpty);
ZEND_METHOD(Async_ThreadChannel, isFull);

static const zend_function_entry class_Async_ThreadChannel_methods[] = {
	ZEND_ME(Async_ThreadChannel, __construct, arginfo_class_Async_ThreadChannel___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadChannel, send, arginfo_class_Async_ThreadChannel_send, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadChannel, recv, arginfo_class_Async_ThreadChannel_recv, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadChannel, close, arginfo_class_Async_ThreadChannel_close, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadChannel, isClosed, arginfo_class_Async_ThreadChannel_isClosed, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadChannel, capacity, arginfo_class_Async_ThreadChannel_capacity, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadChannel, count, arginfo_class_Async_ThreadChannel_count, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadChannel, isEmpty, arginfo_class_Async_ThreadChannel_isEmpty, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_ThreadChannel, isFull, arginfo_class_Async_ThreadChannel_isFull, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_ThreadChannelException(zend_class_entry *class_entry_Async_AsyncException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "ThreadChannelException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Async_AsyncException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_ThreadChannel(zend_class_entry *class_entry_Async_Awaitable, zend_class_entry *class_entry_Countable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "ThreadChannel", class_Async_ThreadChannel_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);
	zend_class_implements(class_entry, 2, class_entry_Async_Awaitable, class_entry_Countable);

	return class_entry;
}
