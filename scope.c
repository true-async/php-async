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
#include "context.h"
#include "zend_attributes.h"
#include "scope_arginfo.h"
#include "zend_common.h"
#include "php_async.h"
#include "exceptions.h"

#define METHOD(name) PHP_METHOD(Async_Scope, name)

zend_class_entry * async_ce_scope = NULL;
zend_class_entry * async_ce_scope_provider = NULL;
zend_class_entry * async_ce_spawn_strategy = NULL;

static zend_object_handlers async_scope_handlers;

//////////////////////////////////////////////////////////
/// Scope methods
//////////////////////////////////////////////////////////

static void scope_dispose_coroutines_and_children(async_scope_t *scope);

// Event method forward declarations
static void scope_event_start(zend_async_event_t *event);
static void scope_event_stop(zend_async_event_t *event);
static void scope_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback);
static void scope_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback);
static bool scope_replay(zend_async_event_t *event, zend_async_event_callback_t *callback, zval *result, zend_object **exception);
static zend_string* scope_info(zend_async_event_t *event);

#define THROW_IF_SCHEDULER_CONTEXT if (UNEXPECTED(ZEND_ASYNC_IS_SCHEDULER_CONTEXT)) {		\
		async_throw_error("The operation cannot be executed in the scheduler context");		\
		RETURN_THROWS();																	\
	}

#define THROW_IF_ASYNC_OFF if (UNEXPECTED(ZEND_ASYNC_OFF)) {								\
		async_throw_error("The operation cannot be executed while async is off");			\
		RETURN_THROWS();																	\
	}

#define SCHEDULER_LAUNCH if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL)) {		\
		async_scheduler_launch();														\
		if (UNEXPECTED(EG(exception) != NULL)) {										\
			RETURN_THROWS();															\
		}																				\
	}

#define THIS_SCOPE ((async_scope_object_t *) Z_OBJ_P(ZEND_THIS))

METHOD(inherit)
{
	zend_object *parent_scope_obj = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(parent_scope_obj, async_ce_scope)
	ZEND_PARSE_PARAMETERS_END();

	zend_async_scope_t *base_parent_scope = NULL;

	if (parent_scope_obj != NULL) {
		async_scope_object_t *parent_obj = (async_scope_object_t *) parent_scope_obj;

		if (UNEXPECTED(parent_obj->scope == NULL)) {
			async_throw_error("Cannot inherit a Scope from a parent Scope that has already been disposed.");
			RETURN_THROWS();
		}

		base_parent_scope = &parent_obj->scope->scope;
	} else {
		base_parent_scope = ZEND_ASYNC_CURRENT_SCOPE;
	}

	zend_async_scope_t *new_scope = async_new_scope(base_parent_scope);
	if (UNEXPECTED(new_scope == NULL)) {
		RETURN_THROWS();
	}

	RETURN_OBJ(new_scope->scope_object);
}

METHOD(provideScope)
{
	ZEND_PARSE_PARAMETERS_NONE();

	RETURN_ZVAL(ZEND_THIS, 1, 0);
}

METHOD(__construct)
{
	ZEND_PARSE_PARAMETERS_NONE();

	// Constructor is called when object is created
	// Main initialization is handled in scope_object_create
}

METHOD(asNotSafely)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		async_throw_error("Scope object has been disposed");
		RETURN_THROWS();
	}

	// Clear dispose safely flag
	ZEND_ASYNC_SCOPE_CLR_DISPOSE_SAFELY(&scope_object->scope->scope);

	RETURN_ZVAL(ZEND_THIS, 1, 0);
}

METHOD(spawn)
{
	THROW_IF_ASYNC_OFF;
	THROW_IF_SCHEDULER_CONTEXT;

	zval *args = NULL;
	int args_count = 0;
	HashTable *named_args = NULL;

	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(1, -1)
		Z_PARAM_FUNC(fci, fcc);
		Z_PARAM_VARIADIC_WITH_NAMED(args, args_count, named_args);
	ZEND_PARSE_PARAMETERS_END();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		async_throw_error("Scope object has been disposed");
		RETURN_THROWS();
	}

	if (UNEXPECTED(ZEND_ASYNC_SCOPE_IS_CLOSED(&scope_object->scope->scope))) {
		async_throw_error("Cannot spawn coroutine in a closed scope");
		RETURN_THROWS();
	}

	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_SPAWN_WITH(&scope_object->scope->scope);
	if (UNEXPECTED(EG(exception))) {
		return;
	}

	ZEND_ASYNC_FCALL_DEFINE(fcall, fci, fcc, args, args_count, named_args);

	coroutine->coroutine.fcall = fcall;
	RETURN_OBJ_COPY(&coroutine->std);
}

