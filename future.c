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

#include "future.h"
#include "php_async.h"
#include "exceptions.h"
#include "future_arginfo.h"
#include "zend_exceptions.h"
#include "zend_closures.h"
#include "zend_common.h"
#include "scheduler.h"
#include "iterator.h"
#include "zend_smart_str.h"

///////////////////////////////////////////////////////////
/// Architecture Overview
///////////////////////////////////////////////////////////
/*
 * Future Module Architecture
 * ==========================
 *
 * This module implements Future pattern on top of the core zend_future_t event.
 *
 * Core Layer (Zend/zend_async_API.h):
 * ------------------------------------
 * zend_future_t - Low-level event container
 *   - Stores result/exception
 *   - Manages event lifecycle (start/stop)
 *   - Maintains callback list
 *   - NO business logic, only event mechanics
 *
 * Module Layer (ext/async/):
 * --------------------------
 * FutureState (async_future_state_t) - Mutable container
 *   - PHP object wrapper around zend_future_t
 *   - Provides complete() and error() methods
 *   - Owns the underlying zend_future_t
 *
 * Future (async_future_t) - Readonly container with transformation chain
 *   - PHP object wrapper around the SAME zend_future_t
 *   - Provides map(), catch(), finally(), await() methods
 *   - Stores child_futures created by transformations
 *   - Stores mapper callable (if this Future is a child)
 *   - Subscribes to zend_future_t via callback mechanism
 *
 * Object Ownership Scheme:
 * ------------------------
 *
 *   FutureState (PHP object)
 *       │
 *       │ owns (ref_count)
 *       ▼
 *   zend_future_t (core event)  ◄────┐
 *       │                            │ references (callback subscription)
 *       │ has callbacks              │
 *       │                            │
 *       ├─► callback 1               │
 *       ├─► callback 2               │
 *       └─► Future callback ─────────┘
 *               │
 *               │ when triggered
 *               ▼
 *           Future (PHP object)
 *               │
 *               │ owns
 *               ▼
 *           child_futures[] ──► [Future2, Future3, ...]
 *
 * Event Flow:
 * -----------
 * 1. FutureState->complete() called
 *    └─► zend_future_t->stop() triggered (core)
 *        └─► ZEND_ASYNC_CALLBACKS_NOTIFY() (core - simple iteration)
 *            └─► Future callback handler invoked (module)
 *                └─► async_iterator_run_in_coroutine() (module)
 *                    └─► Process each child future in SEPARATE coroutine
 *                        └─► Call mapper callable
 *                            └─► Resolve child future
 *
 * Key Design Principles:
 * ----------------------
 * - Core (zend_future_t) has NO knowledge of Future/FutureState
 * - Core only manages event lifecycle and callback notification
 * - Module implements business logic via callback subscription
 * - Child futures are processed in separate coroutine (NOT synchronously!)
 * - Iterator ensures proper async execution without blocking
 */

#define FUTURE_METHOD(name) PHP_METHOD(Async_Future, name)
#define FUTURE_STATE_METHOD(name) PHP_METHOD(Async_FutureState, name)

#define SCHEDULER_LAUNCH if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL)) {		\
		async_scheduler_launch();														\
		if (UNEXPECTED(EG(exception) != NULL)) {										\
			RETURN_THROWS();															\
		}																				\
	}


zend_class_entry *async_ce_future_state = NULL;
zend_class_entry *async_ce_future = NULL;

static zend_object_handlers async_future_state_handlers;
static zend_object_handlers async_future_handlers;

///////////////////////////////////////////////////////////
/// Future callback structure
///////////////////////////////////////////////////////////

/**
 * Callback structure for Future object.
 * When parent future completes, this callback processes child futures.
 */
typedef struct {
    zend_async_event_callback_t base;
    async_future_t *future_obj;
} async_future_callback_t;

/**
 * Context structure for processing a single mapper in a coroutine.
 * Used when source future is already completed.
 */
typedef struct {
    async_future_t *child_future_obj;
    zend_future_t *parent_future;
} future_mapper_context_t;

/* Forward declarations */
static void process_future_mapper(zend_future_t *parent_future, async_future_t *child_future_obj);
static zend_result future_mappers_handler(async_iterator_t *iterator, zval *current, zval *key);
static void async_future_callback_handler(zend_async_event_t *event, zend_async_event_callback_t *callback, void *result, zend_object *exception);
static void async_future_callback_dispose(zend_async_event_callback_t *callback, zend_async_event_t *event);
static void async_future_create_mapper(INTERNAL_FUNCTION_PARAMETERS, async_future_mapper_type_t mapper_type);
static async_future_t *async_future_create_internal(void);
static void future_mapper_coroutine_entry(void);
static void future_mapper_context_dispose(zend_coroutine_t *coroutine);

