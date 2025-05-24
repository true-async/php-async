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

typedef struct _async_scope_s async_scope_t;

typedef struct async_coroutines_vector_t {
	uint32_t                      length;    /* current number of items      */
	uint32_t                      capacity;  /* allocated slots in the array */
	async_coroutine_t			  **data;    /* dynamically allocated array	 */
} async_coroutines_vector_t;

struct _async_scope_s {
	zend_async_scope_t scope;
	async_coroutines_vector_t coroutines;
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


static zend_always_inline void async_scope_try_dispose(async_scope_t *scope)
{
	if (scope->scope.scopes.length == 0 && scope->coroutines.length == 0) {
		scope->scope.dispose(&scope->scope);
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
}

static zend_always_inline void
async_scope_remove_coroutine(async_scope_t *scope, async_coroutine_t *coroutine)
{
	async_coroutines_vector_t *vector = &scope->coroutines;
	for (uint32_t i = 0; i < vector->length; ++i) {
		if (vector->data[i] == coroutine) {
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

zend_async_scope_t * async_new_scope(zend_async_scope_t * parent_scope);
void async_register_scope_ce(void);

#endif //SCOPE_H
