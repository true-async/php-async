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
#include "context_arginfo.h"

//////////////////////////////////////////////////////////////////////
/// Context API Implementation
//////////////////////////////////////////////////////////////////////

bool async_context_find(async_context_t * context, zval *key, zval *result)
{
	// First try to find in current context
	if (async_context_find_local(context, key, result)) {
		return true;
	}

	// If not found and parent exists, search in parent
	if (context->parent != NULL) {
		return async_context_find(context->parent, key, result);
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
	// First check current context
	if (async_context_has_local(context, key)) {
		return true;
	}

	// If not found and parent exists, check parent
	if (context->parent != NULL) {
		return async_context_has(context->parent, key);
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
	} else {
		async_throw_error("Context key must be a string or an object");
		return false;
	}

	return deleted;
}

bool async_context_find_local(async_context_t * context, zval *key, zval *result)
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

bool async_context_has_local(async_context_t * context, zval *key)
{
	if (Z_TYPE_P(key) == IS_STRING) {
		// String key
		return zend_hash_exists(&context->values, Z_STR_P(key));
	} else if (Z_TYPE_P(key) == IS_OBJECT) {
		// Object key
		return zend_hash_index_exists(&context->values, Z_OBJ_P(key)->handle);
	} else {
		async_throw_error("Context key must be a string or an object");
		return false;
	}
}

async_context_t *async_context_create(async_context_t *parent_context)
{
	async_context_t *context = emalloc(sizeof(async_context_t));
	
	// Initialize hash tables directly
	zend_hash_init(&context->values, 8, NULL, ZVAL_PTR_DTOR, 0);
	zend_hash_init(&context->keys, 8, NULL, ZVAL_PTR_DTOR, 0);
	
	// Set parent context
	context->parent = parent_context;
	
	// Initialize base context function pointers
	context->base.find = (zend_async_context_find_t)async_context_find;
	context->base.set = (zend_async_context_set_t)async_context_set;
	context->base.unset = (zend_async_context_unset_t)async_context_delete;
	context->base.dispose = (zend_async_context_dispose_t)async_context_dispose;
	context->base.offset = XtOffsetOf(async_context_t, std);
	
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

//////////////////////////////////////////////////////////////////////
/// Context Class Implementation
//////////////////////////////////////////////////////////////////////

zend_class_entry *async_ce_context = NULL;

#define METHOD(name) PHP_METHOD(Async_Context, name)

METHOD(find)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	async_context_t *context = (async_context_t *) ZEND_ASYNC_OBJECT_TO_EVENT(Z_OBJ_P(ZEND_THIS));
	
	zval result;
	if (async_context_find(context, key, &result)) {
		RETURN_ZVAL(&result, 0, 1);
	}
	
	RETURN_NULL();
}

METHOD(get)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	async_context_t *context = (async_context_t *) ZEND_ASYNC_OBJECT_TO_EVENT(Z_OBJ_P(ZEND_THIS));
	
	zval result;
	if (async_context_find_local(context, key, &result)) {
		RETURN_ZVAL(&result, 0, 1);
	}
	
	RETURN_NULL();
}

METHOD(has)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	async_context_t *context = (async_context_t *) ZEND_ASYNC_OBJECT_TO_EVENT(Z_OBJ_P(ZEND_THIS));
	
	RETURN_BOOL(async_context_has(context, key));
}

METHOD(findLocal)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	async_context_t *context = (async_context_t *) ZEND_ASYNC_OBJECT_TO_EVENT(Z_OBJ_P(ZEND_THIS));
	
	zval result;
	if (async_context_find_local(context, key, &result)) {
		RETURN_ZVAL(&result, 0, 1);
	}
	
	RETURN_NULL();
}

METHOD(getLocal)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	async_context_t *context = (async_context_t *) ZEND_ASYNC_OBJECT_TO_EVENT(Z_OBJ_P(ZEND_THIS));
	
	zval result;
	if (async_context_find_local(context, key, &result)) {
		RETURN_ZVAL(&result, 0, 1);
	}
	
	RETURN_NULL();
}

METHOD(hasLocal)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	async_context_t *context = (async_context_t *) ZEND_ASYNC_OBJECT_TO_EVENT(Z_OBJ_P(ZEND_THIS));
	
	RETURN_BOOL(async_context_has_local(context, key));
}

METHOD(set)
{
	zval *key, *value;
	bool replace = false;
	
	ZEND_PARSE_PARAMETERS_START(2, 3)
		Z_PARAM_ZVAL(key)
		Z_PARAM_ZVAL(value)
		Z_PARAM_OPTIONAL
		Z_PARAM_BOOL(replace)
	ZEND_PARSE_PARAMETERS_END();

	async_context_t *context = (async_context_t *) ZEND_ASYNC_OBJECT_TO_EVENT(Z_OBJ_P(ZEND_THIS));
	
	// Check if key exists and replace is false
	if (!replace && async_context_has_local(context, key)) {
		async_throw_error("Context key already exists and replace is false");
		RETURN_THROWS();
	}
	
	async_context_set(context, key, value);
	
	RETURN_OBJ_COPY(&context->std);
}

METHOD(unset)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	async_context_t *context = (async_context_t *) ZEND_ASYNC_OBJECT_TO_EVENT(Z_OBJ_P(ZEND_THIS));
	
	async_context_delete(context, key);
	
	RETURN_OBJ_COPY(&context->std);
}

static zend_object *context_object_create(zend_class_entry *class_entry)
{
	async_context_t *context = zend_object_alloc(
		sizeof(async_context_t) + zend_object_properties_size(async_ce_context), class_entry
	);

	// Initialize hash tables directly
	zend_hash_init(&context->values, 8, NULL, ZVAL_PTR_DTOR, 0);
	zend_hash_init(&context->keys, 8, NULL, ZVAL_PTR_DTOR, 0);
	
	// Set parent context to NULL by default
	context->parent = NULL;
	
	ZEND_ASYNC_EVENT_SET_ZEND_OBJ(&context->base);
	ZEND_ASYNC_EVENT_SET_NO_FREE_MEMORY(&context->base);
	ZEND_ASYNC_EVENT_SET_ZEND_OBJ_OFFSET(&context->base, XtOffsetOf(async_context_t, std));

	zend_object_std_init(&context->std, class_entry);
	object_properties_init(&context->std, class_entry);

	return &context->std;
}

static void context_object_destroy(zend_object *object)
{
	async_context_t *context = (async_context_t *) ZEND_ASYNC_OBJECT_TO_EVENT(object);
	
	// Destroy hash tables
	zend_hash_destroy(&context->values);
	zend_hash_destroy(&context->keys);
}

static void context_free(zend_object *object)
{
	zend_object_std_dtor(object);
}

void async_register_context_ce(void)
{
	async_ce_context = register_class_Async_Context();
	
	async_ce_context->create_object = context_object_create;
	
	// Set up object handlers
	static zend_object_handlers context_handlers;
	context_handlers = std_object_handlers;
	context_handlers.offset = XtOffsetOf(async_context_t, std);
	context_handlers.clone_obj = NULL;
	context_handlers.dtor_obj = context_object_destroy;
	context_handlers.free_obj = context_free;
	
	async_ce_context->default_object_handlers = &context_handlers;
}