///////////////////////////////////////////////////////////
/// FutureState event functions (like coroutine)
///////////////////////////////////////////////////////////

static bool future_state_event_start(zend_async_event_t *event)
{
    /* Nothing to start for FutureState */
    return true;
}

/**
 * The method that is called to resolve the Future (complete or reject).
 * This method must be triggered only once.
 * This is the proper method for completing futures, NOT stop().
 */
static bool future_state_resolve(zend_async_event_t *event)
{
    if (ZEND_ASYNC_EVENT_IS_CLOSED(event)) {
        return true;
    }

    ZEND_ASYNC_EVENT_SET_CLOSED(event);

    zend_future_t *future = (zend_future_t *)event;

    // Invocation of internal handlers.
    // Attention: these handlers must not call user-land PHP functions,
    // as this would violate the rule that PHP code must run only inside coroutines.
    ZEND_ASYNC_CALLBACKS_NOTIFY(event, &future->result, future->exception);

    return true;
}

/**
 * Standard event stop method.
 * For futures, this does nothing - all completion logic is in resolve().
 */
static bool future_state_event_stop(zend_async_event_t *event)
{
    return true;
}

static bool future_state_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
    return zend_async_callbacks_push(event, callback);
}

static bool future_state_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
    return zend_async_callbacks_remove(event, callback);
}

static bool future_state_replay(zend_async_event_t *event, zend_async_event_callback_t *callback, zval *result, zend_object **exception)
{
    zend_future_t *future = (zend_future_t *) event;

    if (!ZEND_FUTURE_IS_COMPLETED(future)) {
        return false;
    }

    if (callback != NULL) {
        callback->callback(event, callback, &future->result, future->exception);
    } else if (result != NULL) {
        // Extract result for await
        if (future->exception != NULL) {
            if (exception != NULL) {
                *exception = future->exception;
                GC_ADDREF(future->exception);
            } else {
                zval ex;
                ZVAL_OBJ(&ex, future->exception);
                GC_ADDREF(future->exception);
                zend_throw_exception_object(&ex);
            }
        } else {
            ZVAL_COPY(result, &future->result);
        }
    }

	return EG(exception) == NULL;
}

static zend_string* future_state_info(zend_async_event_t *event)
{
    const zend_future_t *future = (zend_future_t *) event;

    return zend_strpprintf(0, "FutureState(%s)", ZEND_FUTURE_IS_COMPLETED(future) ? "completed" : "pending");
}

static bool future_state_dispose(zend_async_event_t *event)
{
    zend_future_t *future = (zend_future_t *) event;
    
    zval_ptr_dtor(&future->result);
    
    if (future->exception != NULL) {
        OBJ_RELEASE(future->exception);
        future->exception = NULL;
    }
    
    if (future->filename != NULL) {
        zend_string_release(future->filename);
        future->filename = NULL;
    }
    
    if (future->completed_filename != NULL) {
        zend_string_release(future->completed_filename);
        future->completed_filename = NULL;
    }

    zend_async_callbacks_free(event);
    
    efree(future);
    
    return true;
}

static zend_always_inline void init_future_state_event(zend_async_event_t *event)
{
    zend_future_t *future = (zend_future_t *)event;

    event->start = future_state_event_start;
    event->stop = future_state_event_stop;
    event->add_callback = future_state_add_callback;
    event->del_callback = future_state_del_callback;
    event->replay = future_state_replay;
    event->info = future_state_info;
    event->dispose = future_state_dispose;
    event->ref_count = 1;

    /* Set the resolve method for completing the future */
    future->resolve = future_state_resolve;
}

///////////////////////////////////////////////////////////
/// FutureState object lifecycle
///////////////////////////////////////////////////////////

static zend_object *async_future_state_object_create(zend_class_entry *ce)
{
    async_future_state_t *state = zend_object_alloc(sizeof(async_future_state_t), ce);

    // Internal future object
    zend_future_t *future = ecalloc(1, sizeof(zend_future_t));
    zend_async_event_t *event = &future->event;
    ZVAL_UNDEF(&future->result);

    /* Set event handlers */
    init_future_state_event(event);

    ZEND_ASYNC_EVENT_REF_SET(state, XtOffsetOf(async_future_state_t, std), event);
    ZEND_ASYNC_EVENT_SET_ZVAL_RESULT(state->event);

    zend_object_std_init(&state->std, ce);
    object_properties_init(&state->std, ce);
    
    return &state->std;
}

