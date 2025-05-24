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
#include "scope.h"
#include "zend_attributes.h"
#include "scope_arginfo.h"
#include "zend_common.h"
#include "php_async.h"

#define METHOD(name) PHP_METHOD(Async_Scope, name)

zend_class_entry * async_ce_scope = NULL;
zend_class_entry * async_ce_scope_provider = NULL;
zend_class_entry * async_ce_spawn_strategy = NULL;

static zend_object_handlers async_scope_handlers;

//////////////////////////////////////////////////////////
/// Scope methods
//////////////////////////////////////////////////////////

METHOD(inherit)
{

}

METHOD(provideScope)
{

}

METHOD(__construct)
{

}

METHOD(asNotSafely)
{

}

METHOD(spawn)
{

}

METHOD(cancel)
{

}

METHOD(awaitCompletion)
{

}

METHOD(awaitAfterCancellation)
{

}

METHOD(isFinished)
{

}

METHOD(isClosed)
{

}

METHOD(setExceptionHandler)
{

}

METHOD(setChildScopeExceptionHandler)
{

}

METHOD(onFinally)
{

}

METHOD(dispose)
{

}

METHOD(disposeSafely)
{

}

METHOD(disposeAfterTimeout)
{

}

METHOD(getChildScopes)
{

}

//////////////////////////////////////////////////////////
/// Scope methods end
//////////////////////////////////////////////////////////

static void scope_before_coroutine_enqueue(zend_coroutine_t *coroutine, zend_async_scope_t *zend_scope, zval *result)
{
	async_scope_t *scope = (async_scope_t *) zend_scope;

	async_scope_add_coroutine(scope, (async_coroutine_t *) coroutine);
}

static void scope_after_coroutine_enqueue(zend_coroutine_t *coroutine, zend_async_scope_t *scope)
{
}

static void scope_dispose_coroutines_and_children(async_scope_t *scope)
{
	// First dispose all children scopes
	for (uint32_t i = 0; i < scope->scope.scopes.length; ++i) {
		async_scope_t *child_scope = (async_scope_t *) scope->scope.scopes.data[i];
		child_scope->scope.dispose(&child_scope->scope);
	}

	const bool is_safely = ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(&scope->scope);

	// Then cancel all coroutines
	for (uint32_t i = 0; i < scope->coroutines.length; ++i) {
		async_coroutine_t *coroutine = scope->coroutines.data[i];
		ZEND_ASYNC_CANCEL_EX(&coroutine->coroutine, NULL, false, is_safely);
	}
}

static void scope_dispose(zend_async_scope_t *zend_scope)
{
	async_scope_t *scope = (async_scope_t *) zend_scope;

	if (scope->coroutines.length > 0 || scope->scope.scopes.length > 0) {

		if (false == ZEND_ASYNC_SCOPE_IS_CLOSED(&scope->scope)) {
			ZEND_ASYNC_SCOPE_SET_CLOSED(&scope->scope);
			scope_dispose_coroutines_and_children(scope);
		}

		if (scope->coroutines.length > 0 || scope->scope.scopes.length > 0) {
			return;
		}
	}

	if (scope->scope.parent_scope) {
		zend_async_scope_remove_child(scope->scope.parent_scope, &scope->scope);
	}

	scope->scope.before_coroutine_enqueue = NULL;
	scope->scope.after_coroutine_enqueue = NULL;
	scope->scope.dispose = NULL;

	if (scope->scope.scope_object != NULL) {
		((async_scope_object_t *) scope->scope.scope_object)->scope = NULL;
		OBJ_RELEASE(scope->scope.scope_object);
		scope->scope.scope_object = NULL;
	}

	async_scope_free_coroutines(scope);
	efree(scope);
}

zend_async_scope_t * async_new_scope(zend_async_scope_t * parent_scope)
{
	async_scope_t *scope = ecalloc(1, sizeof(async_scope_t));

	async_scope_object_t *scope_object = zend_object_alloc(sizeof(async_scope_object_t), async_ce_scope);

	zend_object_std_init(&scope_object->std, async_ce_scope);
	object_properties_init(&scope_object->std, async_ce_scope);

	if (UNEXPECTED(EG(exception))) {
		efree(scope);
		OBJ_RELEASE(&scope_object->std);
		return NULL;
	}

	scope_object->scope = scope;

	scope->scope.parent_scope = parent_scope;

	scope->scope.before_coroutine_enqueue = scope_before_coroutine_enqueue;
	scope->scope.after_coroutine_enqueue = scope_after_coroutine_enqueue;
	scope->scope.dispose = scope_dispose;
	scope->scope.scope_object = &scope_object->std;
	scope->coroutines.length = 0;
	scope->coroutines.capacity = 0;
	scope->coroutines.data = NULL;

	return &scope->scope;
}

zend_object *scope_object_create(zend_class_entry *class_entry)
{
	return async_new_scope(NULL)->scope_object;
}

static void scope_destroy(zend_object *object)
{
	async_scope_object_t *scope_object = (async_scope_object_t *) object;

	if (scope_object->scope != NULL) {
		async_scope_t *scope = scope_object->scope;
		scope_object->scope = NULL;
		scope->scope.dispose(&scope->scope);
	}
}

void async_register_scope_ce(void)
{
	async_ce_scope_provider = register_class_Async_ScopeProvider();
	async_ce_spawn_strategy = register_class_Async_SpawnStrategy(async_ce_scope_provider);
	async_ce_scope = register_class_Async_Scope(async_ce_scope_provider);

	async_ce_scope->create_object = scope_object_create;

	async_scope_handlers = std_object_handlers;

	async_scope_handlers.clone_obj = NULL;
	async_scope_handlers.dtor_obj = scope_destroy;
}