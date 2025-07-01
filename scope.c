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
#include "iterator.h"

#define METHOD(name) PHP_METHOD(Async_Scope, name)

zend_class_entry * async_ce_scope = NULL;
zend_class_entry * async_ce_scope_provider = NULL;
zend_class_entry * async_ce_spawn_strategy = NULL;

static zend_object_handlers async_scope_handlers;

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
	if (ZEND_COROUTINE_IS_ZOMBIE(&coroutine->coroutine)) {
		scope->zombie_coroutines_count++;
	} else {
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
				} else if (scope->zombie_coroutines_count > 0) {
					scope->zombie_coroutines_count--;
				}
			}

			vector->data[i] = vector->data[--vector->length];
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

//////////////////////////////////////////////////////////
/// Scope methods
//////////////////////////////////////////////////////////

static void callback_resolve_when_zombie_completed(
	zend_async_event_t *event, zend_async_event_callback_t *callback, void * result, zend_object *exception
);

static bool scope_can_be_disposed(async_scope_t *scope, bool with_zombies, bool check_zend_objects);
static void scope_check_completion_and_notify(async_scope_t *scope, bool with_zombies);

// Finally handlers execution functions
static bool async_scope_call_finally_handlers(async_scope_t *scope);

// Structure for scope finally handlers context
typedef struct {
	async_scope_t *scope;
	zend_object *composite_exception;
} scope_finally_handlers_context_t;

#define SCOPE_IS_COMPLETED(scope) scope_can_be_disposed(scope, false, false)
#define SCOPE_IS_COMPLETELY_DONE(scope) scope_can_be_disposed(scope, true, false)
#define SCOPE_CAN_BE_DISPOSED(scope) scope_can_be_disposed(scope, true, true)

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

	zend_async_scope_t *new_scope = async_new_scope(base_parent_scope, true);
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

	ZEND_ASYNC_SCOPE_CANCEL(scope, cancellation, false, ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(scope));
}

METHOD(awaitCompletion)
{
	zend_object *cancellation_obj;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJ_OF_CLASS(cancellation_obj, async_ce_awaitable)
	ZEND_PARSE_PARAMETERS_END();

	zend_coroutine_t *current_coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	if (UNEXPECTED(current_coroutine == NULL)) {
		return;
	}

	async_scope_object_t *scope_object = THIS_SCOPE;

	// If the Scope has already been destroyed or closed,
	// we immediately return.
	if (UNEXPECTED(scope_object->scope == NULL || ZEND_ASYNC_SCOPE_IS_CLOSED(&scope_object->scope->scope))) {
		return;
	}

	// If the scope is cancelled, throw cancellation exception
	if (UNEXPECTED(ZEND_ASYNC_SCOPE_IS_CANCELLED(&scope_object->scope->scope))) {
		async_throw_cancellation("The scope has been cancelled");
		RETURN_THROWS();
	}

	// Check for deadlock: current coroutine belongs to this scope or its children
	if (async_scope_contains_coroutine(scope_object->scope, current_coroutine, 0)) {
		async_throw_error("Cannot await completion of scope from a coroutine that belongs to the same scope or its children");
		RETURN_THROWS();
	}
	if (UNEXPECTED(EG(exception))) {
		RETURN_THROWS();
	}

	// Check if scope is already finished (no active coroutines and no child scopes)
	if (scope_object->scope->active_coroutines_count == 0 && scope_object->scope->scope.scopes.length == 0) {
		return;
	}

	zend_async_waker_new(current_coroutine);
	if (UNEXPECTED(EG(exception))) {
		RETURN_THROWS();
	}

	zend_async_resume_when(
		current_coroutine, &scope_object->scope->scope.event, false, zend_async_waker_callback_resolve, NULL
	);
	if (UNEXPECTED(EG(exception))) {
		zend_async_waker_destroy(current_coroutine);
		RETURN_THROWS();
	}

	zend_async_resume_when(
		current_coroutine,
		ZEND_ASYNC_OBJECT_TO_EVENT(cancellation_obj),
		false,
		zend_async_waker_callback_cancel,
		NULL
	);
	if (UNEXPECTED(EG(exception))) {
		zend_async_waker_destroy(current_coroutine);
		RETURN_THROWS();
	}

	ZEND_ASYNC_SUSPEND();
	zend_async_waker_destroy(current_coroutine);
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

	zend_coroutine_t *current_coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	if (UNEXPECTED(current_coroutine == NULL)) {
		return;
	}

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL || ZEND_ASYNC_SCOPE_IS_CLOSED(&scope_object->scope->scope))) {
		return;
	}

	if (false == ZEND_ASYNC_SCOPE_IS_CANCELLED(&scope_object->scope->scope)) {
		async_throw_error("Attempt to await a Scope that has not been cancelled");
	}

	// Check for deadlock: current coroutine belongs to this scope or its children
	if (async_scope_contains_coroutine(scope_object->scope, current_coroutine, 0)) {
		async_throw_error("Cannot await completion of scope from a coroutine that belongs to the same scope or its children");
		RETURN_THROWS();
	}
	if (UNEXPECTED(EG(exception))) {
		RETURN_THROWS();
	}

	// Check if scope is already finished (no active coroutines and no child scopes)
	if (scope_object->scope->coroutines.length == 0 && scope_object->scope->scope.scopes.length == 0) {
		return;
	}

	zend_async_waker_new(current_coroutine);
	if (UNEXPECTED(EG(exception))) {
		RETURN_THROWS();
	}

	// We need to create a custom callback to handle errors coming from coroutines.
	scope_coroutine_callback_t *scope_callback = (scope_coroutine_callback_t *) zend_async_coroutine_callback_new(
		current_coroutine, callback_resolve_when_zombie_completed, sizeof(scope_coroutine_callback_t)
	);
	if (UNEXPECTED(scope_callback == NULL)) {
		zend_async_waker_destroy(current_coroutine);
		RETURN_THROWS();
	}

	if (error_handler_fci.size != 0) {
		scope_callback->error_fci = &error_handler_fci;
		scope_callback->error_fci_cache = &error_handler_fcc;
	} else {
		scope_callback->error_fci = NULL;
		scope_callback->error_fci_cache = NULL;
	}

	zend_async_resume_when(
		current_coroutine, &scope_object->scope->scope.event, true, NULL, &scope_callback->callback
	);
	if (UNEXPECTED(EG(exception))) {
		zend_async_waker_destroy(current_coroutine);
		RETURN_THROWS();
	}

	if (cancellation_obj != NULL) {
		zend_async_resume_when(
			current_coroutine,
			ZEND_ASYNC_OBJECT_TO_EVENT(cancellation_obj),
			false,
			zend_async_waker_callback_cancel,
			NULL
		);
		if (UNEXPECTED(EG(exception))) {
			zend_async_waker_destroy(current_coroutine);
			RETURN_THROWS();
		}
	}

	ZEND_ASYNC_SUSPEND();
	zend_async_waker_destroy(current_coroutine);
}

