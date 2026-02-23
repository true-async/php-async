/* This is a generated file, edit channel.stub.php instead.
 * Stub hash: efd35c2b09db63fda28eb831273d0be269aa58d4 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Async_Channel___construct, 0, 0, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, capacity, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Channel_send, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellationToken, Async\\Completable, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Channel_sendAsync, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Channel_recv, 0, 0, IS_MIXED, 0)
	ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancellationToken, Async\\Completable, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Channel_recvAsync, 0, 0, Async\\Future, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Channel_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Channel_isClosed, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Async_Channel_capacity, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Async_Channel_count arginfo_class_Async_Channel_capacity

#define arginfo_class_Async_Channel_isEmpty arginfo_class_Async_Channel_isClosed

#define arginfo_class_Async_Channel_isFull arginfo_class_Async_Channel_isClosed

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Async_Channel_getIterator, 0, 0, Iterator, 0)
ZEND_END_ARG_INFO()

ZEND_METHOD(Async_Channel, __construct);
ZEND_METHOD(Async_Channel, send);
ZEND_METHOD(Async_Channel, sendAsync);
ZEND_METHOD(Async_Channel, recv);
ZEND_METHOD(Async_Channel, recvAsync);
ZEND_METHOD(Async_Channel, close);
ZEND_METHOD(Async_Channel, isClosed);
ZEND_METHOD(Async_Channel, capacity);
ZEND_METHOD(Async_Channel, count);
ZEND_METHOD(Async_Channel, isEmpty);
ZEND_METHOD(Async_Channel, isFull);
ZEND_METHOD(Async_Channel, getIterator);

static const zend_function_entry class_Async_Channel_methods[] = {
	ZEND_ME(Async_Channel, __construct, arginfo_class_Async_Channel___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Channel, send, arginfo_class_Async_Channel_send, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Channel, sendAsync, arginfo_class_Async_Channel_sendAsync, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Channel, recv, arginfo_class_Async_Channel_recv, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Channel, recvAsync, arginfo_class_Async_Channel_recvAsync, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Channel, close, arginfo_class_Async_Channel_close, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Channel, isClosed, arginfo_class_Async_Channel_isClosed, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Channel, capacity, arginfo_class_Async_Channel_capacity, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Channel, count, arginfo_class_Async_Channel_count, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Channel, isEmpty, arginfo_class_Async_Channel_isEmpty, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Channel, isFull, arginfo_class_Async_Channel_isFull, ZEND_ACC_PUBLIC)
	ZEND_ME(Async_Channel, getIterator, arginfo_class_Async_Channel_getIterator, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_Async_ChannelException(zend_class_entry *class_entry_Async_AsyncException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "ChannelException", NULL);
	class_entry = zend_register_internal_class_with_flags(&ce, class_entry_Async_AsyncException, 0);

	return class_entry;
}

static zend_class_entry *register_class_Async_Channel(zend_class_entry *class_entry_Async_Awaitable, zend_class_entry *class_entry_IteratorAggregate, zend_class_entry *class_entry_Countable)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "Async", "Channel", class_Async_Channel_methods);
	class_entry = zend_register_internal_class_with_flags(&ce, NULL, ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE);
	zend_class_implements(class_entry, 3, class_entry_Async_Awaitable, class_entry_IteratorAggregate, class_entry_Countable);

	return class_entry;
}
