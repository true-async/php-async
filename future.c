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
#include "zend_smart_str.h"

#define METHOD(name) PHP_METHOD(Async_Future, name)
#define STATE_METHOD(name) PHP_METHOD(Async_FutureState, name)

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
/// FutureState event functions (like coroutine)
///////////////////////////////////////////////////////////

static void future_state_event_start(zend_async_event_t *event)
{
    /* Nothing to start for FutureState */
}

static void future_state_event_stop(zend_async_event_t *event)
{
    /* Nothing to stop for FutureState */
}

static void future_state_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
    zend_async_callbacks_push(event, callback);
}

static void future_state_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
    zend_async_callbacks_remove(event, callback);
}

static bool future_state_replay(zend_async_event_t *event, zend_async_event_callback_t *callback, zval *result, zend_object **exception)
{
    async_future_state_t *state = (async_future_state_t *) event;

    if (!state->completed) {
        return false;
    }

    if (callback != NULL) {
        callback->callback(event, callback, &state->result, state->exception);
        return true;
    }

    if (result != NULL) {
        ZVAL_COPY(result, &state->result);
    }

    if (exception == NULL && state->exception != NULL) {
        GC_ADDREF(state->exception);
        async_rethrow_exception(state->exception);
    } else if (exception != NULL && state->exception != NULL) {
        *exception = state->exception;
        GC_ADDREF(*exception);
    }

    return state->exception != NULL || Z_TYPE(state->result) != IS_UNDEF;
}

static zend_string* future_state_info(zend_async_event_t *event)
{
    async_future_state_t *state = (async_future_state_t *) event;

    return zend_strpprintf(0, "FutureState(%s)", state->completed ? "completed" : "pending");
}

static void future_state_dispose(zend_async_event_t *event)
{
    async_future_state_t *state = (async_future_state_t *) event;
    OBJ_RELEASE(&state->std);
}

///////////////////////////////////////////////////////////
/// FutureState object lifecycle
///////////////////////////////////////////////////////////

static zend_object *async_future_state_object_create(zend_class_entry *ce)
{
    async_future_state_t *state = zend_object_alloc(sizeof(async_future_state_t), ce);
    
    zend_future_t *zend_future = ecalloc(1, sizeof(zend_future_t));
    zend_async_event_t *event = &zend_future->event;

    ZVAL_UNDEF(&zend_future->result);
    zend_future->exception = NULL;

    /* Set event handlers */
    event->start = future_state_event_start;
    event->stop = future_state_event_stop;
    event->add_callback = future_state_add_callback;
    event->del_callback = future_state_del_callback;
    event->replay = future_state_replay;
    event->info = future_state_info;
    event->dispose = future_state_dispose;
    event->ref_count = 1;

    ZEND_ASYNC_EVENT_REF_SET(state, XtOffsetOf(async_future_state_t, std), event);

    state->event = event;

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
        future->event.dispose(&future->event);
    }

    zend_object_std_dtor(&state->std);
}

///////////////////////////////////////////////////////////
/// Future object lifecycle
///////////////////////////////////////////////////////////

static zend_object *async_future_object_create(zend_class_entry *ce)
{
    async_future_t *future = zend_object_alloc(sizeof(async_future_t), ce);
    future->event = NULL;

    zend_object_std_init(&future->std, ce);
    object_properties_init(&future->std, ce);

    return &future->std;
}

static void async_future_object_free(zend_object *object)
{
    async_future_t *future = ASYNC_FUTURE_FROM_OBJ(object);

    zend_future_t *zend_future = (zend_future_t *)future->event;
    future->event = NULL;

    if (zend_future != NULL) {
        zend_future->event.dispose(&zend_future->event);
    }

    zend_object_std_dtor(&future->std);
}

///////////////////////////////////////////////////////////
/// FutureState methods
///////////////////////////////////////////////////////////

#define THIS_FUTURE_STATE ((async_future_state_t *) ASYNC_FUTURE_STATE_FROM_OBJ(Z_OBJ_P(ZEND_THIS)))

STATE_METHOD(__construct)
{
    ZEND_PARSE_PARAMETERS_NONE();
}

STATE_METHOD(complete)
{
    zval *result;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(result)
    ZEND_PARSE_PARAMETERS_END();
    
    async_future_state_t *state = THIS_FUTURE_STATE;

    if (state->event == NULL) {
        async_throw_error("FutureState is already destroyed");
        RETURN_THROWS();
    }

    zend_future_t *future = (zend_future_t *)state->event;

    if (ZEND_FUTURE_IS_COMPLETED(future)) {
        if (future->completed_filename != NULL) {
            async_throw_error("FutureState is already completed at %s:%d", future->completed_filename, future->completed_lineno);
        } else {
            async_throw_error("FutureState is already completed at Unknown:0");
        }

        RETURN_THROWS();
    }

    ZEND_FUTURE_COMPLETE(future, result);
}

STATE_METHOD(error)
{
    zval *throwable;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(throwable, zend_ce_throwable)
    ZEND_PARSE_PARAMETERS_END();
    
    async_future_state_t *state = THIS_FUTURE_STATE;
    
    if (state->event == NULL) {
        async_throw_error("FutureState is already destroyed");
        RETURN_THROWS();
    }

    zend_future_t *future = (zend_future_t *)state->event;

    if (ZEND_FUTURE_IS_COMPLETED(future)) {
        if (future->completed_filename != NULL) {
            async_throw_error("FutureState is already completed at %s:%d", future->completed_filename, future->completed_lineno);
        } else {
            async_throw_error("FutureState is already completed at Unknown:0");
        }

        RETURN_THROWS();
    }

    ZEND_FUTURE_REJECT(future, Z_OBJ_P(throwable));
}

