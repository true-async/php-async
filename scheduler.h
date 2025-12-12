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
#ifndef PHP_SCHEDULER_H
#define PHP_SCHEDULER_H

#include <Zend/zend_fibers.h>

/* Fiber context pool configuration */
#define ASYNC_FIBER_POOL_SIZE 4

BEGIN_EXTERN_C()

void async_scheduler_startup(void);
void async_scheduler_shutdown(void);

bool start_graceful_shutdown(void);

bool async_scheduler_launch(void);
/**
 * A function that is called when control needs to be transferred from a coroutine to the Scheduler.
 * In reality, no context switch occurs.
 * The Scheduler's logic runs directly within the coroutine that called suspend.
 */
bool async_scheduler_coroutine_suspend(void);
bool async_scheduler_main_coroutine_suspend(void);
bool async_scheduler_coroutine_enqueue(zend_coroutine_t *coroutine);

/* Fiber context creation */
async_fiber_context_t *async_fiber_context_create(void);

END_EXTERN_C()

#endif // PHP_SCHEDULER_H
