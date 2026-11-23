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
#ifndef SCOPE_H
#define SCOPE_H

#include <Zend/zend_hrtime.h>
#include "php_async.h"

PHP_ASYNC_API extern zend_class_entry *async_ce_scope;
PHP_ASYNC_API extern zend_class_entry *async_ce_scope_provider;
PHP_ASYNC_API extern zend_class_entry *async_ce_spawn_strategy;

#define ASYNC_SCOPE_MAX_RECURSION_DEPTH 64

typedef struct _async_scope_s async_scope_t;

typedef struct async_coroutines_vector_t
{
	uint32_t length;          /* current number of items      */
	uint32_t capacity;        /* allocated slots in the array */
	async_coroutine_t **data; /* dynamically allocated array	 */
} async_coroutines_vector_t;

struct _async_scope_s
{
	zend_async_scope_t scope;
	async_coroutines_vector_t coroutines;
	uint32_t active_coroutines_count; /* Number of active (non-zombie) coroutines */
	uint32_t zombie_coroutines_count; /* Number of zombie coroutines */

	/* Spawned file and line number */
	zend_string *filename;
	uint32_t lineno;

	/* Exception handlers */
	zend_fcall_info *exception_fci;
	zend_fcall_info_cache *exception_fcc;
	zend_fcall_info *child_exception_fci;
	zend_fcall_info_cache *child_exception_fcc;

	/* Finally handlers array (zval callables) - lazy initialization */
	HashTable *finally_handlers;

	/* Timer of the last disposeAfterTimeout(), NULL while none runs. It holds
	 * no reference to the scope: the scope stops it when it runs empty or is
	 * freed, so a finished scope does not keep the event loop alive. */
	zend_async_event_t *dispose_timer;
	/* That call's deadline in zend_hrtime() nanoseconds, 0 for none. Kept
	 * while the scope is empty: a coroutine spawned into it later restarts
	 * the timer for what is left. */
	zend_hrtime_t dispose_deadline;
};

typedef struct _async_scope_object_s
{
	union
	{
		/* PHP object handle. */
		zend_object std;

		struct
		{
			char _padding[sizeof(zend_object) - sizeof(zval)];
			async_scope_t *scope;
			bool is_cancelled; /* Indicates if the scope is cancelled */
		};
	};
} async_scope_object_t;

typedef struct
{
	zend_coroutine_event_callback_t callback;
	zend_fcall_info *error_fci;
	zend_fcall_info_cache *error_fci_cache;
} scope_coroutine_callback_t;

zend_async_scope_t *async_new_scope(zend_async_scope_t *parent_scope, const bool with_zend_object);
void async_register_scope_ce(void);

/* Check if coroutine belongs to this scope or any of its child scopes */
bool async_scope_contains_coroutine(async_scope_t *scope, zend_coroutine_t *coroutine, uint32_t depth);

/* C core of Scope::awaitAfterCancellation (see scope.c). */
void async_scope_await_after_cancellation(
		zend_async_scope_t *scope, zend_coroutine_t *awaiter,
		zend_fcall_info *error_fci, zend_fcall_info_cache *error_fci_cache,
		zend_async_event_t *cancellation);

/* Suspends `awaiter` until no coroutine is left in the scope tree, `cancellation` fires or
 * `timeout_ms` passes (0: no limit). Returns false when it did not wait: the scope is closed
 * or empty, `awaiter` belongs to it (an exception is thrown), or the suspend failed.
 * Terminates nothing. */
bool async_scope_await_empty(
		zend_async_scope_t *scope, zend_coroutine_t *awaiter,
		zend_fcall_info *error_fci, zend_fcall_info_cache *error_fci_cache,
		zend_async_event_t *cancellation, const zend_ulong timeout_ms);

/* The await of a cancelled scope behind the C API (ZEND_ASYNC_SCOPE_AWAIT_AFTER_CANCELLATION).
 * With a `cancellation` token it waits as Scope::awaitAfterCancellation() does. Without one
 * it drains: after each async.shutdown_grace it terminates what is left with
 * async_coroutine_terminate() (finally blocks skipped), and returns once the scope tree is
 * empty, the scope is closed or a wait fails. A cancellation the awaiter receives meanwhile
 * does not stop the drain and is rethrown at the end. A grace of 0 or less waits without
 * terminating. */
void async_scope_drain_after_cancellation(
		zend_async_scope_t *scope, zend_coroutine_t *awaiter,
		zend_fcall_info *error_fci, zend_fcall_info_cache *error_fci_cache,
		zend_async_event_t *cancellation);

void async_scope_notify_coroutine_finished(async_coroutine_t *coroutine);

/* Mark coroutine as zombie and update active count */
void async_scope_mark_coroutine_zombie(async_coroutine_t *coroutine);

#endif // SCOPE_H
