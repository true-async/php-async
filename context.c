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

bool async_context_find(async_context_t *context, zval *key, zval *result, bool include_parent)
{
	// First try to find in current context
	if (async_context_find_local(context, key, result)) {
		return true;
	}

	if (false == include_parent) {
		// If not found and parent is not included, return false
		if (result != NULL) {
			ZVAL_NULL(result);
		}

		return false;
	}

	// Use context's own scope instead of global current scope
	zend_async_scope_t *scope = context->scope;

	if (UNEXPECTED(scope == NULL)) {
		if (result != NULL) {
			ZVAL_NULL(result);
		}

		return false;
	}

	// Start from parent scope since we already checked current context
	scope = scope->parent_scope;

	while (scope != NULL && scope->context != NULL) {
		if (async_context_find_local((async_context_t *) scope->context, key, result)) {
			return true;
		}

		scope = scope->parent_scope;
	}

	if (result != NULL) {
		ZVAL_NULL(result);
	}

	return false;
}

void async_context_set(async_context_t *context, zval *key, zval *value)
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
	}
}

bool async_context_has(async_context_t *context, zval *key, bool include_parent)
{
	return async_context_find(context, key, NULL, include_parent);
}

bool async_context_unset(async_context_t *context, zval *key)
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

bool async_context_find_local(async_context_t *context, zval *key, zval *result)
{
	zval *found = NULL;

	if (Z_TYPE_P(key) == IS_STRING) {
		found = zend_hash_find(&context->values, Z_STR_P(key));
	} else if (Z_TYPE_P(key) == IS_OBJECT) {
		found = zend_hash_index_find(&context->values, Z_OBJ_P(key)->handle);
	} else {
		async_throw_error("Context key must be a string or an object");

		if (result != NULL) {
			ZVAL_NULL(result);
		}

		return false;
	}

	if (found != NULL) {
		if (result != NULL) {
			ZVAL_COPY(result, found);
		}

		return true;
	}

	if (result != NULL) {
		ZVAL_NULL(result);
	}

	return false;
}

bool async_context_has_local(async_context_t *context, zval *key)
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

async_context_t *async_context_new(void)
{
	async_context_t *context = zend_object_alloc(sizeof(async_context_t), async_ce_context);

	// Initialize hash tables directly
	zend_hash_init(&context->values, 8, NULL, ZVAL_PTR_DTOR, 0);
	zend_hash_init(&context->keys, 8, NULL, ZVAL_PTR_DTOR, 0);

	// Initialize scope reference as NULL (weak reference)
	context->scope = NULL;

	// Initialize base context function pointers
	context->base.find = (zend_async_context_find_t) async_context_find;
	context->base.set = (zend_async_context_set_t) async_context_set;
	context->base.unset = (zend_async_context_unset_t) async_context_unset;
	context->base.dispose = (zend_async_context_dispose_t) async_context_dispose;

	// Initialize std object
	context->base.offset = XtOffsetOf(async_context_t, std);

	zend_object_std_init(&context->std, async_ce_context);
	object_properties_init(&context->std, async_ce_context);

	return context;
}

void async_context_dispose(async_context_t *context)
{
	OBJ_RELEASE(&context->std);
}

//////////////////////////////////////////////////////////////////////
/// Context Class Implementation
//////////////////////////////////////////////////////////////////////

zend_class_entry *async_ce_context = NULL;

#define METHOD(name) ZEND_METHOD(Async_Context, name)
#define ZEND_OBJECT_TO_CONTEXT(obj) ((async_context_t *) ((char *) (obj) - (obj)->handlers->offset))
#define THIS_CONTEXT ZEND_OBJECT_TO_CONTEXT(Z_OBJ_P(ZEND_THIS))

#define VALIDATE_CONTEXT_KEY(key, arg_num) do { \
	if (UNEXPECTED(Z_TYPE_P(key) != IS_STRING && Z_TYPE_P(key) != IS_OBJECT)) { \
		zend_argument_type_error(arg_num, "must be of type string|object, %s given", zend_zval_type_name(key)); \
		RETURN_THROWS(); \
	} \
} while (0)

METHOD(find)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	VALIDATE_CONTEXT_KEY(key, 1);

	if (async_context_find(THIS_CONTEXT, key, return_value, true)) {
		return;
	}

	RETURN_NULL();
}

METHOD(get)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	VALIDATE_CONTEXT_KEY(key, 1);

	if (async_context_find(THIS_CONTEXT, key, return_value, true)) {
		return;
	}

	RETURN_NULL();
}

METHOD(has)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	VALIDATE_CONTEXT_KEY(key, 1);

	RETURN_BOOL(async_context_find(THIS_CONTEXT, key, NULL, true));
}

METHOD(findLocal)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	VALIDATE_CONTEXT_KEY(key, 1);

	if (async_context_find_local(THIS_CONTEXT, key, return_value)) {
		return;
	}

	RETURN_NULL();
}

METHOD(getLocal)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	VALIDATE_CONTEXT_KEY(key, 1);

	if (async_context_find_local(THIS_CONTEXT, key, return_value)) {
		return;
	}

	RETURN_NULL();
}

METHOD(hasLocal)
{
	zval *key;
	ZEND_PARSE_PARAMETERS_START(1, 1)
	Z_PARAM_ZVAL(key)
	ZEND_PARSE_PARAMETERS_END();

	VALIDATE_CONTEXT_KEY(key, 1);

	RETURN_BOOL(async_context_has_local(THIS_CONTEXT, key));
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

	async_context_t *context = THIS_CONTEXT;

	VALIDATE_CONTEXT_KEY(key, 1);

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

	VALIDATE_CONTEXT_KEY(key, 1);

	async_context_t *context = THIS_CONTEXT;

	async_context_unset(context, key);

	RETURN_OBJ_COPY(&context->std);
}

static zend_object *context_object_create(zend_class_entry *class_entry)
{
	async_context_t *context = async_context_new();
	return &context->std;
}

static void context_object_destroy(zend_object *object)
{
	async_context_t *context = ZEND_OBJECT_TO_CONTEXT(object);

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