METHOD(cancel)
{
	zend_object *cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OR_NULL(cancellation)
	ZEND_PARSE_PARAMETERS_END();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		return;
	}

	zend_async_scope_t * scope = &scope_object->scope->scope;

	scope->cancel(scope, cancellation, false, ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(scope));
}

bool async_scope_contains_coroutine(async_scope_t *scope, zend_coroutine_t *coroutine)
{
	// Check if coroutine belongs directly to this scope
	for (uint32_t i = 0; i < scope->coroutines.length; i++) {
		if (&scope->coroutines.data[i]->coroutine == coroutine) {
			return true;
		}
	}
	
	// Recursively check child scopes
	for (uint32_t i = 0; i < scope->scope.scopes.length; i++) {
		async_scope_t *child_scope = (async_scope_t *) scope->scope.scopes.data[i];
		if (async_scope_contains_coroutine(child_scope, coroutine)) {
			return true;
		}
	}
	
	return false;
}

void async_scope_mark_coroutine_zombie(async_scope_t *scope, async_coroutine_t *coroutine)
{
	// Check if coroutine was active before becoming zombie
	if (!ZEND_COROUTINE_IS_ZOMBIE(&coroutine->coroutine)) {
		// Mark as zombie and decrement active count
		ZEND_COROUTINE_SET_ZOMBIE(&coroutine->coroutine);
		if (scope->active_coroutines_count > 0) {
			scope->active_coroutines_count--;
		}
		
		// Check if scope is now complete and notify waiting callbacks
		if (scope->active_coroutines_count == 0 && scope->scope.scopes.length == 0) {
			ZEND_ASYNC_CALLBACKS_NOTIFY(&scope->scope.event, NULL, NULL);
		}
	}
}

METHOD(awaitCompletion)
{
	zend_object *cancellation_obj;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJ(cancellation_obj)
	ZEND_PARSE_PARAMETERS_END();

	zend_coroutine_t *current_coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	if (UNEXPECTED(current_coroutine == NULL)) {
		return;
	}

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		async_throw_error("Scope object has been disposed");
		RETURN_THROWS();
	}

	// Check for deadlock: current coroutine belongs to this scope or its children
	if (async_scope_contains_coroutine(scope_object->scope, current_coroutine)) {
		async_throw_error("Cannot await completion of scope from a coroutine that belongs to the same scope or its children - this would cause a deadlock");
		RETURN_THROWS();
	}

	// Check if scope is already finished (no active coroutines and no child scopes)
	if (scope_object->scope->active_coroutines_count == 0 && scope_object->scope->scope.scopes.length == 0) {
		return; // Already completed
	}

	// Register callback to be notified when scope completes
	zend_coroutine_event_callback_t * callback = zend_async_coroutine_callback_new(
		current_coroutine,
		zend_async_waker_callback_resolve,
		0
	);

	// Add callback to scope's event system
	scope_object->scope->scope.event.add_callback(&scope_object->scope->scope.event, callback);

	ZEND_ASYNC_SUSPEND();
}

METHOD(awaitAfterCancellation)
{
	zend_fcall_info error_handler_fci = {0};
	zend_fcall_info_cache error_handler_fcc = {0};
	zend_object *cancellation_obj = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 2)
		Z_PARAM_OPTIONAL
		Z_PARAM_FUNC_OR_NULL(error_handler_fci, error_handler_fcc)
		Z_PARAM_OBJ_OR_NULL(cancellation_obj)
	ZEND_PARSE_PARAMETERS_END();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		async_throw_error("Scope object has been disposed");
		RETURN_THROWS();
	}

	// Cancel the scope first if not already cancelled
	if (!ZEND_ASYNC_SCOPE_IS_CLOSED(&scope_object->scope->scope)) {
		ZEND_ASYNC_SCOPE_SET_CLOSED(&scope_object->scope->scope);
		scope_dispose_coroutines_and_children(scope_object->scope);
	}

	// Wait for completion
	while (scope_object->scope->coroutines.length > 0 || scope_object->scope->scope.scopes.length > 0) {
		ZEND_ASYNC_SUSPEND();
		if (UNEXPECTED(EG(exception))) {
			if (ZEND_FCI_INITIALIZED(error_handler_fci)) {
				// TODO: Call error handler with exception
				zend_clear_exception();
			}
			break;
		}
	}
}

