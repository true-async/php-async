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
#include "zend_smart_str.h"

#define METHOD(name) PHP_METHOD(Async_Future, name)
#define STATE_METHOD(name) PHP_METHOD(Async_FutureState, name)

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

static void async_future_state_object_destroy(zend_object *object)
{
    async_future_state_t *state = ASYNC_FUTURE_STATE_FROM_OBJ(object);
    
    zval_ptr_dtor(&state->result);
    
    if (state->exception != NULL) {
        OBJ_RELEASE(state->exception);
    }
    
    zend_object_std_dtor(&state->std);
}

///////////////////////////////////////////////////////////
/// Future object lifecycle
///////////////////////////////////////////////////////////

static zend_object *async_future_object_create(zend_class_entry *ce)
{
    async_future_t *future = emalloc(sizeof(async_future_t));
    
    zend_object_std_init(&future->std, ce);
    object_properties_init(&future->std, ce);
    
    future->std.handlers = &async_future_handlers;
    future->state = NULL;
    
    return &future->std;
}

static void async_future_object_destroy(zend_object *object)
{
    async_future_t *future = ASYNC_FUTURE_FROM_OBJ(object);
    
    if (future->state != NULL) {
        OBJ_RELEASE(&future->state->std);
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
    
    if (state->completed) {
        async_throw_error("FutureState is already completed");
        RETURN_THROWS();
    }
    
    ZVAL_COPY(&state->result, result);
    state->completed = true;
    
    /* Mark event as closed (completed) */
    ZEND_ASYNC_EVENT_SET_CLOSED(&state->event);
    
    /* Notify all callbacks */
    zend_async_callbacks_notify(&state->event, &state->result, NULL, false);
}

STATE_METHOD(error)
{
    zval *throwable;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(throwable, zend_ce_throwable)
    ZEND_PARSE_PARAMETERS_END();
    
    async_future_state_t *state = THIS_FUTURE_STATE;
    
    if (state->completed) {
        async_throw_error("FutureState is already completed");
        RETURN_THROWS();
    }
    
    state->exception = Z_OBJ_P(throwable);
    GC_ADDREF(state->exception);
    state->completed = true;
    
    /* Mark event as closed (completed) */
    ZEND_ASYNC_EVENT_SET_CLOSED(&state->event);
    
    /* Notify all callbacks with exception */
    zend_async_callbacks_notify(&state->event, NULL, state->exception, false);
}

STATE_METHOD(isComplete)
{
    ZEND_PARSE_PARAMETERS_NONE();
    
    async_future_state_t *state = THIS_FUTURE_STATE;
    
    RETURN_BOOL(state->completed);
}

STATE_METHOD(ignore)
{
    ZEND_PARSE_PARAMETERS_NONE();
    
    async_future_state_t *state = THIS_FUTURE_STATE;
    
    state->ignored = true;
}

STATE_METHOD(getInfo)
{
    ZEND_PARSE_PARAMETERS_NONE();
    
    async_future_state_t *state = THIS_FUTURE_STATE;
    
    smart_str info = {0};
    smart_str_appends(&info, "FutureState(");
    smart_str_appends(&info, state->completed ? "completed" : "pending");
    
    if (state->completed) {
        if (state->exception != NULL) {
            smart_str_appends(&info, ", error");
        } else {
            smart_str_appends(&info, ", value");
        }
    }
    
    smart_str_appendc(&info, ')');
    smart_str_0(&info);
    
    RETURN_STR(info.s);
}

///////////////////////////////////////////////////////////
/// Future methods
///////////////////////////////////////////////////////////

#define THIS_FUTURE ((async_future_t *) ASYNC_FUTURE_FROM_OBJ(Z_OBJ_P(ZEND_THIS)))

METHOD(complete)
{
    zval *value = NULL;
    
    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(value)
    ZEND_PARSE_PARAMETERS_END();
    
    /* Create new FutureState */
    async_future_state_t *state = async_future_state_create();
    
    /* Complete it immediately */
    if (value != NULL) {
        ZVAL_COPY(&state->result, value);
    } else {
        ZVAL_NULL(&state->result);
    }
    state->completed = true;
    
    /* Create Future wrapping the state */
    async_future_t *future = async_future_wrap_state(state);
    
    RETURN_OBJ_COPY(&future->std);
}

METHOD(error)
{
    zval *throwable;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(throwable, zend_ce_throwable)
    ZEND_PARSE_PARAMETERS_END();
    
    /* Create new FutureState */
    async_future_state_t *state = async_future_state_create();
    
    /* Set error immediately */
    state->exception = Z_OBJ_P(throwable);
    GC_ADDREF(state->exception);
    state->completed = true;
    
    /* Create Future wrapping the state */
    async_future_t *future = async_future_wrap_state(state);
    
    RETURN_OBJ_COPY(&future->std);
}

METHOD(__construct)
{
    zval *state_obj;
    
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_OBJECT_OF_CLASS(state_obj, async_ce_future_state)
    ZEND_PARSE_PARAMETERS_END();
    
    async_future_t *future = THIS_FUTURE;
    async_future_state_t *state = ASYNC_FUTURE_STATE_FROM_OBJ(Z_OBJ_P(state_obj));
    
    future->state = state;
    GC_ADDREF(&state->std);
}

METHOD(isComplete)
{
    ZEND_PARSE_PARAMETERS_NONE();
    
    async_future_t *future = THIS_FUTURE;
    
    if (future->state == NULL) {
        RETURN_FALSE;
    }
    
    RETURN_BOOL(future->state->completed);
}

METHOD(ignore)
{
    ZEND_PARSE_PARAMETERS_NONE();
    
    async_future_t *future = THIS_FUTURE;
    
    if (future->state != NULL) {
        future->state->ignored = true;
    }
    
    RETURN_ZVAL(ZEND_THIS, 1, 0);
}

METHOD(await)
{
    zval *cancellation = NULL;
    
    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancellation, async_ce_awaitable)
    ZEND_PARSE_PARAMETERS_END();
    
    async_future_t *future = THIS_FUTURE;
    
    if (future->state == NULL) {
        async_throw_error("Future has no state");
        RETURN_THROWS();
    }
    
    async_future_state_t *state = future->state;
    
    /* If already completed, return result immediately */
    if (state->completed) {
        if (state->exception != NULL) {
            zval exception_zv;
            ZVAL_OBJ(&exception_zv, state->exception);
            zend_throw_exception_object(&exception_zv);
            RETURN_THROWS();
        }
        
        RETURN_ZVAL(&state->result, 1, 0);
    }
    
    /* Get current coroutine */
    zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;
    if (coroutine == NULL) {
        async_throw_error("Cannot await outside of coroutine context");
        RETURN_THROWS();
    }
    
    /* TODO: Handle cancellation if provided */
    if (cancellation != NULL) {
        /* Currently not implemented */
    }
    
    /* Create callback for when future completes */
    zend_coroutine_event_callback_t *callback = emalloc(sizeof(zend_coroutine_event_callback_t));
    callback->base.ref_count = 1;
    callback->base.callback = zend_async_waker_callback_resolve;
    callback->base.dispose = NULL;
    callback->coroutine = coroutine;
    
    /* Suspend coroutine until future completes */
    zend_async_resume_when(coroutine, &state->event, false, NULL, callback);
    
    /* Execution resumes here when future completes */
    
    /* Check result after await */
    if (state->exception != NULL) {
        zval exception_zv;
        ZVAL_OBJ(&exception_zv, state->exception);
        zend_throw_exception_object(&exception_zv);
        RETURN_THROWS();
    }
    
    RETURN_ZVAL(&state->result, 1, 0);
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

async_future_t *async_future_wrap_state(async_future_state_t *state)
{
    zend_object *obj = async_future_object_create(async_ce_future);
    async_future_t *future = ASYNC_FUTURE_FROM_OBJ(obj);
    
    future->state = state;
    GC_ADDREF(&state->std);
    
    return future;
}

///////////////////////////////////////////////////////////
/// API function implementations
///////////////////////////////////////////////////////////

zend_future_t *async_future_create(void)
{
    async_future_state_t *state = async_future_state_create();
    return (zend_future_t *)state;
}

void async_future_complete(zend_future_t *future, zval *value)
{
    async_future_state_t *state = (async_future_state_t *)future;
    
    if (state->completed) {
        return;
    }
    
    ZVAL_COPY(&state->result, value);
    state->completed = true;
    
    /* Mark event as closed (completed) */
    ZEND_ASYNC_EVENT_SET_CLOSED(&state->event);
    
    /* Notify all callbacks */
    zend_async_callbacks_notify(&state->event, &state->result, NULL, false);
}

void async_future_error(zend_future_t *future, zend_object *exception)
{
    async_future_state_t *state = (async_future_state_t *)future;
    
    if (state->completed) {
        return;
    }
    
    state->exception = exception;
    GC_ADDREF(exception);
    state->completed = true;
    
    /* Mark event as closed (completed) */
    ZEND_ASYNC_EVENT_SET_CLOSED(&state->event);
    
    /* Notify all callbacks with exception */
    zend_async_callbacks_notify(&state->event, NULL, state->exception, false);
}

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
    async_future_state_handlers.free_obj = async_future_state_object_destroy;
    
    /* Register Future class using generated registration */
    async_ce_future = register_class_Async_Future();
    async_ce_future->create_object = async_future_object_create;
    
    memcpy(&async_future_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    async_future_handlers.offset = XtOffsetOf(async_future_t, std);
    async_future_handlers.free_obj = async_future_object_destroy;
    
    /* Make Future implement Awaitable */
    zend_class_implements(async_ce_future, 1, async_ce_awaitable);
}