METHOD(isFinished)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		RETURN_TRUE;
	}

	RETURN_BOOL(SCOPE_IS_COMPLETED(scope_object->scope));
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

	async_scope_t *scope = scope_object->scope;
	
	// Free existing exception handler if any
	if (scope->exception_fci != NULL || scope->exception_fcc != NULL) {
		zend_free_fci(scope->exception_fci, scope->exception_fcc);
		scope->exception_fci = NULL;
		scope->exception_fcc = NULL;
	}
	
	// Allocate and copy new handler
	scope->exception_fci = emalloc(sizeof(zend_fcall_info));
	scope->exception_fcc = emalloc(sizeof(zend_fcall_info_cache));
	zend_copy_fci(scope->exception_fci, scope->exception_fcc, &fci, &fcc);
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

	async_scope_t *scope = scope_object->scope;
	
	// Free existing child exception handler if any
	if (scope->child_exception_fci != NULL || scope->child_exception_fcc != NULL) {
		zend_free_fci(scope->child_exception_fci, scope->child_exception_fcc);
		scope->child_exception_fci = NULL;
		scope->child_exception_fcc = NULL;
	}
	
	// Allocate and copy new handler
	scope->child_exception_fci = emalloc(sizeof(zend_fcall_info));
	scope->child_exception_fcc = emalloc(sizeof(zend_fcall_info_cache));
	zend_copy_fci(scope->child_exception_fci, scope->child_exception_fcc, &fci, &fcc);
}