METHOD(isFinished)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		RETURN_TRUE;
	}

	// Scope is finished when it has no active coroutines or child scopes
	bool is_finished = (scope_object->scope->active_coroutines_count == 0 && 
						scope_object->scope->scope.scopes.length == 0);

	RETURN_BOOL(is_finished);
}

METHOD(isClosed)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		RETURN_TRUE;
	}

	RETURN_BOOL(ZEND_ASYNC_SCOPE_IS_CLOSED(&scope_object->scope->scope));
}

METHOD(setExceptionHandler)
{
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_FUNC(fci, fcc)
	ZEND_PARSE_PARAMETERS_END();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		async_throw_error("Scope object has been disposed");
		RETURN_THROWS();
	}

	// TODO: Store exception handler in scope structure
	// For now, just validate the callable
}

METHOD(setChildScopeExceptionHandler)
{
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_FUNC(fci, fcc)
	ZEND_PARSE_PARAMETERS_END();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		async_throw_error("Scope object has been disposed");
		RETURN_THROWS();
	}

	// TODO: Store child scope exception handler
	// For now, just validate the callable
}

METHOD(onFinally)
{
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_FUNC(fci, fcc)
	ZEND_PARSE_PARAMETERS_END();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		async_throw_error("Scope object has been disposed");
		RETURN_THROWS();
	}

	// TODO: Store finally callback
	// For now, just validate the callable
}

METHOD(dispose)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (scope_object->scope != NULL) {
		scope_object->scope->scope.event.dispose(&scope_object->scope->scope.event);
	}
}

METHOD(disposeSafely)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (scope_object->scope != NULL) {
		// Set dispose safely flag
		ZEND_ASYNC_SCOPE_SET_DISPOSE_SAFELY(&scope_object->scope->scope);
		scope_object->scope->scope.event.dispose(&scope_object->scope->scope.event);
	}
}

METHOD(disposeAfterTimeout)
{
	zend_long timeout;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(timeout)
	ZEND_PARSE_PARAMETERS_END();

	if (timeout < 0) {
		async_throw_error("Timeout must be non-negative");
		RETURN_THROWS();
	}

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (scope_object->scope != NULL) {
		// TODO: Implement timeout-based disposal
		// For now, just dispose immediately
		scope_object->scope->scope.event.dispose(&scope_object->scope->scope.event);
	}
}

METHOD(getChildScopes)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		array_init(return_value);
		return;
	}

	array_init_size(return_value, scope_object->scope->scope.scopes.length);

	for (uint32_t i = 0; i < scope_object->scope->scope.scopes.length; i++) {
		zend_async_scope_t *child_scope = scope_object->scope->scope.scopes.data[i];
		if (child_scope->scope_object != NULL) {
			add_next_index_object(return_value, child_scope->scope_object);
			GC_ADDREF(child_scope->scope_object);
		}
	}
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

static void scope_cancel(zend_async_scope_t *scope, zend_object *error, bool transfer_error, const bool is_safely)
{
	async_scope_t *async_scope = (async_scope_t *) scope;

	if (error == NULL) {
		transfer_error = false; // No error to transfer
	}

	if (UNEXPECTED(ZEND_ASYNC_SCOPE_IS_CLOSED(&async_scope->scope))) {
		if (transfer_error) {
			OBJ_RELEASE(error);
		}
		return; // Already closed
	}

	ZEND_ASYNC_SCOPE_SET_CANCELLED(&async_scope->scope);

	const bool is_error_null = (error == NULL);

	if (is_error_null) {
		error = async_new_exception(async_ce_cancellation_exception, "Scope was cancelled");
		transfer_error = true;
		if (UNEXPECTED(EG(exception))) {
			return;
		}
	}

	// First cancel all children scopes
	for (uint32_t i = 0; i < scope->scopes.length; ++i) {
		async_scope_t *child_scope = (async_scope_t *) scope->scopes.data[i];
		child_scope->scope.cancel(&child_scope->scope, error, false, is_safely);
	}

	// Then cancel all coroutines
	for (uint32_t i = 0; i < async_scope->coroutines.length; ++i) {
		async_coroutine_t *coroutine = async_scope->coroutines.data[i];
		ZEND_ASYNC_CANCEL_EX(&coroutine->coroutine, error, false, is_safely);
	}

	if (transfer_error) {
		OBJ_RELEASE(error);
	}
}

