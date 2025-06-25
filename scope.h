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

#include "php_async.h"

extern zend_class_entry * async_ce_scope;
extern zend_class_entry * async_ce_scope_provider;
extern zend_class_entry * async_ce_spawn_strategy;

#define ASYNC_SCOPE_MAX_RECURSION_DEPTH 64

typedef struct _async_scope_s async_scope_t;

typedef struct async_coroutines_vector_t {
	uint32_t                      length;    /* current number of items      */
	uint32_t                      capacity;  /* allocated slots in the array */
	async_coroutine_t			  **data;    /* dynamically allocated array	 */
} async_coroutines_vector_t;

struct _async_scope_s {
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
};

typedef struct _async_scope_object_s {
	union
	{
		/* PHP object handle. */
		zend_object std;
		struct {
			char _padding[sizeof(zend_object) - sizeof(zval)];
			async_scope_t *scope;
		};
	};
} async_scope_object_t;

typedef struct {
	zend_coroutine_event_callback_t callback;
	zend_fcall_info *error_fci;
	zend_fcall_info_cache *error_fci_cache;
} scope_coroutine_callback_t;

static zend_always_inline void async_scope_try_dispose(async_scope_t *scope)
{
	if (scope->scope.scopes.length == 0 && scope->coroutines.length == 0) {
		scope->scope.event.dispose(&scope->scope.event);
	}
}

static zend_always_inline void
async_scope_add_coroutine(async_scope_t *scope, async_coroutine_t *coroutine)
{
	async_coroutines_vector_t *vector = &scope->coroutines;

	if (vector->data == NULL) {
		vector->data = safe_emalloc(4, sizeof(async_coroutine_t *), 0);
		vector->capacity = 4;
	}

	if (vector->length == vector->capacity) {
		vector->capacity *= 2;
		vector->data = safe_erealloc(vector->data, vector->capacity, sizeof(async_coroutine_t *), 0);
	}

	vector->data[vector->length++] = coroutine;
	coroutine->coroutine.scope = &scope->scope;
	
	// Increment active coroutines count if coroutine is not zombie
	if (!ZEND_COROUTINE_IS_ZOMBIE(&coroutine->coroutine)) {
		scope->active_coroutines_count++;
	}
}

static zend_always_inline void
async_scope_remove_coroutine(async_scope_t *scope, async_coroutine_t *coroutine)
{
	async_coroutines_vector_t *vector = &scope->coroutines;
	for (uint32_t i = 0; i < vector->length; ++i) {
		if (vector->data[i] == coroutine) {
			// Decrement active coroutines count if coroutine was active
			if (false == ZEND_COROUTINE_IS_ZOMBIE(&coroutine->coroutine)) {
				if (scope->active_coroutines_count > 0) {
					scope->active_coroutines_count--;
				}
			}
			
			vector->data[i] = vector->data[--vector->length];
			async_scope_try_dispose(scope);
			return;
		}
	}
}

static zend_always_inline void
async_scope_free_coroutines(async_scope_t *scope)
{
	async_coroutines_vector_t *vector = &scope->coroutines;

	if (vector->data != NULL) {
		efree(vector->data);
	}

	vector->data = NULL;
	vector->length = 0;
	vector->capacity = 0;
}

zend_async_scope_t * async_new_scope(zend_async_scope_t * parent_scope, const bool with_zend_object);
void async_register_scope_ce(void);

/* Check if coroutine belongs to this scope or any of its child scopes */
bool async_scope_contains_coroutine(async_scope_t *scope, zend_coroutine_t *coroutine, uint32_t depth);

bool async_scope_try_to_handle_exception(async_coroutine_t *coroutine, zend_object *exception);

void async_scope_notify_coroutine_finished(async_coroutine_t *coroutine);

/* Mark coroutine as zombie and update active count */
void async_scope_mark_coroutine_zombie(async_coroutine_t *coroutine);

#endif //SCOPE_H