METHOD(onFinally)
{
	zval *callable;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(callable)
	ZEND_PARSE_PARAMETERS_END();

	// Validate callable
	if (UNEXPECTED(false == zend_is_callable(callable, 0, NULL))) {
		zend_argument_type_error(1, "argument must be callable");
		RETURN_THROWS();
	}

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (UNEXPECTED(scope_object->scope == NULL)) {
		// Execute callable immediately with scope as parameter
		zval result, param;
		ZVAL_UNDEF(&result);
		ZVAL_OBJ(&param, &scope_object->std);

		if (UNEXPECTED(call_user_function(NULL, NULL, callable, &result, 1, &param) == FAILURE)) {
			zend_throw_error(NULL, "Failed to call finally handler in finished scope");
			zval_ptr_dtor(&result);
			RETURN_THROWS();
		}
		zval_ptr_dtor(&result);
		return;
	}

	async_scope_t *scope = scope_object->scope;

	// Lazy initialization of finally_handlers
	if (scope->finally_handlers == NULL) {
		scope->finally_handlers = zend_new_array(0);
	}

	// Add to queue
	if (UNEXPECTED(zend_hash_next_index_insert(scope->finally_handlers, callable) == NULL)) {
		async_throw_error("Failed to add finally handler to scope");
		RETURN_THROWS();
	}

	Z_TRY_ADDREF_P(callable);
}

METHOD(dispose)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (scope_object->scope != NULL) {
		ZEND_ASYNC_SCOPE_CLOSE(&scope_object->scope->scope, ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(&scope_object->scope->scope));
	}
}

METHOD(disposeSafely)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_scope_object_t *scope_object = THIS_SCOPE;
	if (scope_object->scope != NULL) {
		ZEND_ASYNC_SCOPE_CLOSE(&scope_object->scope->scope, true);
	}
}

typedef struct {
	zend_async_event_callback_t callback;
	async_scope_t *scope;
} scope_timeout_callback_t;

static void scope_timeout_coroutine_entry(void)
{
	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
	async_scope_t *scope = (async_scope_t *) coroutine->extended_data;

	if (scope == NULL) {
		return;
	}

	coroutine->extended_data = NULL;

	zend_object * exception = async_new_exception(
		async_ce_cancellation_exception, "Scope has been disposed due to timeout"
	);

	ZEND_ASYNC_SCOPE_CANCEL(&scope->scope, exception, false, ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(&scope->scope));
}

