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
/// Snapshot — package transferred from parent to child
///////////////////////////////////////////////////////////

/**
 * Arena block for bump allocator — all op_array data lives here.
 * Freed as a whole on snapshot destroy (no individual pefree needed).
 */
typedef struct _thread_copy_arena_block_t {
	struct _thread_copy_arena_block_t *prev;
	size_t size;
	size_t offset;
	char data[];  /* flexible array member */
} thread_copy_arena_block_t;

/**
 * A deep-copied closure: arena-allocated op_array + transferred bound variables.
 */
typedef struct _async_thread_closure_copy_t {
	zend_op_array *func;
	HashTable *bound_vars;   /* NULL if no captured variables (pemalloc, not arena) */
} async_thread_closure_copy_t;

typedef struct _async_thread_snapshot_t {
	/* Deep-copied entry closure */
	async_thread_closure_copy_t entry;
	/* Deep-copied bootloader closure (func == NULL if not provided) */
	async_thread_closure_copy_t bootloader;
	/* Arena block list head — all pemalloc'd op_array data */
	thread_copy_arena_block_t *arena_blocks;
} async_thread_snapshot_t;

/**
 * Create a snapshot: deep-copy entry closure + optional bootloader,
 * capture parent SAPI context.
 *
 * @param entry        The callable to execute in the child thread
 * @param bootloader   Optional bootloader callable (NULL if not provided)
 * @return Snapshot (caller owns, free with async_thread_snapshot_destroy)
 */
async_thread_snapshot_t *async_thread_snapshot_create(
	const zend_fcall_t *entry, const zend_fcall_t *bootloader);

/**
 * Free a snapshot and all its resources.
 */
void async_thread_snapshot_destroy(async_thread_snapshot_t *snapshot);

///////////////////////////////////////////////////////////
/// Thread lifecycle — PHP request in child thread
///////////////////////////////////////////////////////////

/**
 * Initialize TSRM for the child thread.
 * Must be called BEFORE any zend_try block (EG(bailout) is not available yet).
 * After this call, EG()/SG()/PG() macros work correctly.
 */
void async_thread_tsrm_init(void);

/**
 * Initialize PHP request in child thread.
 * Must be called after async_thread_tsrm_init().
 *
 * Performs: php_request_startup, post-startup fixups.
 *
 * @return SUCCESS or FAILURE
 */
int async_thread_request_startup(const async_thread_snapshot_t *snapshot);

/**
 * Shut down PHP request in child thread.
 * Performs php_request_shutdown only.
 * Caller must call ts_free_thread() separately after zend_end_try.
 */
void async_thread_request_shutdown(void);

/**
 * Create a callable Closure from a deep-copied closure.
 * Loads bound variables into current thread's emalloc.
 *
 * @param copy         The deep-copied closure (from snapshot)
 * @param closure_zv   Output: the created Closure zval
 */
void async_thread_create_closure(
	const async_thread_closure_copy_t *copy, zval *closure_zv);

/**
 * Thread entry point: run the snapshot's closures in a new PHP request.
 *
 * Handles the full lifecycle: request startup, bootloader, entry closure,
 * result/exception transfer, request shutdown, and parent notification.
 * Called from the reactor's OS thread callback.
 *
 * @param arg  Pointer to zend_async_thread_event_t (cast from void* for
 *             compatibility with OS thread entry signatures)
 */
void async_thread_run(void *arg);

/**
 * Load thread result/exception from persistent memory into parent's emalloc.
 * Sets ZEND_THREAD_F_RESULT_LOADED flag.
 */
void async_thread_load_result(zend_async_thread_event_t *event);

/* API-compatible wrappers (opaque void* signatures for function pointers) */
void *async_thread_snapshot_create_api(
	const zend_fcall_t *entry, const zend_fcall_t *bootloader);
void async_thread_snapshot_destroy_api(void *snapshot);

///////////////////////////////////////////////////////////
/// Zval transfer — copy runtime values between threads
///////////////////////////////////////////////////////////

/* thread_transfer_ctx_t is an alias for the Zend-level type */
typedef zend_async_thread_transfer_ctx_t thread_transfer_ctx_t;

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

/* Recursion helpers passed to zend_async_thread_pool_register() — expose
 * thread_transfer_zval_inner / thread_load_zval_inner / xlat_put / a lazy
 * defer-release list. Used by transfer_obj handlers in Zend core classes
 * (e.g. WeakReference, WeakMap) that need to deep-copy child zvals within
 * an existing ctx, share the xlat table with their caller, and defer the
 * release of locally-created temporaries until the load ctx is torn down. */
void async_thread_transfer_zval_ctx(
	zend_async_thread_transfer_ctx_t *ctx, zval *dst, const zval *src);
void async_thread_load_zval_ctx(
	zend_async_thread_transfer_ctx_t *ctx, zval *dst, const zval *src);
void async_thread_xlat_put_ctx(
	zend_async_thread_transfer_ctx_t *ctx, const void *src, void *dst);
void async_thread_defer_release_ctx(
	zend_async_thread_transfer_ctx_t *ctx, zval *z);

///////////////////////////////////////////////////////////
/// Thread exceptions
///////////////////////////////////////////////////////////

PHP_ASYNC_API extern zend_class_entry *async_ce_remote_exception;
PHP_ASYNC_API extern zend_class_entry *async_ce_thread_transfer_exception;

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
