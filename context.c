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
#include "context.h"

#include "exceptions.h"

//////////////////////////////////////////////////////////////////////
/// Context API Implementation
//////////////////////////////////////////////////////////////////////

bool async_context_find(async_context_t * context, zval *key, zval *result)
{
	zval *found = NULL;

	if (Z_TYPE_P(key) == IS_STRING) {
		found = zend_hash_find(&context->values, Z_STR_P(key));
	} else if (Z_TYPE_P(key) == IS_OBJECT) {
		found = zend_hash_index_find(&context->values, Z_OBJ_P(key)->handle);
	} else {
		async_throw_error("Context key must be a string or an object");
		ZVAL_NULL(result);
		return false;
	}

	if (found != NULL) {
		ZVAL_COPY(result, found);
		return true;
	}

	ZVAL_NULL(result);
	return false;
}

bool async_context_set(async_context_t * context, zval *key, zval *value)
{
	if (Z_TYPE_P(key) == IS_STRING) {
		// String key
		zend_hash_update(&context->values, Z_STR_P(key), value);
		Z_TRY_ADDREF_P(value);
	} else if (Z_TYPE_P(key) == IS_OBJECT) {
		// Object key - use object handle as hash key
		zend_hash_index_update(&context->values, Z_OBJ_P(key)->handle, value);
		Z_TRY_ADDREF_P(value);

		// Store object reference to keep it alive
		zend_hash_index_update(&context->keys, Z_OBJ_P(key)->handle, key);
		Z_TRY_ADDREF_P(key);
	} else {
		async_throw_error("Context key must be a string or an object");
		return false;
	}

	return true;
}

bool async_context_has(async_context_t * context, zval *key)
{
	if (Z_TYPE_P(key) == IS_STRING) {
		// String key
		return zend_hash_exists(&context->values, Z_STR_P(key));
	} else if (Z_TYPE_P(key) == IS_OBJECT) {
		// Object key
		return zend_hash_index_exists(&context->values, Z_OBJ_P(key)->handle);
	}

	return false;
}

bool async_context_delete(async_context_t * context, zval *key)
{
	bool deleted = false;

	if (Z_TYPE_P(key) == IS_STRING) {
		// String key
		deleted = (zend_hash_del(&context->values, Z_STR_P(key)) == SUCCESS);
	} else if (Z_TYPE_P(key) == IS_OBJECT) {
		// Object key
		deleted = (zend_hash_index_del(&context->values, Z_OBJ_P(key)->handle) == SUCCESS);

		if (deleted) {
			// Also remove from object keys storage
			zend_hash_index_del(&context->keys, Z_OBJ_P(key)->handle);
		}
	}

	return deleted;
}

async_context_t *async_context_create(zend_async_context_t *parent_context)
{
	async_context_t *context = emalloc(sizeof(async_context_t));
	
	// Initialize hash tables directly
	zend_hash_init(&context->values, 8, NULL, ZVAL_PTR_DTOR, 0);
	zend_hash_init(&context->keys, 8, NULL, ZVAL_PTR_DTOR, 0);
	
	// Initialize base context function pointers
	context->base.find = (zend_async_context_find_t)async_context_find;
	context->base.set = (zend_async_context_set_t)async_context_set;
	context->base.unset = (zend_async_context_unset_t)async_context_delete;
	context->base.dispose = (zend_async_context_dispose_t)async_context_dispose;
	context->base.offset = offsetof(async_context_t, std);
	
	// Initialize std object
	zend_object_std_init(&context->std, NULL);
	
	return context;
}

void async_context_dispose(async_context_t *context)
{
	if (context == NULL) {
		return;
	}
	
	// Destroy hash tables
	zend_hash_destroy(&context->values);
	zend_hash_destroy(&context->keys);
	
	// Free the context structure
	efree(context);
}