static void scope_timeout_callback(
	zend_async_event_t *event,
	zend_async_event_callback_t *callback,
	void * result,
	zend_object *exception
)
{
	scope_timeout_callback_t *scope_callback = (scope_timeout_callback_t *) callback;
	async_scope_t *scope = scope_callback->scope;
	event->dispose(event);

	zend_coroutine_t *coroutine = ZEND_ASYNC_SPAWN_WITH(ZEND_ASYNC_MAIN_SCOPE);
	if (UNEXPECTED(coroutine == NULL)) {
		return;
	}

	coroutine->internal_entry = scope_timeout_coroutine_entry;
	coroutine->extended_data = scope;
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
	if (scope_object->scope == NULL) {
		return;
	}

	if (ZEND_ASYNC_SCOPE_IS_CLOSED(&scope_object->scope->scope)) {
		return;
	}

	if (scope_object->scope->coroutines.length == 0
		&& scope_object->scope->scope.scopes.length == 0) {
		return;
	}

	zend_async_timer_event_t *timer_event = ZEND_ASYNC_NEW_TIMER_EVENT(timeout, false);
	if (UNEXPECTED(timer_event == NULL)) {
		RETURN_THROWS();
	}

	scope_timeout_callback_t * callback = (scope_timeout_callback_t *) zend_async_event_callback_new(
		scope_timeout_callback,sizeof(scope_timeout_callback_t)
	);
	if (UNEXPECTED(callback == NULL)) {
		timer_event->base.dispose(&timer_event->base);
		RETURN_THROWS();
	}

	callback->scope = scope_object->scope;
	callback->scope->scope.event.ref_count++;

	timer_event->base.add_callback(&timer_event->base, &callback->callback);
	if (UNEXPECTED(EG(exception) != NULL)) {
		timer_event->base.dispose(&timer_event->base);
		return;
	}

	timer_event->base.start(&timer_event->base);
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
static void callback_resolve_when_zombie_completed(
	zend_async_event_t *event, zend_async_event_callback_t *callback, void * result, zend_object *exception
)
{
	async_scope_t *scope = (async_scope_t *) event;
	scope_coroutine_callback_t *scope_callback = (scope_coroutine_callback_t *) callback;
	zend_coroutine_t * coroutine = scope_callback->callback.coroutine;

	if (UNEXPECTED(exception != NULL && scope_callback->error_fci == NULL)) {
		// No error handler - resume with exception
		ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(event);
		ZEND_ASYNC_RESUME_WITH_ERROR(coroutine, exception, false);
		return;
	}

	if (UNEXPECTED(exception != NULL && scope_callback->error_fci != NULL)) {
		ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(event);
		
		// Prepare parameters for handler(\Throwable $error, Scope $scope)
		zval params[2];
		ZVAL_OBJ(&params[0], exception);
		ZVAL_OBJ(&params[1], scope->scope.scope_object);

		// Setup function call
		scope_callback->error_fci->param_count = 2;
		scope_callback->error_fci->params = params;
		
		zval retval;
		ZVAL_UNDEF(&retval);
		scope_callback->error_fci->retval = &retval;
		
		if (UNEXPECTED(zend_call_function(scope_callback->error_fci, scope_callback->error_fci_cache) == FAILURE)) {
			zval_ptr_dtor(&retval);
			ZEND_ASYNC_RESUME_WITH_ERROR(
				coroutine,
				async_new_exception(async_ce_async_exception, "Failed to call error handler in scope completion"),
				true
			);

			return;
		}

		if (UNEXPECTED(EG(exception))) {
			zend_exception_save();
			zend_exception_restore();
			ZEND_ASYNC_RESUME_WITH_ERROR(coroutine, EG(exception), false);
			zend_clear_exception();
			return;
		}
		
		zval_ptr_dtor(&retval);
	}

	if (SCOPE_IS_COMPLETELY_DONE(scope)) {
		ZEND_ASYNC_RESUME(coroutine);
	}
}

bool async_scope_contains_coroutine(async_scope_t *scope, zend_coroutine_t *coroutine, uint32_t depth)
{
	// Protect against stack overflow
	if (UNEXPECTED(depth > ASYNC_SCOPE_MAX_RECURSION_DEPTH)) {
		async_throw_error("Maximum recursion depth exceeded while checking coroutine containment");
		return false;
	}

	// Check if coroutine belongs directly to this scope
	for (uint32_t i = 0; i < scope->coroutines.length; i++) {
		if (&scope->coroutines.data[i]->coroutine == coroutine) {
			return true;
		}
	}

	// Recursively check child scopes
	for (uint32_t i = 0; i < scope->scope.scopes.length; i++) {
		async_scope_t *child_scope = (async_scope_t *) scope->scope.scopes.data[i];
		// Skip recursion if child scope has no coroutines
		if (child_scope->coroutines.length == 0 && child_scope->scope.scopes.length == 0) {
			continue;
		}
		if (async_scope_contains_coroutine(child_scope, coroutine, depth + 1)) {
			return true;
		}
	}

	return false;
}

void async_scope_notify_coroutine_finished(async_coroutine_t *coroutine)
{
	async_scope_t *scope = (async_scope_t *) coroutine->coroutine.scope;

	ZEND_ASSERT(scope != NULL && "Coroutine must belong to a valid scope");

	async_scope_remove_coroutine(scope, coroutine);
	scope->scope.try_to_dispose(&scope->scope);
}

void async_scope_mark_coroutine_zombie(async_coroutine_t *coroutine)
{
	async_scope_t *scope = (async_scope_t *) coroutine->coroutine.scope;

	// Check if coroutine was active before becoming zombie
	if (false == ZEND_COROUTINE_IS_ZOMBIE(&coroutine->coroutine)) {
		// Mark as zombie and decrement active count
		ZEND_COROUTINE_SET_ZOMBIE(&coroutine->coroutine);
		if (scope->active_coroutines_count > 0) {
			scope->active_coroutines_count--;
			scope->zombie_coroutines_count++;
		}

		// Check if scope and its parents are completed and notify
		scope_check_completion_and_notify(scope, false);
	}
}

static void scope_before_coroutine_enqueue(zend_coroutine_t *coroutine, zend_async_scope_t *zend_scope, zval *result)
{
	async_scope_t *scope = (async_scope_t *) zend_scope;

	async_scope_add_coroutine(scope, (async_coroutine_t *) coroutine);
}

static void scope_after_coroutine_enqueue(zend_coroutine_t *coroutine, zend_async_scope_t *scope)
{
}

static zend_always_inline bool try_to_handle_exception(
	async_scope_t *current_scope, async_scope_t * from_scope, zend_object *exception, async_coroutine_t *coroutine
);

static bool scope_catch_or_cancel(
	zend_async_scope_t *scope,
	zend_coroutine_t *coroutine,
	zend_async_scope_t *from_scope,
	zend_object *exception,
	bool transfer_error,
	const bool is_safely,
	const bool is_cancellation
)
{
	async_scope_t *async_scope = (async_scope_t *) scope;
	transfer_error = exception != NULL ? transfer_error : false;

	// If this is a user action rather than an error propagated from a coroutine,
	// and the Scope is already closed, exit immediately.
	if (UNEXPECTED(is_cancellation && ZEND_ASYNC_SCOPE_IS_CLOSED(&async_scope->scope))) {
		if (transfer_error) {
			OBJ_RELEASE(exception);
		}

		return true; // Already closed
	}

	// If the Scope::dispose() method was called by the user, and the Scope has fully completed execution at this point,
	// we can transition it to the CLOSED status and invoke the finalizing handlers right here.
	if (is_cancellation && SCOPE_IS_COMPLETELY_DONE(async_scope)) {
		ZEND_ASYNC_SCOPE_SET_CLOSED(&async_scope->scope);
		if (transfer_error) {
			OBJ_RELEASE(exception);
		}
		async_scope_call_finally_handlers(async_scope);
		return true;
	}

	if (is_cancellation && exception == NULL) {
		exception = async_new_exception(async_ce_cancellation_exception, "Scope was cancelled");
		transfer_error = true;
		if (UNEXPECTED(EG(exception))) {
			return false;
		}
	}

	if (UNEXPECTED(exception == NULL)) {
		return true;
	}

	bool result = true;

	//
	// Exception propagation algorithm through the Scope hierarchy:
	//
	// 1. If the current Scope is a parent of the original one, and it has a child_exception_fcc handler, we use it.
	// 2. Otherwise, we try to use the exception_fci handler.
	// 3. Otherwise, we cancel the Scope.
	// 4. We invoke all callback functions. If they absorb the exception, we stop unwinding the Scope hierarchy.
	// 5. Otherwise, we move to the next parent or exit.
	//

	if (false == is_cancellation
		&& (async_scope->exception_fci != NULL || async_scope->child_exception_fci != NULL)
		&& try_to_handle_exception(
			async_scope, (async_scope_t *) from_scope, exception, (async_coroutine_t *) coroutine
		)) {
		goto exit_true;
	}

	ZEND_ASYNC_SCOPE_SET_CANCELLED(&async_scope->scope);

	// If an unexpected exception occurs during the function's execution, we combine them into one.
	if (EG(exception)) {
		exception = zend_exception_merge(exception, true, transfer_error);
		transfer_error = true; // Because we owned the exception
	}

	zend_object *critical_exception = NULL;
	zend_async_scope_t * this_scope = &async_scope->scope;
	zend_async_scopes_vector_t *scopes = &this_scope->scopes;

	// First cancel all children scopes
	for (uint32_t i = 0; i < scopes->length; ++i) {
		async_scope_t *child_scope = (async_scope_t *) scopes->data[i];
		child_scope->scope.catch_or_cancel(
			&child_scope->scope,
			coroutine,
			this_scope,
			// In CATCH mode, we don’t pass the exception that caused the cancellation
			// to the child Scopes; instead, a new cancellation exception is generated.
			is_cancellation ? exception : NULL,
			false,
			is_safely,
			true
		);

		if (UNEXPECTED(EG(exception))) {
			critical_exception = zend_exception_merge(critical_exception, true, true);
		}
	}

	// Then cancel all coroutines
	for (uint32_t i = 0; i < async_scope->coroutines.length; ++i) {
		async_coroutine_t *scope_coroutine = async_scope->coroutines.data[i];
		ZEND_ASYNC_CANCEL_EX(&scope_coroutine->coroutine, is_cancellation ? exception : NULL, false, is_safely);
		if (UNEXPECTED(EG(exception))) {
			critical_exception = zend_exception_merge(critical_exception, true, true);
		}
	}

	// When the Scope is already in a cancelled state,
	// we now notify all listeners about it, passing them the error.
	ZEND_ASYNC_CALLBACKS_NOTIFY(&async_scope->scope.event, NULL, exception);
	if (UNEXPECTED(EG(exception))) {
		critical_exception = zend_exception_merge(critical_exception, true, true);
	}

	if (UNEXPECTED(critical_exception)) {
		async_rethrow_exception(critical_exception);
		goto exit_false;
	}

	if (ZEND_ASYNC_EVENT_IS_EXCEPTION_HANDLED(&async_scope->scope.event)) {
		goto exit_true;
	}

	// If it’s the exception-catching mode, we keep moving up the hierarchy.
	if (false == is_cancellation && async_scope->scope.parent_scope != NULL) {
		zend_async_scope_t *parent_scope = async_scope->scope.parent_scope;

		return parent_scope->catch_or_cancel(
			parent_scope,
			coroutine,
			&async_scope->scope,
			exception,
			transfer_error,
			is_safely,
			false
		);
	}

exit_false:
	result = false;
exit_true:

	if (transfer_error) {
		OBJ_RELEASE(exception);
	}

	return result;
}

static bool scope_try_to_dispose(zend_async_scope_t *scope)
{
	async_scope_t *async_scope = (async_scope_t *) scope;

	if (false == SCOPE_CAN_BE_DISPOSED(async_scope)) {
		return false;
	}

	// Dispose all child scopes
	for (uint32_t i = 0; i < async_scope->scope.scopes.length; ++i) {
		async_scope_t *child_scope = (async_scope_t *) async_scope->scope.scopes.data[i];
		child_scope->scope.event.dispose(&child_scope->scope.event);
		if (UNEXPECTED(EG(exception))) {
			// If an exception occurs during child scope disposal, we stop further processing
			return false;
		}
	}

	// Dispose the scope
	async_scope->scope.event.dispose(&async_scope->scope.event);
	return true;
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
	uint32_t scope_id = 0;

	if (scope->scope.scope_object != NULL) {
		scope_id = scope->scope.scope_object->handle;
	}

	if (scope->filename != NULL) {
		return zend_strpprintf(0, "Scope #%d created at %s:%d", scope_id, ZSTR_VAL(scope->filename), scope->lineno);
	} else {
		return zend_strpprintf(0, "Scope #%d", scope_id);
	}
}

static void scope_dispose(zend_async_event_t *scope_event)
{
	if (ZEND_ASYNC_EVENT_REF(scope_event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(scope_event);
		return;
	}

	if (ZEND_ASYNC_EVENT_REF(scope_event) == 1) {
		ZEND_ASYNC_EVENT_DEL_REF(scope_event);
	}

	async_scope_t *scope = (async_scope_t *) scope_event;

	ZEND_ASSERT(scope->coroutines.length == 0 && scope->scope.scopes.length == 0
		&& "Scope should be empty before disposal");

	if (scope->finally_handlers != NULL
		&& zend_hash_num_elements(scope->finally_handlers) > 0
		&& async_scope_call_finally_handlers(scope)) {
		// If finally handlers were called, we don't dispose the scope yet
		ZEND_ASYNC_EVENT_ADD_REF(&scope->scope.event);
		return;
	}

	if (scope->scope.parent_scope) {
		zend_async_scope_remove_child(scope->scope.parent_scope, &scope->scope);
	}

	// Clear weak reference from context to scope
	if (scope->scope.context != NULL) {
		async_context_t *context = (async_context_t *) scope->scope.context;
		context->scope = NULL;
	}

	if (scope->scope.scope_object != NULL) {
		((async_scope_object_t *) scope->scope.scope_object)->scope = NULL;
		scope->scope.scope_object = NULL;
	}

	// Free spawn location filename
	if (scope->filename != NULL) {
		zend_string_release(scope->filename);
		scope->filename = NULL;
	}
	
	// Free exception handlers
	if (scope->exception_fci != NULL || scope->exception_fcc != NULL) {
		zend_free_fci(scope->exception_fci, scope->exception_fcc);
		scope->exception_fci = NULL;
		scope->exception_fcc = NULL;
	}
	if (scope->child_exception_fci != NULL || scope->child_exception_fcc != NULL) {
		zend_free_fci(scope->child_exception_fci, scope->child_exception_fcc);
		scope->child_exception_fci = NULL;
		scope->child_exception_fcc = NULL;
	}
	
	// Free finally handlers
	if (scope->finally_handlers != NULL) {
		zend_hash_destroy(scope->finally_handlers);
		FREE_HASHTABLE(scope->finally_handlers);
		scope->finally_handlers = NULL;
	}
	
	async_scope_free_coroutines(scope);
	zend_async_scope_free_children(&scope->scope);
	efree(scope);
}

zend_async_scope_t * async_new_scope(zend_async_scope_t * parent_scope, const bool with_zend_object)
{
	async_scope_t *scope = ecalloc(1, sizeof(async_scope_t));
	async_scope_object_t *scope_object = NULL;
	scope->scope.scope_object = NULL;

	if (with_zend_object) {
		scope_object = ZEND_OBJECT_ALLOC_EX(sizeof(async_scope_object_t), async_ce_scope);

		zend_object_std_init(&scope_object->std, async_ce_scope);
		object_properties_init(&scope_object->std, async_ce_scope);

		if (UNEXPECTED(EG(exception))) {
			efree(scope);
			OBJ_RELEASE(&scope_object->std);
			return NULL;
		}

		scope_object->scope = scope;
		scope->scope.scope_object = &scope_object->std;
	}

	scope->scope.parent_scope = parent_scope;
	zend_async_event_t *event = &scope->scope.event;

	event->ref_count = 1; // Initialize reference count

	scope->scope.before_coroutine_enqueue = scope_before_coroutine_enqueue;
	scope->scope.after_coroutine_enqueue = scope_after_coroutine_enqueue;
	scope->scope.catch_or_cancel = scope_catch_or_cancel;
	scope->scope.try_to_dispose = scope_try_to_dispose;
	scope->coroutines.length = 0;
	scope->coroutines.capacity = 0;
	scope->coroutines.data = NULL;
	scope->active_coroutines_count = 0;
	
	// Initialize spawn location
	scope->filename = NULL;
	scope->lineno = 0;
	zend_apply_current_filename_and_line(&scope->filename, &scope->lineno);
	
	// Initialize exception handlers
	scope->exception_fci = NULL;
	scope->exception_fcc = NULL;
	scope->child_exception_fci = NULL;
	scope->child_exception_fcc = NULL;
	
	// Initialize finally handlers
	scope->finally_handlers = NULL;

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
	zend_async_scope_t *scope = async_new_scope(NULL, true);

	if (UNEXPECTED(scope == NULL)) {
		return NULL;
	}

	return scope->scope_object;
}

static void scope_destroy(zend_object *object)
{
	async_scope_object_t *scope_object = (async_scope_object_t *) object;

	if (scope_object->scope == NULL) {
		return;
	}

	async_scope_t *scope = scope_object->scope;
	scope_object->scope = NULL;
	scope->scope.scope_object = NULL;

	// At this point, the user-defined Scope object is about to be destroyed.
	// This means we are obligated to cancel the Scope and all its child Scopes along with their coroutines.
	// However, the Scope itself will not be destroyed.
	if (false == scope->scope.try_to_dispose(&scope->scope)) {
		zend_object *exception = async_new_exception(
			async_ce_cancellation_exception, "Scope is being disposed due to object destruction"
		);

		ZEND_ASYNC_SCOPE_CANCEL(&scope->scope, exception, true, ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(&scope->scope));
	}
}

void async_register_scope_ce(void)
{
	async_ce_scope_provider = register_class_Async_ScopeProvider();
	async_ce_spawn_strategy = register_class_Async_SpawnStrategy(async_ce_scope_provider);
	async_ce_scope = register_class_Async_Scope(async_ce_scope_provider);

	async_ce_scope->create_object = scope_object_create;
	async_ce_scope->default_object_handlers = &async_scope_handlers;

	async_scope_handlers = std_object_handlers;

	async_scope_handlers.clone_obj = NULL;
	async_scope_handlers.dtor_obj = scope_destroy;
}

/**
 * Recursively checks if a scope and all its children are completed.
 * A scope is considered completed when:
 * - It is closed OR
 * - It has no active coroutines AND all child scopes are also completed
 */
static bool scope_can_be_disposed(async_scope_t *scope, bool with_zombies, bool can_be_disposed)
{
	if (scope == NULL) {
		return true;
	}

	// If scope is closed or cancelled, it's considered completed
	if (false == can_be_disposed
		&& (ZEND_ASYNC_SCOPE_IS_CLOSED(&scope->scope) || ZEND_ASYNC_SCOPE_IS_CANCELLED(&scope->scope))) {
		return true;
	}

	// If scope has active coroutines, it's not completed
	if ((with_zombies ? (scope->active_coroutines_count + scope->zombie_coroutines_count) : scope->active_coroutines_count) > 0) {
		return false;
	}

	// If a Scope has no active coroutines,
	// it can be disposed provided that it is either cancelled or
	// its Zend object has already been destroyed.
	if (can_be_disposed
		&& false == (ZEND_ASYNC_SCOPE_IS_CANCELLED(&scope->scope) || scope->scope.scope_object == NULL)) {
		return false;
	}

	// Check if all child scopes are completed
	for (uint32_t i = 0; i < scope->scope.scopes.length; i++) {
		async_scope_t *child_scope = (async_scope_t *) scope->scope.scopes.data[i];

		if (false == scope_can_be_disposed(child_scope, with_zombies, can_be_disposed)) {
			return false;
		}
	}

	return true;
}

/**
 * Checks if scope is completed and notifies waiting callbacks.
 * If scope is completed, also recursively checks parent scopes
 * to handle cascade completion events up the hierarchy.
 */
static void scope_check_completion_and_notify(async_scope_t *scope, bool with_zombies)
{
	if (scope == NULL) {
		return;
	}

	// Check if current scope is completed
	if (SCOPE_IS_COMPLETELY_DONE(scope)) {
		// Notify waiting callbacks for this scope
		ZEND_ASYNC_CALLBACKS_NOTIFY(&scope->scope.event, NULL, NULL);

		// Recursively check parent scope
		if (scope->scope.parent_scope != NULL) {
			async_scope_t *parent_scope = (async_scope_t *) scope->scope.parent_scope;
			scope_check_completion_and_notify(parent_scope, with_zombies);
		}
	}
}

static zend_always_inline bool try_to_handle_exception(
	async_scope_t *current_scope, async_scope_t *from_scope, zend_object *exception, async_coroutine_t *coroutine
)
{
	// Fast return:
	if (current_scope->exception_fci == NULL && current_scope->child_exception_fci == NULL) {
		return false; // No exception handlers defined
	}

	if (ZEND_ASYNC_IS_SCHEDULER_CONTEXT || coroutine == NULL) {

		// Handlers from userland PHP can’t be invoked from the microtask context,
		// only from within a coroutine.
		// So if we need to handle an exception,
		// we’ll have to create a special coroutine where the exception is thrown,
		// and only then handle it…
		if (ZEND_ASYNC_SPAWN_AND_THROW(exception, from_scope ? &from_scope->scope : &current_scope->scope, 1)) {
			return true;
		}
	}


	// Prototype: function (Async\Scope $scope, Async\Coroutine $coroutine, Throwable $e)
	zval retval;
	zval parameters[3];
	ZVAL_UNDEF(&retval);
	ZVAL_OBJ(&parameters[0], current_scope->scope.scope_object);
	ZVAL_OBJ(&parameters[1], &coroutine->std);
	ZVAL_OBJ(&parameters[2], exception);

	// If the exception came from another child Scope,
	// we first try to handle it using the child Scope exception handler.
	if (from_scope != NULL && current_scope->child_exception_fci != NULL && current_scope->child_exception_fcc != NULL) {

		zend_fcall_info *exception_fci = current_scope->child_exception_fci;

		exception_fci->retval = &retval;
		exception_fci->param_count = 3;
		exception_fci->params = &parameters[0];

		zend_result result = zend_call_function(exception_fci, current_scope->child_exception_fcc);
		Z_TRY_DELREF(retval);
		exception_fci->retval = NULL;
		exception_fci->param_count = 0;
		exception_fci->params = NULL;

		if (result == SUCCESS && EG(exception) == NULL) {
			return true;
		}
	}

	// Second attempt is to handle the exception using the current Scope's exception handler.
	if (current_scope->exception_fci != NULL && current_scope->exception_fcc != NULL) {

		zend_fcall_info *exception_fci = current_scope->exception_fci;

		exception_fci->retval = &retval;
		exception_fci->param_count = 3;
		exception_fci->params = &parameters[0];

		zend_result result = zend_call_function(exception_fci, current_scope->exception_fcc);
		Z_TRY_DELREF(retval);
		exception_fci->retval = NULL;
		exception_fci->param_count = 0;
		exception_fci->params = NULL;

		if (result == SUCCESS && EG(exception) == NULL) {
			return true;
		}
	}

	return false; // Exception not handled
}

static void async_scope_call_finally_handlers_dtor(finally_handlers_context_t *context)
{
	zend_async_scope_t *scope = context->target;
	if (ZEND_ASYNC_EVENT_REF(&scope->event) > 0) {
		ZEND_ASYNC_EVENT_DEL_REF(&scope->event);
	}

	scope->try_to_dispose(scope);
	context->target = NULL;
}

static bool async_scope_call_finally_handlers(async_scope_t *scope)
{
	if (scope->finally_handlers == NULL || zend_hash_num_elements(scope->finally_handlers) == 0) {
		return false;
	}

	HashTable *finally_handlers = scope->finally_handlers;
	scope->finally_handlers = NULL;
	finally_handlers_context_t *finally_context = ecalloc(1, sizeof(finally_handlers_context_t));
	finally_context->target = scope;
	finally_context->scope = &scope->scope;
	finally_context->dtor = async_scope_call_finally_handlers_dtor;
	finally_context->params_count = 1;

	if (scope->scope.scope_object != NULL) {
		ZVAL_OBJ(&finally_context->params[0], scope->scope.scope_object);
	} else {
		ZVAL_NULL(&finally_context->params[0]);
	}

	if (false == async_call_finally_handlers(finally_handlers, finally_context, 1)) {
		efree(finally_context);
		zend_array_destroy(finally_handlers);
		return false;
	} else {
		ZEND_ASYNC_EVENT_ADD_REF(&scope->scope.event);
		return true;
	}
}