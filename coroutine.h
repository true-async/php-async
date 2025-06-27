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

	/* deferred cancellation object. */
	zend_object * deferred_cancellation;

	/* Active fiber vm stack. */
	zend_vm_stack vm_stack;

	/* Finally handlers array (zval callables) - lazy initialization */
	HashTable *finally_handlers;

	/* PHP object handle. */
	zend_object std;
};

typedef struct _finally_handlers_context_s finally_handlers_context_t;

// Structure for finally handlers context
struct _finally_handlers_context_s {
	union {
		void *target;
		async_coroutine_t *coroutine;
	};
	zend_async_scope_t *scope;
	HashTable *finally_handlers;
	zend_object *composite_exception;
	void (*dtor)(finally_handlers_context_t *context);
	uint32_t params_count;
	zval params[1];
};

void async_register_coroutine_ce(void);
zend_coroutine_t *async_new_coroutine(zend_async_scope_t *scope);
void async_coroutine_cleanup(zend_fiber_context *context);
void async_coroutine_finalize(zend_fiber_transfer *transfer, async_coroutine_t * coroutine);
void async_coroutine_finalize_from_scheduler(async_coroutine_t * coroutine);
void async_coroutine_suspend(const bool from_main);
void async_coroutine_resume(zend_coroutine_t *coroutine, zend_object * error, const bool transfer_error);
void async_coroutine_cancel(zend_coroutine_t *zend_coroutine, zend_object *error, bool transfer_error, const bool is_safely);
bool async_coroutine_context_set(zend_coroutine_t * z_coroutine, zval *key, zval *value);
bool async_coroutine_context_get(zend_coroutine_t * z_coroutine, zval *key, zval *result);
bool async_coroutine_context_has(zend_coroutine_t * z_coroutine, zval *key);
bool async_coroutine_context_delete(zend_coroutine_t * z_coroutine, zval *key);
bool async_call_finally_handlers(HashTable *finally_handlers, finally_handlers_context_t *context, int32_t priority);

#endif //COROUTINE_H
