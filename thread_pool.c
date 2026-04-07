/*
+----------------------------------------------------------------------+
  | Copyright (c) The PHP Group                                          |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | https://www.php.net/license/3_01.txt                                 |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Edmond                                                       |
  +----------------------------------------------------------------------+
*/

#include "thread_pool.h"
#include "thread_pool_arginfo.h"
#include "php_async.h"
#include "zend_interfaces.h"
#include "zend_exceptions.h"

zend_class_entry *async_ce_thread_pool = NULL;
zend_class_entry *async_ce_thread_pool_exception = NULL;

static zend_object_handlers thread_pool_handlers;

#define METHOD(name) PHP_METHOD(Async_ThreadPool, name)
#define THIS_POOL() ASYNC_THREAD_POOL_FROM_OBJ(Z_OBJ_P(ZEND_THIS))

///////////////////////////////////////////////////////////
/// Stub methods — TODO: full implementation
///////////////////////////////////////////////////////////

METHOD(__construct)
{
	zend_long workers;
	zend_long queue_size = 0;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_LONG(workers)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(queue_size)
	ZEND_PARSE_PARAMETERS_END();

	if (workers < 1) {
		zend_argument_value_error(1, "must be >= 1");
		RETURN_THROWS();
	}

	/* TODO: create pool */
}

METHOD(submit)
{
	zend_throw_exception(async_ce_thread_pool_exception, "ThreadPool not yet implemented", 0);
}

METHOD(map)
{
	zend_throw_exception(async_ce_thread_pool_exception, "ThreadPool not yet implemented", 0);
}

METHOD(close)
{
	zend_throw_exception(async_ce_thread_pool_exception, "ThreadPool not yet implemented", 0);
}

METHOD(cancel)
{
	zend_throw_exception(async_ce_thread_pool_exception, "ThreadPool not yet implemented", 0);
}

METHOD(isClosed)
{
	RETURN_FALSE;
}

METHOD(getPendingCount)
{
	RETURN_LONG(0);
}

METHOD(getRunningCount)
{
	RETURN_LONG(0);
}

METHOD(count)
{
	RETURN_LONG(0);
}

METHOD(getWorkerCount)
{
	RETURN_LONG(0);
}

void async_register_thread_pool_ce(void)
{
	async_ce_thread_pool = register_class_Async_ThreadPool(zend_ce_countable);
	async_ce_thread_pool_exception = register_class_Async_ThreadPoolException(zend_ce_exception);
}
