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
#ifndef COROUTINE_H
#define COROUTINE_H

#include <Zend/zend_async_API.h>

ZEND_STACK_ALIGNED void async_coroutine_execute(zend_fiber_transfer *transfer);
extern zend_class_entry * async_ce_coroutine;

typedef struct _async_coroutine_s async_coroutine_t;

struct _async_coroutine_s {

	/* Basic structure for coroutine. */
	zend_coroutine_t coroutine;

	/* Flags are defined in enum zend_fiber_flag. */
	uint8_t flags;

	/* Native C fiber context. */
	zend_fiber_context context;

	/* Current Zend VM execute data being run by the coroutine. */
	zend_execute_data *execute_data;

	/* Active fiber vm stack. */
	zend_vm_stack vm_stack;

	/* PHP object handle. */
	zend_object std;
};

void async_register_coroutine_ce(void);
zend_coroutine_t *new_coroutine(zend_async_scope_t *scope);
void async_coroutine_cleanup(zend_fiber_context *context);
void async_coroutine_finalize(zend_fiber_transfer *transfer, async_coroutine_t * coroutine);

#endif //COROUTINE_H
