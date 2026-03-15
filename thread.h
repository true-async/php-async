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
#ifndef ASYNC_THREAD_H
#define ASYNC_THREAD_H

#include "php_async_api.h"
#include <Zend/zend_async_API.h>

///////////////////////////////////////////////////////////
/// Snapshot — transfer compiled code between threads
///////////////////////////////////////////////////////////

/**
 * Snapshot of a thread's code tables, used to transfer compiled code
 * from a parent thread to a child thread.
 *
 * With OPcache: function/class entries point into SHM (ZEND_ACC_IMMUTABLE),
 *               so we only store pointers (shallow copy).
 * Without OPcache: TODO deep copy via adapted zend_persist.c logic.
 */
typedef struct _async_thread_snapshot_t {
	/* User-defined functions (key → zend_function*) */
	HashTable function_table;
	/* User-defined classes (key → zend_class_entry*) */
	HashTable class_table;
	/* Number of internal functions in parent CG(function_table) at snapshot time.
	 * Used to skip internal entries during iteration. */
	uint32_t copied_functions_count;
	/* Autoloader callables (zval array of callables) */
	zval autoload_functions;
	/* Included files list */
	HashTable included_files;
} async_thread_snapshot_t;

/**
 * Create a snapshot of the current thread's user code tables.
 * Must be called from the parent thread before spawning the child.
 *
 * @param inherit  If true, copy function/class tables. If false, only autoloaders.
 * @return Snapshot structure (caller owns, must call async_thread_snapshot_destroy)
 */
async_thread_snapshot_t *async_thread_snapshot_create(bool inherit);

/**
 * Load a snapshot into the current thread's CG(function_table) and CG(class_table).
 * Must be called from the child thread after TSRM initialization.
 *
 * @param snapshot  The snapshot to load (not consumed, caller still owns it)
 */
void async_thread_snapshot_load(const async_thread_snapshot_t *snapshot);

/**
 * Free a snapshot and all its resources.
 */
void async_thread_snapshot_destroy(async_thread_snapshot_t *snapshot);

///////////////////////////////////////////////////////////
/// Thread PHP object — Async\Thread class
///////////////////////////////////////////////////////////

typedef struct _async_thread_object_s async_thread_object_t;

PHP_ASYNC_API extern zend_class_entry *async_ce_thread;

struct _async_thread_object_s
{
	/* Event reference prolog — allows ZEND_ASYNC_OBJECT_TO_EVENT() to
	 * resolve from this object to the thread event pointer.
	 * Must be at offset 0 from the struct start (= handlers->offset from zend_object). */
	ZEND_ASYNC_EVENT_REF_PROLOG

	/* Pointer to the underlying thread event */
	zend_async_thread_event_t *thread_event;

	/* Finally handlers array (zval callables) - lazy initialization */
	HashTable *finally_handlers;

	/* PHP object handle — must be last */
	zend_object std;
};

void async_register_thread_ce(void);

static zend_always_inline async_thread_object_t *async_thread_object_from_obj(zend_object *obj)
{
	return (async_thread_object_t *) ((char *) obj - XtOffsetOf(async_thread_object_t, std));
}

#define Z_ASYNC_THREAD_P(zv) async_thread_object_from_obj(Z_OBJ_P(zv))

#endif /* ASYNC_THREAD_H */
