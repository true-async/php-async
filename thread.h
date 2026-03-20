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
 * A deep-copied closure: pemalloc'd op_array + transferred bound variables.
 */
typedef struct _async_thread_closure_copy_t {
	zend_op_array *func;
	HashTable *bound_vars;   /* NULL if no captured variables */
} async_thread_closure_copy_t;

typedef struct _async_thread_snapshot_t {
	/* Deep-copied entry closure */
	async_thread_closure_copy_t entry;
	/* Deep-copied bootloader closure (func == NULL if not provided) */
	async_thread_closure_copy_t bootloader;
	/* Autoloader callables (transferred zval array) */
	zval autoload_functions;
	/* All pemalloc'd pointers from deep copy: old_ptr → new_ptr.
	 * Used for deduplication during copy and bulk pefree on destroy. */
	HashTable persistent_map;
} async_thread_snapshot_t;

/**
 * Create a snapshot: deep-copy entry closure + optional bootloader + autoloaders.
 * Must be called from the parent thread before spawning the child.
 *
 * @param entry        The callable to execute in the child thread
 * @param bootloader   Optional bootloader callable (NULL if not provided)
 * @return Snapshot structure (caller owns, must call async_thread_snapshot_destroy)
 */
async_thread_snapshot_t *async_thread_snapshot_create(const zend_fcall_t *entry, const zend_fcall_t *bootloader);

/**
 * Load a snapshot into the current thread: register autoloaders.
 * Must be called from the child thread after php_request_startup().
 */
void async_thread_snapshot_load(const async_thread_snapshot_t *snapshot);

/**
 * Free a snapshot and all its resources.
 */
void async_thread_snapshot_destroy(async_thread_snapshot_t *snapshot);

///////////////////////////////////////////////////////////
/// Zval transfer — copy runtime values between threads
///////////////////////////////////////////////////////////

/**
 * Copy a zval into persistent memory for cross-thread transfer.
 * Deep copies strings, arrays, and objects. Preserves identity
 * (shared references → shared copies) and handles cycles.
 */
void async_thread_transfer_zval(zval *dst, const zval *src);

/**
 * Load a persistent zval into the current thread's emalloc heap.
 * Creates proper refcounted copies owned by the calling thread.
 */
void async_thread_load_zval(zval *dst, const zval *src);

/**
 * Free a persistent zval created by async_thread_transfer_zval().
 */
void async_thread_release_transferred_zval(zval *z);

///////////////////////////////////////////////////////////
/// Thread PHP object — Async\Thread class
///////////////////////////////////////////////////////////

typedef struct _async_thread_object_s async_thread_object_t;

PHP_ASYNC_API extern zend_class_entry *async_ce_thread;

struct _async_thread_object_s
{
	/* Event reference — allows ZEND_ASYNC_OBJECT_TO_EVENT() to
	 * resolve from this object to the thread event pointer.
	 * Must be at offset 0 from the struct start (= handlers->offset from zend_object). */
	ZEND_ASYNC_EVENT_REF_FIELDS

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