static void async_future_state_object_free(zend_object *object)
{
    async_future_state_t *state = ASYNC_FUTURE_STATE_FROM_OBJ(object);

    zend_future_t *future = (zend_future_t *)state->event;
    state->event = NULL;

    if (future != NULL) {
        ZEND_ASYNC_EVENT_RELEASE(&future->event);
    }

    zend_object_std_dtor(&state->std);
}

///////////////////////////////////////////////////////////
/// Future object lifecycle
///////////////////////////////////////////////////////////

static zend_object *async_future_object_create(zend_class_entry *ce)
{
    async_future_t *future = zend_object_alloc(sizeof(async_future_t), ce);

    ZEND_ASYNC_EVENT_REF_SET(future, XtOffsetOf(async_future_t, std), NULL);

    future->child_futures = NULL;
    ZVAL_UNDEF(&future->mapper);
    future->mapper_type = ASYNC_FUTURE_MAPPER_SUCCESS;

    zend_object_std_init(&future->std, ce);
    object_properties_init(&future->std, ce);

    return &future->std;
}

static void async_future_object_free(zend_object *object)
{
    async_future_t *future = ASYNC_FUTURE_FROM_OBJ(object);

    if (future->child_futures != NULL) {
        zend_array_destroy(future->child_futures);
        future->child_futures = NULL;
    }

    zval_ptr_dtor(&future->mapper);

    zend_future_t *zend_future = (zend_future_t *)future->event;
    future->event = NULL;

    if (zend_future != NULL) {
        ZEND_ASYNC_EVENT_RELEASE(&zend_future->event);
    }

    zend_object_std_dtor(&future->std);
}

///////////////////////////////////////////////////////////
/// FutureState methods
///////////////////////////////////////////////////////////

#define THIS_FUTURE_STATE ((async_future_state_t *) ASYNC_FUTURE_STATE_FROM_OBJ(Z_OBJ_P(ZEND_THIS)))

FUTURE_STATE_METHOD(__construct)
{
    ZEND_PARSE_PARAMETERS_NONE();
}

FUTURE_STATE_METHOD(complete)
{
    zval *result;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(result)
    ZEND_PARSE_PARAMETERS_END();

    const async_future_state_t *state = THIS_FUTURE_STATE;

    if (state->event == NULL) {
        async_throw_error("FutureState is already destroyed");
        RETURN_THROWS();
    }

    zend_future_t *future = (zend_future_t *)state->event;

    if (ZEND_FUTURE_IS_COMPLETED(future)) {
        if (future->completed_filename != NULL) {
            async_throw_error("FutureState is already completed at %s:%d", ZSTR_VAL(future->completed_filename), future->completed_lineno);
        } else {
            async_throw_error("FutureState is already completed at Unknown:0");
        }

        RETURN_THROWS();
    }

    ZEND_FUTURE_COMPLETE(future, result);
}

FUTURE_STATE_METHOD(error)
{
    zval *throwable;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(throwable, zend_ce_throwable)
    ZEND_PARSE_PARAMETERS_END();

    const async_future_state_t *state = THIS_FUTURE_STATE;
    
    if (state->event == NULL) {
        async_throw_error("FutureState is already destroyed");
        RETURN_THROWS();
    }

    zend_future_t *future = (zend_future_t *)state->event;

    if (ZEND_FUTURE_IS_COMPLETED(future)) {
        if (future->completed_filename != NULL) {
            async_throw_error("FutureState is already completed at %s:%d", ZSTR_VAL(future->completed_filename), future->completed_lineno);
        } else {
            async_throw_error("FutureState is already completed at Unknown:0");
        }

        RETURN_THROWS();
    }

    ZEND_FUTURE_REJECT(future, Z_OBJ_P(throwable));
}

FUTURE_STATE_METHOD(isCompleted)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_state_t *state = THIS_FUTURE_STATE;
    const zend_future_t *future = (zend_future_t *)state->event;

    if (UNEXPECTED(future == NULL)) {
        RETURN_TRUE;
    }
    
    RETURN_BOOL(ZEND_FUTURE_IS_COMPLETED(future));
}