static void scope_dispose_coroutines_and_children(async_scope_t *scope)
{
	const bool is_safely = ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(&scope->scope);

	// First dispose all children scopes
	for (uint32_t i = 0; i < scope->scope.scopes.length; ++i) {
		async_scope_t *child_scope = (async_scope_t *) scope->scope.scopes.data[i];
		child_scope->scope.cancel(&child_scope->scope, NULL, false, is_safely);
		child_scope->scope.event.dispose(&child_scope->scope.event);
	}

	// Then cancel all coroutines
	for (uint32_t i = 0; i < scope->coroutines.length; ++i) {
		async_coroutine_t *coroutine = scope->coroutines.data[i];
		ZEND_ASYNC_CANCEL_EX(&coroutine->coroutine, NULL, false, is_safely);
	}
}

static void scope_event_start(zend_async_event_t *event)
{
	// Empty implementation - scopes don't need explicit start
}

static void scope_event_stop(zend_async_event_t *event)
{
	// Empty implementation - scopes don't need explicit stop
}

static void scope_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	zend_async_callbacks_push(event, callback);
}

static void scope_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	zend_async_callbacks_remove(event, callback);
}

static bool scope_replay(zend_async_event_t *event, zend_async_event_callback_t *callback, zval *result, zend_object **exception)
{
	async_scope_t *scope = (async_scope_t *) event;

	// Scope can only be replayed if it's finished (no active coroutines or child scopes)
	if (UNEXPECTED(scope->coroutines.length > 0 || scope->scope.scopes.length > 0)) {
		ZEND_ASSERT("Cannot replay scope, because the scope is not finished");
		return false;
	}

	if (callback != NULL) {
		// For finished scopes, we don't have a specific result, so pass UNDEF
		zval undef_result;
		ZVAL_UNDEF(&undef_result);
		callback->callback(event, callback, &undef_result, NULL);
		return true;
	}

	if (result != NULL) {
		// Finished scope has no meaningful result
		ZVAL_NULL(result);
	}

	// No exception to propagate from finished scope
	if (exception != NULL) {
		*exception = NULL;
	}

	return true;
}

static zend_string* scope_info(zend_async_event_t *event)
{
	async_scope_t *scope = (async_scope_t *) event;

	const char *status;
	if (ZEND_ASYNC_SCOPE_IS_CLOSED(&scope->scope)) {
		status = "closed";
	} else if (scope->coroutines.length == 0 && scope->scope.scopes.length == 0) {
		status = "finished";
	} else {
		status = "running";
	}

	return zend_strpprintf(0,
		"Scope %s with %d coroutines and %d child scopes",
		status,
		scope->coroutines.length,
		scope->scope.scopes.length
	);
}

static void scope_dispose(zend_async_event_t *scope_event)
{
	if (ZEND_ASYNC_EVENT_REF(scope_event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(scope_event);
		return;
	}

	async_scope_t *scope = (async_scope_t *) scope_event;

	ZEND_ASSERT(scope->coroutines.length == 0 && scope->scope.scopes.length == 0
		&& "Scope should be empty before disposal");

	if (scope->scope.parent_scope) {
		zend_async_scope_remove_child(scope->scope.parent_scope, &scope->scope);
	}

	scope->scope.before_coroutine_enqueue = NULL;
	scope->scope.after_coroutine_enqueue = NULL;
	scope->scope.event.dispose = NULL;

	// Clear weak reference from context to scope
	if (scope->scope.context != NULL) {
		async_context_t *context = (async_context_t *) scope->scope.context;
		context->scope = NULL;
	}

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
	zend_async_event_t *event = &scope->scope.event;

	scope->scope.before_coroutine_enqueue = scope_before_coroutine_enqueue;
	scope->scope.after_coroutine_enqueue = scope_after_coroutine_enqueue;
	scope->scope.cancel = scope_cancel;
	scope->scope.scope_object = &scope_object->std;
	scope->coroutines.length = 0;
	scope->coroutines.capacity = 0;
	scope->coroutines.data = NULL;
	scope->active_coroutines_count = 0;

	event->start = scope_event_start;
	event->stop = scope_event_stop;
	event->add_callback = scope_add_callback;
	event->del_callback = scope_del_callback;
	event->replay = scope_replay;
	event->info = scope_info;
	event->dispose = scope_dispose;

	if (parent_scope != NULL) {
		zend_async_scope_add_child(parent_scope, &scope->scope);
	}

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
		scope->scope.event.dispose(&scope->scope.event);
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