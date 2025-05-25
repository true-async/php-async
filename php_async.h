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
#ifndef PHP_ASYNC_H
#define PHP_ASYNC_H

#include <php.h>

#ifdef PHP_ASYNC_LIBUV
#ifdef PHP_WIN32
#include "libuv/uv.h"
#else
#include <uv.h>
#endif
#endif

#include "coroutine.h"
#include "internal/circular_buffer.h"

#ifdef PHP_WIN32

#else

#endif

extern zend_module_entry async_module_entry;
#define phpext_async_ptr &async_module_entry

extern zend_class_entry * async_ce_awaitable;
extern zend_class_entry * async_ce_timeout;

#define PHP_ASYNC_NAME "TrueAsync"
#define PHP_ASYNC_VERSION "0.5.0"
#define PHP_ASYNC_NAME_VERSION "TrueAsync v0.5.0"

typedef struct
{
	zend_async_event_t event;
	/* Reactor original handle */
	zend_async_event_dispose_t reactor_dispose;
	zend_object std;
} async_timeout_t;

#define ASYNC_TIMEOUT_FROM_OBJ(obj) ((async_timeout_t *)((char *)(obj) - XtOffsetOf(async_timeout_t, std)))
#define Z_ASYNC_TIMEOUT_P(zv)  ASYNC_TIMEOUT_FROM_OBJ(Z_OBJ_P(zv))

ZEND_BEGIN_MODULE_GLOBALS(async)
	// Microtask queue
	circular_buffer_t microtasks;
	/* Queue of coroutine_queue */
	circular_buffer_t coroutine_queue;
	/* List of coroutines  */
	HashTable coroutines;
	/* The transfer structure is used to return to the main execution context. */
	zend_fiber_transfer *main_transfer;
	/* The main flow stack */
	zend_vm_stack main_vm_stack;

#ifdef PHP_ASYNC_LIBUV
	/* The reactor */
	uv_loop_t uvloop;
	bool reactor_started;
#ifdef PHP_WIN32
	uv_thread_t * watcherThread;
	HANDLE ioCompletionPort;
	unsigned int countWaitingDescriptors;
	bool isRunning;
	uv_async_t * uvloop_wakeup;
	/* Circular buffer of libuv_process_t ptr */
	circular_buffer_t * pid_queue;
#endif
#endif

	#ifdef PHP_WIN32
	#endif
ZEND_END_MODULE_GLOBALS(async)

ZEND_EXTERN_MODULE_GLOBALS(async)

#define ASYNC_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(async, v)
#define ASYNC_GLOBALS ZEND_MODULE_GLOBALS_BULK(async)

# if defined(ZTS) && defined(COMPILE_DL_ASYNC)
ZEND_TSRMLS_CACHE_EXTERN()
# endif

#endif //ASYNC_H