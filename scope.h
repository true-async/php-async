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
			bool is_cancelled; /* Indicates if the scope is cancelled */
		};
	};
} async_scope_object_t;

typedef struct {
	zend_coroutine_event_callback_t callback;
	zend_fcall_info *error_fci;
	zend_fcall_info_cache *error_fci_cache;
} scope_coroutine_callback_t;

zend_async_scope_t * async_new_scope(zend_async_scope_t * parent_scope, const bool with_zend_object);
void async_register_scope_ce(void);

/* Check if coroutine belongs to this scope or any of its child scopes */
bool async_scope_contains_coroutine(async_scope_t *scope, zend_coroutine_t *coroutine, uint32_t depth);

void async_scope_notify_coroutine_finished(async_coroutine_t *coroutine);

/* Mark coroutine as zombie and update active count */
void async_scope_mark_coroutine_zombie(async_coroutine_t *coroutine);

#endif //SCOPE_H