FUTURE_STATE_METHOD(ignore)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_state_t *state = THIS_FUTURE_STATE;
    zend_future_t *future = (zend_future_t *)state->event;

    if (UNEXPECTED(future == NULL)) {
        async_throw_error("FutureState is already destroyed");
        RETURN_THROWS();
    }
    
    ZEND_FUTURE_SET_IGNORED(future);
}

FUTURE_STATE_METHOD(getAwaitingInfo)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_state_t *state = THIS_FUTURE_STATE;
    zend_future_t *future = (zend_future_t *)state->event;

    if (UNEXPECTED(future == NULL)) {
        RETURN_EMPTY_ARRAY();
    }

    zend_string *state_info = future->event.info(&future->event);
    zval z_state_info;
    ZVAL_STR(&z_state_info, state_info);
    // new array zend array
    zend_array *info = zend_new_array(0);
    zend_hash_index_add_new(info, 0, &z_state_info);

    RETURN_ARR(info);
}

///////////////////////////////////////////////////////////
/// Future methods
///////////////////////////////////////////////////////////

#define THIS_FUTURE ((async_future_t *) ASYNC_FUTURE_FROM_OBJ(Z_OBJ_P(ZEND_THIS)))

FUTURE_METHOD(__construct)
{
    zval *state_obj;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(state_obj, async_ce_future_state)
    ZEND_PARSE_PARAMETERS_END();

    async_future_t *future = THIS_FUTURE;
    const async_future_state_t *state = ASYNC_FUTURE_STATE_FROM_OBJ(Z_OBJ_P(state_obj));

    if (UNEXPECTED(state->event == NULL)) {
        async_throw_error("FutureState is already destroyed");
        RETURN_THROWS();
    }

    future->event = state->event;
    ZEND_ASYNC_EVENT_ADD_REF(state->event);

    ZEND_ASYNC_EVENT_REF_SET(future, XtOffsetOf(async_future_t, std), state->event);
}

FUTURE_METHOD(completed)
{
    zval *value = NULL;
    
    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();

    // Create FutureState
    async_future_state_t *state = async_future_state_create();
    
    if (state == NULL) {
        RETURN_THROWS();
    }
    
    zend_future_t *future = (zend_future_t *)state->event;
    
    // Complete it immediately
    if (value != NULL) {
        ZEND_FUTURE_COMPLETE(future, value);
    } else {
        zval null_val;
        ZVAL_NULL(&null_val);
        ZEND_FUTURE_COMPLETE(future, &null_val);
    }
    
    object_init_ex(return_value, async_ce_future);

    zval args[1];
    ZVAL_OBJ(&args[0], &state->std);

    zend_call_method_with_1_params(Z_OBJ_P(return_value), async_ce_future, NULL, "__construct", NULL, &args[0]);

    OBJ_RELEASE(&state->std);
}

FUTURE_METHOD(failed)
{
    zval *throwable;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(throwable, zend_ce_throwable)
    ZEND_PARSE_PARAMETERS_END();

    // Create FutureState
    async_future_state_t *state = async_future_state_create();
    
    if (state == NULL) {
        RETURN_THROWS();
    }
    
    zend_future_t *future = (zend_future_t *)state->event;
    
    // Reject it immediately
    ZEND_FUTURE_REJECT(future, Z_OBJ_P(throwable));
    
    object_init_ex(return_value, async_ce_future);

    zval args[1];
    ZVAL_OBJ(&args[0], &state->std);

    zend_call_method_with_1_params(Z_OBJ_P(return_value), async_ce_future, NULL, "__construct", NULL, &args[0]);

    OBJ_RELEASE(&state->std);
}

FUTURE_METHOD(isCompleted)
{
    ZEND_PARSE_PARAMETERS_NONE();
    
    async_future_t *future = THIS_FUTURE;
    
    if (future->event == NULL) {
        RETURN_FALSE;
    }
    
    zend_future_t *state = (zend_future_t *)future->event;

    RETURN_BOOL(ZEND_FUTURE_IS_COMPLETED(state));
}

FUTURE_METHOD(isCancelled)
{
    ZEND_PARSE_PARAMETERS_NONE();

    async_future_t *future = THIS_FUTURE;

    if (future->event == NULL) {
        RETURN_FALSE;
    }

    zend_future_t *state = (zend_future_t *)future->event;

    RETURN_BOOL(ZEND_FUTURE_IS_COMPLETED(state) && state->exception != NULL
        && instanceof_function(state->exception->ce, async_ce_cancellation_exception));
}