STATE_METHOD(isComplete)
{
    ZEND_PARSE_PARAMETERS_NONE();
    
    async_future_state_t *state = THIS_FUTURE_STATE;
    zend_future_t *future = (zend_future_t *)state->event;
    
    RETURN_BOOL(ZEND_FUTURE_IS_COMPLETED(future));
}

STATE_METHOD(ignore)
{
    ZEND_PARSE_PARAMETERS_NONE();
    
    async_future_state_t *state = THIS_FUTURE_STATE;
    zend_future_t *future = (zend_future_t *)state->event;
    
    ZEND_FUTURE_SET_IGNORED(future);
}

STATE_METHOD(getAwaitingInfo)
{
    ZEND_PARSE_PARAMETERS_NONE();
    
    async_future_state_t *state = THIS_FUTURE_STATE;

    /* @TODO STATE_METHOD(getInfo) */
}

///////////////////////////////////////////////////////////
/// Future methods
///////////////////////////////////////////////////////////

#define THIS_FUTURE ((async_future_t *) ASYNC_FUTURE_FROM_OBJ(Z_OBJ_P(ZEND_THIS)))
#define THIS_ZEND_FUTURE (zend_future_t *) THIS_FUTURE->event

METHOD(__construct)
{
    zval *state_obj;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(state_obj, async_ce_future_state)
    ZEND_PARSE_PARAMETERS_END();

    async_future_t *future = THIS_FUTURE;
    async_future_state_t *state = ASYNC_FUTURE_STATE_FROM_OBJ(Z_OBJ_P(state_obj));

    future->event = state->event;
    ZEND_ASYNC_EVENT_ADD_REF(state->event);
}

METHOD(complete)
{
    zval *result = NULL;
    
    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(result)
    ZEND_PARSE_PARAMETERS_END();

    zend_future_t *future = THIS_ZEND_FUTURE;

    if (future == NULL) {
        async_throw_error("Future is already destroyed");
        RETURN_THROWS();
    }

    if (ZEND_FUTURE_IS_COMPLETED(future)) {
        if (future->completed_filename != NULL) {
            async_throw_error("Future is already completed at %s:%d", future->completed_filename, future->completed_lineno);
        } else {
            async_throw_error("Future is already completed at Unknown:0");
        }

        RETURN_THROWS();
    }

    ZEND_FUTURE_COMPLETE(future, result);
}

METHOD(error)
{
    zval *throwable;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(throwable, zend_ce_throwable)
    ZEND_PARSE_PARAMETERS_END();

    zend_future_t *future = THIS_ZEND_FUTURE;

    if (future == NULL) {
        async_throw_error("Future is already destroyed");
        RETURN_THROWS();
    }

    if (ZEND_FUTURE_IS_COMPLETED(future)) {
        if (future->completed_filename != NULL) {
            async_throw_error("Future is already completed at %s:%d", future->completed_filename, future->completed_lineno);
        } else {
            async_throw_error("Future is already completed at Unknown:0");
        }

        RETURN_THROWS();
    }

    ZEND_FUTURE_REJECT(future, Z_OBJ_P(throwable));
}

METHOD(isComplete)
{
    ZEND_PARSE_PARAMETERS_NONE();
    
    zend_future_t *future = THIS_ZEND_FUTURE;

    RETURN_BOOL(ZEND_FUTURE_IS_COMPLETED(future));
}

METHOD(ignore)
{
    ZEND_PARSE_PARAMETERS_NONE();



    RETURN_ZVAL(ZEND_THIS, 1, 0);
}

METHOD(await)
{
    zend_object * cancellation = NULL;
    
    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable)
    ZEND_PARSE_PARAMETERS_END();

    SCHEDULER_LAUNCH;

    zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

    if (UNEXPECTED(coroutine == NULL)) {
        RETURN_NULL();
    }

    zend_future_t *future = THIS_ZEND_FUTURE;
    
    if (future == NULL) {
        async_throw_error("Future has no state");
        RETURN_THROWS();
    }

    zend_async_event_t *event = &future->event;

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

/* TODO: Implement map, catch, finally methods */
METHOD(map)
{
    async_throw_error("Future::map() not yet implemented");
    RETURN_THROWS();
}

METHOD(catch)
{
    async_throw_error("Future::catch() not yet implemented");
    RETURN_THROWS();
}

METHOD(finally)
{
    async_throw_error("Future::finally() not yet implemented");
    RETURN_THROWS();
}

///////////////////////////////////////////////////////////
/// Helper functions
///////////////////////////////////////////////////////////

async_future_state_t *async_future_state_create(void)
{
    zend_object *obj = async_future_state_object_create(async_ce_future_state);
    return ASYNC_FUTURE_STATE_FROM_OBJ(obj);
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
    
    /* Register Future class using generated registration */
    async_ce_future = register_class_Async_Future();
    async_ce_future->create_object = async_future_object_create;
    
    memcpy(&async_future_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    async_future_handlers.offset = XtOffsetOf(async_future_t, std);
    async_future_handlers.free_obj = async_future_object_free;
    
    /* Make Future implement Awaitable */
    zend_class_implements(async_ce_future, 1, async_ce_awaitable);
}