FUTURE_METHOD(cancel)
{
    zend_object *cancellation = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_cancellation_exception)
    ZEND_PARSE_PARAMETERS_END();

    async_future_t *future = THIS_FUTURE;

    if (future->event == NULL) {
        async_throw_error("Future has no state");
        RETURN_THROWS();
    }

    zend_future_t *state = (zend_future_t *)future->event;

    if (ZEND_FUTURE_IS_COMPLETED(state)) {
        return;
    }

    if (cancellation == NULL) {
        cancellation = async_new_exception(async_ce_cancellation_exception, "Future has been cancelled");
    } else {
        GC_ADDREF(cancellation);
    }

    ZEND_FUTURE_REJECT(state, cancellation);
    OBJ_RELEASE(cancellation);
}

FUTURE_METHOD(ignore)
{
    ZEND_PARSE_PARAMETERS_NONE();

    async_future_t *future = THIS_FUTURE;

    if (future->event != NULL) {
        zend_future_t *state = (zend_future_t *)future->event;
        ZEND_FUTURE_SET_IGNORED(state);
    }

    RETURN_ZVAL(ZEND_THIS, 1, 0);
}

FUTURE_METHOD(await)
{
    zend_object * cancellation = NULL;
    
    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_completable)
    ZEND_PARSE_PARAMETERS_END();

    SCHEDULER_LAUNCH;

    zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

    if (UNEXPECTED(coroutine == NULL)) {
        RETURN_NULL();
    }

    const async_future_t *future = THIS_FUTURE;
    
    if (future->event == NULL) {
        async_throw_error("Future has no state");
        RETURN_THROWS();
    }

    zend_async_event_t *event = future->event;

    if (ZEND_ASYNC_EVENT_IS_CLOSED(event)) {

        if (ZEND_ASYNC_EVENT_EXTRACT_RESULT(event, return_value)) {
            return;
        }

        RETURN_NULL();
    }

    zend_async_event_t *cancellation_event = cancellation != NULL ? ZEND_ASYNC_OBJECT_TO_EVENT(cancellation) : NULL;

    // If the cancellation event is already resolved, we can return exception immediately.
    if (cancellation_event != NULL && ZEND_ASYNC_EVENT_IS_CLOSED(cancellation_event)) {
        if (ZEND_ASYNC_EVENT_EXTRACT_RESULT(cancellation_event, return_value)) {
            return;
        }

        async_throw_cancellation("Future awaiting has been cancelled");
        RETURN_THROWS();
    }

    zend_async_waker_new(coroutine);

    if (UNEXPECTED(EG(exception) != NULL)) {
        RETURN_THROWS();
    }

    zend_async_resume_when(
        coroutine,
        event,
        false,
        zend_async_waker_callback_resolve,
        NULL
    );

    if (UNEXPECTED(EG(exception) != NULL)) {
        RETURN_THROWS();
    }

    if (cancellation_event != NULL) {
        zend_async_resume_when(
            coroutine,
            cancellation_event,
            false,
            zend_async_waker_callback_cancel,
            NULL
        );

        if (UNEXPECTED(EG(exception) != NULL)) {
            RETURN_THROWS();
        }
    }

    ZEND_ASYNC_SUSPEND();

    if (UNEXPECTED(EG(exception) != NULL)) {
        RETURN_THROWS();
    }

    ZEND_ASSERT(coroutine->waker != NULL && "coroutine->waker must not be NULL");

    if (Z_TYPE(coroutine->waker->result) == IS_UNDEF) {
        ZVAL_NULL(return_value);
    } else {
        ZVAL_COPY(return_value, &coroutine->waker->result);
    }

    zend_async_waker_destroy(coroutine);
}

///////////////////////////////////////////////////////////
/// Mapper implementation
///////////////////////////////////////////////////////////

/**
 * Common function to process a single mapper.
 * Calls the mapper callback and completes the child future with the result.
 *
 * @param parent_future The parent future that has completed
 * @param child_future_obj The child future object to be completed
 */
static void process_future_mapper(zend_future_t *parent_future, async_future_t *child_future_obj)
{
	zend_future_t *child_future = (zend_future_t *)child_future_obj->event;

	if (UNEXPECTED(child_future == NULL || ZEND_FUTURE_IS_COMPLETED(child_future))) {
		return;
	}

	zval result_value;
	ZVAL_UNDEF(&result_value);
	zend_object *fallback_exception = NULL;
	zval args[1];
	uint32_t arg_count = 0;
	bool should_call = true;

	switch (child_future_obj->mapper_type) {
		case ASYNC_FUTURE_MAPPER_SUCCESS:
			if (parent_future->exception != NULL) {
				fallback_exception = parent_future->exception;
				should_call = false;
			} else {
				ZVAL_COPY(&args[0], &parent_future->result);
				arg_count = 1;
			}
			break;

		case ASYNC_FUTURE_MAPPER_CATCH:
			if (parent_future->exception == NULL) {
				result_value = parent_future->result;
				should_call = false;
			} else {
				ZVAL_OBJ(&args[0], parent_future->exception);
				GC_ADDREF(parent_future->exception);
				arg_count = 1;
			}
			break;

		case ASYNC_FUTURE_MAPPER_FINALLY:
			arg_count = 0;
			result_value = parent_future->result;
			fallback_exception = parent_future->exception;
			break;
	}

	if (should_call) {
		call_user_function(NULL, NULL, &child_future_obj->mapper, &result_value, arg_count, arg_count > 0 ? args : NULL);
		if (arg_count > 0) {
			zval_ptr_dtor(&args[0]);
		}
	}

    if (UNEXPECTED(EG(exception) != NULL)) {

        if (fallback_exception) {
            zend_exception_set_previous(EG(exception), fallback_exception);
        }

        fallback_exception = EG(exception);
        GC_ADDREF(fallback_exception);
        zend_clear_exception();
    }

	if (fallback_exception != NULL) {
	    ZEND_ASYNC_EVENT_CLR_EXCEPTION_HANDLED(&child_future->event);
		ZEND_FUTURE_REJECT(child_future, fallback_exception);

	    if (UNEXPECTED(EG(exception) != NULL)) {
	        zend_exception_set_previous(EG(exception), fallback_exception);
	    } else if (UNEXPECTED(false == ZEND_ASYNC_EVENT_IS_EXCEPTION_HANDLED(&child_future->event))) {
	        // We throw the fallback_exception only if it was not handled by anyone.
	        EG(exception) = fallback_exception;
	    }

	} else if (Z_TYPE(result_value) != IS_UNDEF) {
		ZEND_FUTURE_COMPLETE(child_future, &result_value);
	} else {
		zval null_val;
		ZVAL_NULL(&null_val);
		ZEND_FUTURE_COMPLETE(child_future, &null_val);
	}

	zval_ptr_dtor(&result_value);
}

/**
 * Iterator handler that processes child futures when parent resolves.
 * Called once for each child future (from map/catch/finally).
 */
static zend_result future_mappers_handler(async_iterator_t *iterator, zval *current, zval *key)
{
	zend_future_t *parent_future = (zend_future_t *)iterator->extended_data;
	async_future_t *child_future_obj = ASYNC_FUTURE_FROM_OBJ(Z_OBJ_P(current));

	process_future_mapper(parent_future, child_future_obj);

	return SUCCESS;
}

///////////////////////////////////////////////////////////
/// Future callback implementation
///////////////////////////////////////////////////////////

/**
 * Callback handler invoked when parent future completes.
 * Processes all child futures by running iterator in separate coroutine.
 */
static void async_future_callback_handler(
    zend_async_event_t *event,
    zend_async_event_callback_t *callback,
    void *result,
    zend_object *exception
) {
    async_future_callback_t *future_callback = (async_future_callback_t *)callback;
    async_future_t *future_obj = future_callback->future_obj;

    if (future_obj->child_futures == NULL || zend_hash_num_elements(future_obj->child_futures) == 0) {
        return;
    }

    zval children_arr;
    ZVAL_ARR(&children_arr, future_obj->child_futures);
    future_obj->child_futures = NULL;

    async_iterator_t *iterator = async_iterator_new(
        &children_arr,
        NULL,
        NULL,
        future_mappers_handler,
        ZEND_ASYNC_CURRENT_SCOPE,
        0, /* concurrency: default */
        ZEND_COROUTINE_NORMAL, /* priority: default */
        0 /* iterator size: default */
    );

    if (UNEXPECTED(iterator == NULL)) {
        zval_ptr_dtor(&children_arr);
        return;
    }

    zval_ptr_dtor(&children_arr);
    iterator->extended_data = (zend_future_t *)event;
    async_iterator_run_in_coroutine(iterator, 0, true);
}

/**
 * Cleanup callback when it's removed from event.
 */
static void async_future_callback_dispose(
    zend_async_event_callback_t *callback,
    zend_async_event_t *event
) {
    async_future_callback_t *future_callback = (async_future_callback_t *)callback;

    if (future_callback->future_obj != NULL) {
        // No OBJ_RELEASE(&future_callback->future_obj->std);
        future_callback->future_obj = NULL;
    }

    efree(future_callback);
}

///////////////////////////////////////////////////////////
/// Single mapper coroutine (for already completed futures)
///////////////////////////////////////////////////////////

/**
 * Dispose function for mapper context.
 */
static void future_mapper_context_dispose(zend_coroutine_t *coroutine)
{
    if (coroutine->extended_data == NULL) {
        return;
    }

    future_mapper_context_t *context = coroutine->extended_data;
    coroutine->extended_data = NULL;

    if (context->child_future_obj != NULL) {
        OBJ_RELEASE(&context->child_future_obj->std);
        context->child_future_obj = NULL;
    }

    if (context->parent_future != NULL) {
        ZEND_ASYNC_EVENT_RELEASE(&context->parent_future->event);
        context->parent_future = NULL;
    }

    efree(context);
}

/**
 * Coroutine entry point for processing a single mapper.
 * This runs when source future is already completed.
 */
static void future_mapper_coroutine_entry(void)
{
    if (UNEXPECTED(ZEND_ASYNC_CURRENT_COROUTINE == NULL || ZEND_ASYNC_CURRENT_COROUTINE->extended_data == NULL)) {
        async_throw_error("Invalid coroutine context for future mapper");
        return;
    }

    future_mapper_context_t *context = ZEND_ASYNC_CURRENT_COROUTINE->extended_data;
    process_future_mapper(context->parent_future, context->child_future_obj);
    future_mapper_context_dispose(ZEND_ASYNC_CURRENT_COROUTINE);
}

static void async_future_create_mapper(
    INTERNAL_FUNCTION_PARAMETERS,
    async_future_mapper_type_t mapper_type
) {
    zval *callable;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(callable)
    ZEND_PARSE_PARAMETERS_END();

    if (UNEXPECTED(!zend_is_callable(callable, 0, NULL))) {
        async_throw_error("Argument must be a valid callable");
        RETURN_THROWS();
    }

    async_future_t *source = (async_future_t *)THIS_FUTURE;

    if (UNEXPECTED(source->event == NULL)) {
        async_throw_error("Future has no state");
        RETURN_THROWS();
    }

    async_future_t *target_future_obj = async_future_create_internal();
    if (UNEXPECTED(target_future_obj == NULL)) {
        RETURN_THROWS();
    }

    ZVAL_COPY(&target_future_obj->mapper, callable);
    target_future_obj->mapper_type = mapper_type;

    // If source future is already completed, process mapper immediately in a new coroutine
    zend_future_t *source_future = (zend_future_t *)source->event;
    if (ZEND_FUTURE_IS_COMPLETED(source_future)) {

        // Spawn coroutine to process the mapper
        zend_coroutine_t *coroutine = ZEND_ASYNC_SPAWN();
        if (UNEXPECTED(coroutine == NULL)) {
            OBJ_RELEASE(&target_future_obj->std);
            RETURN_THROWS();
        }

        // Create context for the mapper coroutine
        future_mapper_context_t *context = emalloc(sizeof(future_mapper_context_t));
        context->child_future_obj = target_future_obj;
        context->parent_future = source_future;

        // Add refs so they don't get freed while coroutine is running
        ZEND_ASYNC_EVENT_ADD_REF(&source_future->event);

        coroutine->extended_data = context;
        coroutine->internal_entry = future_mapper_coroutine_entry;
        coroutine->extended_dispose = future_mapper_context_dispose;

        // Return the child future immediately
        ZVAL_OBJ(return_value, &target_future_obj->std);
        GC_ADDREF(&target_future_obj->std);
        return;
    }

    if (source->child_futures == NULL) {
        source->child_futures = zend_new_array(0);
        if (UNEXPECTED(source->child_futures == NULL)) {
            OBJ_RELEASE(&target_future_obj->std);
            async_throw_error("Failed to create child futures array");
            RETURN_THROWS();
        }

        async_future_callback_t *callback = ecalloc(1, sizeof(async_future_callback_t));
        callback->base.ref_count = 1;
        callback->base.callback = async_future_callback_handler;
        callback->base.dispose = async_future_callback_dispose;
        callback->future_obj = source;
        // We do not increment the object's reference count because this is a “weak reference”.
        // No GC_ADDREF(&source->std);

        if (UNEXPECTED(!zend_async_callbacks_push(source->event, &callback->base))) {
            ZEND_ASYNC_EVENT_CALLBACK_RELEASE(&callback->base);
            OBJ_RELEASE(&target_future_obj->std);
            async_throw_error("Failed to subscribe to source future");
            RETURN_THROWS();
        }
    }

    zval child_zval;
    ZVAL_OBJ(&child_zval, &target_future_obj->std);

    if (UNEXPECTED(zend_hash_next_index_insert(source->child_futures, &child_zval) == NULL)) {
        OBJ_RELEASE(&target_future_obj->std);
        async_throw_error("Failed to add child future to array");
        RETURN_THROWS();
    }

    ZVAL_OBJ(return_value, &target_future_obj->std);
    GC_ADDREF(&target_future_obj->std);
}

///////////////////////////////////////////////////////////
/// Future transformation methods
///////////////////////////////////////////////////////////

FUTURE_METHOD(map)
{
    async_future_create_mapper(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        ASYNC_FUTURE_MAPPER_SUCCESS
    );
}

FUTURE_METHOD(catch)
{
    async_future_create_mapper(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        ASYNC_FUTURE_MAPPER_CATCH
    );
}

FUTURE_METHOD(finally)
{
    async_future_create_mapper(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        ASYNC_FUTURE_MAPPER_FINALLY
    );
}

FUTURE_METHOD(getAwaitingInfo)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_t *future = THIS_FUTURE;

    if (future->event == NULL) {
        RETURN_EMPTY_ARRAY();
    }

    zend_future_t *state = (zend_future_t *)future->event;
    zend_string *state_info = state->event.info(&state->event);
    zval z_state_info;
    ZVAL_STR(&z_state_info, state_info);

    zend_array *info = zend_new_array(0);
    zend_hash_index_add_new(info, 0, &z_state_info);

    RETURN_ARR(info);
}

///////////////////////////////////////////////////////////
/// Helper functions
///////////////////////////////////////////////////////////

async_future_state_t *async_future_state_create(void)
{
    zend_object *obj = async_future_state_object_create(async_ce_future_state);
    return ASYNC_FUTURE_STATE_FROM_OBJ(obj);
}

/**
 * Create a Future object directly without FutureState wrapper.
 * More memory efficient for internal use (map/catch/finally).
 */
static async_future_t *async_future_create_internal(void)
{
    zend_future_t *future = ecalloc(1, sizeof(zend_future_t));
    zend_async_event_t *event = &future->event;

    ZVAL_UNDEF(&future->result);

    init_future_state_event(event);

    ZEND_ASYNC_EVENT_SET_ZVAL_RESULT(event);

    async_future_t *future_obj = (async_future_t *)zend_object_alloc(sizeof(async_future_t), async_ce_future);

    ZEND_ASYNC_EVENT_REF_SET(future_obj, XtOffsetOf(async_future_t, std), event);

    future_obj->child_futures = NULL;
    ZVAL_UNDEF(&future_obj->mapper);
    future_obj->mapper_type = ASYNC_FUTURE_MAPPER_SUCCESS;

    zend_object_std_init(&future_obj->std, async_ce_future);
    object_properties_init(&future_obj->std, async_ce_future);

    return future_obj;
}

///////////////////////////////////////////////////////////
/// API function implementations
///////////////////////////////////////////////////////////

///////////////////////////////////////////////////////////
/// Class registration
///////////////////////////////////////////////////////////

void async_register_future_ce(void)
{
    /* Register FutureState class using generated registration */
    async_ce_future_state = register_class_Async_FutureState();
    async_ce_future_state->create_object = async_future_state_object_create;
    
    memcpy(&async_future_state_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    async_future_state_handlers.offset = XtOffsetOf(async_future_state_t, std);
    async_future_state_handlers.free_obj = async_future_state_object_free;
    async_ce_future_state->default_object_handlers = &async_future_state_handlers;

    /* Register Future class using generated registration */
    async_ce_future = register_class_Async_Future(async_ce_completable);
    async_ce_future->create_object = async_future_object_create;

    memcpy(&async_future_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    async_future_handlers.offset = XtOffsetOf(async_future_t, std);
    async_future_handlers.free_obj = async_future_object_free;
    async_ce_future->default_object_handlers = &async_future_handlers;
}