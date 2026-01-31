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

///////////////////////////////////////////////////////////
/// Future Iterator (zend_object_iterator)
///////////////////////////////////////////////////////////

/**
 * Custom zend_object_iterator for efficient future chain traversal.
 * Instead of creating a new async_iterator for each future level,
 * this iterator maintains a queue of futures and iterates through
 * their children in a flat manner.
 *
 * Flow:
 * 1. Parent future resolves, adds itself to future_queue
 * 2. Iterator picks parent from queue, iterates its child_futures
 * 3. When child resolves and has its own children, child is added to queue
 * 4. Single iterator processes entire future tree
 */
typedef struct {
    zend_object_iterator it;

    /* Queue of futures to process (FIFO) */
    zend_array *future_queue;
    uint32_t queue_hash_iter;
    HashPosition queue_pos;

    /* Current parent future being processed */
    async_future_t *current_parent;

    /* Position in current_parent->child_futures */
    uint32_t child_hash_iter;
    HashPosition child_pos;

    /* Current child for get_current_data() */
    zval current_child;
} future_iterator_t;

/* Forward declarations for future_iterator functions */
static void future_iterator_dtor(zend_object_iterator *iter);
static zend_result future_iterator_valid(zend_object_iterator *iter);
static zval *future_iterator_get_current_data(zend_object_iterator *iter);
static void future_iterator_get_current_key(zend_object_iterator *iter, zval *key);
static void future_iterator_move_forward(zend_object_iterator *iter);
static void future_iterator_rewind(zend_object_iterator *iter);

static const zend_object_iterator_funcs future_iterator_funcs = {
    .dtor = future_iterator_dtor,
    .valid = future_iterator_valid,
    .get_current_data = future_iterator_get_current_data,
    .get_current_key = future_iterator_get_current_key,
    .move_forward = future_iterator_move_forward,
    .rewind = future_iterator_rewind,
    .invalidate_current = NULL,
    .get_gc = NULL,
};

/**
 * Create a new future_iterator_t.
 */
static future_iterator_t *future_iterator_create(async_future_t *first_future)
{
    future_iterator_t *iterator = ecalloc(1, sizeof(future_iterator_t));

    zend_iterator_init(&iterator->it);
    iterator->it.funcs = &future_iterator_funcs;

    /* Create queue and add first future */
    iterator->future_queue = zend_new_array(4);
    iterator->queue_hash_iter = (uint32_t)-1;

    zval first;
    ZVAL_OBJ(&first, &first_future->std);
    Z_ADDREF(first);
    zend_hash_next_index_insert(iterator->future_queue, &first);

    iterator->current_parent = NULL;
    iterator->child_hash_iter = (uint32_t)-1;
    ZVAL_UNDEF(&iterator->current_child);

    return iterator;
}

/**
 * Destructor for future_iterator_t.
 */
static void future_iterator_dtor(zend_object_iterator *iter)
{
    future_iterator_t *iterator = (future_iterator_t *)iter;

    if (iterator->queue_hash_iter != (uint32_t)-1) {
        zend_hash_iterator_del(iterator->queue_hash_iter);
        iterator->queue_hash_iter = (uint32_t)-1;
    }

    if (iterator->child_hash_iter != (uint32_t)-1) {
        zend_hash_iterator_del(iterator->child_hash_iter);
        iterator->child_hash_iter = (uint32_t)-1;
    }

    if (iterator->future_queue != NULL) {
        zend_array_destroy(iterator->future_queue);
        iterator->future_queue = NULL;
    }

    zval_ptr_dtor(&iterator->current_child);
    ZVAL_UNDEF(&iterator->current_child);

    iterator->current_parent = NULL;

    // Note: don't efree here - zend_objects_store handles the memory
}

/**
 * Extended destructor for async_iterator_t.
 * Called from iterator_dtor to properly clean up the zend_iterator.
 */
static void future_async_iterator_dtor(zend_async_iterator_t *async_iter)
{
    async_iterator_t *iterator = (async_iterator_t *)async_iter;

    // Clear extended_data since zend_iterator_dtor will free the memory
    iterator->extended_data = NULL;

    if (iterator->zend_iterator != NULL) {
        zend_object_iterator *zend_iter = iterator->zend_iterator;
        iterator->zend_iterator = NULL;
        zend_iterator_dtor(zend_iter);
    }
}

/**
 * Move to next parent from the queue.
 * Returns true if a new parent was found, false if queue is empty.
 */
static bool future_iterator_next_parent(future_iterator_t *iterator)
{
    if (iterator->child_hash_iter != (uint32_t)-1) {
        zend_hash_iterator_del(iterator->child_hash_iter);
        iterator->child_hash_iter = (uint32_t)-1;
    }

    iterator->current_parent = NULL;

    if (iterator->future_queue == NULL || zend_hash_num_elements(iterator->future_queue) == 0) {
        return false;
    }

    /* Get next future from queue */
    if (iterator->queue_hash_iter == (uint32_t)-1) {
        zend_hash_internal_pointer_reset_ex(iterator->future_queue, &iterator->queue_pos);
        iterator->queue_hash_iter = zend_hash_iterator_add(iterator->future_queue, iterator->queue_pos);
    } else {
        iterator->queue_pos = zend_hash_iterator_pos(iterator->queue_hash_iter, iterator->future_queue);
        zend_hash_move_forward_ex(iterator->future_queue, &iterator->queue_pos);
        EG(ht_iterators)[iterator->queue_hash_iter].pos = iterator->queue_pos;
    }

    zval *next_future_zval = zend_hash_get_current_data_ex(iterator->future_queue, &iterator->queue_pos);
    if (next_future_zval == NULL) {
        return false;
    }

    iterator->current_parent = ASYNC_FUTURE_FROM_OBJ(Z_OBJ_P(next_future_zval));

    /* Initialize child iteration if parent has children */
    if (iterator->current_parent->child_futures != NULL &&
        zend_hash_num_elements(iterator->current_parent->child_futures) > 0) {

        zend_hash_internal_pointer_reset_ex(iterator->current_parent->child_futures, &iterator->child_pos);
        iterator->child_hash_iter = zend_hash_iterator_add(
            iterator->current_parent->child_futures, iterator->child_pos);

        return true;
    }

    /* Parent has no children, try next parent */
    return future_iterator_next_parent(iterator);
}

/**
 * Check if iteration is valid.
 * If current parent has no more children, switches to next parent from queue.
 */
static zend_result future_iterator_valid(zend_object_iterator *iter)
{
    future_iterator_t *iterator = (future_iterator_t *)iter;

    while (true) {
        if (iterator->current_parent == NULL) {
            /* Try to get next parent from queue */
            if (!future_iterator_next_parent(iterator)) {
                return FAILURE;
            }
            continue;
        }

        if (iterator->current_parent->child_futures == NULL) {
            /* Parent has no children, try next */
            if (!future_iterator_next_parent(iterator)) {
                return FAILURE;
            }
            continue;
        }

        zval *current = zend_hash_get_current_data_ex(
            iterator->current_parent->child_futures, &iterator->child_pos);

        if (current != NULL) {
            return SUCCESS;
        }

        /* No more children, try next parent */
        if (!future_iterator_next_parent(iterator)) {
            return FAILURE;
        }
    }
}

/**
 * Get current child future.
 */
static zval *future_iterator_get_current_data(zend_object_iterator *iter)
{
    future_iterator_t *iterator = (future_iterator_t *)iter;

    if (iterator->current_parent == NULL || iterator->current_parent->child_futures == NULL) {
        return NULL;
    }

    zval *current = zend_hash_get_current_data_ex(
        iterator->current_parent->child_futures, &iterator->child_pos);

    if (current != NULL) {
        zval_ptr_dtor(&iterator->current_child);
        ZVAL_COPY(&iterator->current_child, current);
        return &iterator->current_child;
    }

    return NULL;
}

/**
 * Get current key (numeric index).
 */
static void future_iterator_get_current_key(zend_object_iterator *iter, zval *key)
{
    ZVAL_LONG(key, iter->index);
}

/**
 * Move to next child or next parent.
 *
 * IMPORTANT: move_forward is called BEFORE the handler in PHP's iterate() loop.
 * We must NOT reset current_parent here because the handler still needs it
 * to process the current element. Instead, we just move the position forward.
 * The actual parent switch happens in valid() when we detect no more children.
 */
static void future_iterator_move_forward(zend_object_iterator *iter)
{
    future_iterator_t *iterator = (future_iterator_t *)iter;

    iter->index++;

    if (iterator->current_parent == NULL || iterator->current_parent->child_futures == NULL) {
        /* Will be handled by valid() */
        return;
    }

    /* Move to next child */
    zend_hash_move_forward_ex(iterator->current_parent->child_futures, &iterator->child_pos);

    if (iterator->child_hash_iter != (uint32_t)-1) {
        EG(ht_iterators)[iterator->child_hash_iter].pos = iterator->child_pos;
    }

    /* Don't call future_iterator_next_parent here!
     * valid() will detect there are no more children and switch parent. */
}

/**
 * Rewind to start.
 */
static void future_iterator_rewind(zend_object_iterator *iter)
{
    future_iterator_t *iterator = (future_iterator_t *)iter;

    iter->index = 0;

    /* Reset queue position */
    if (iterator->queue_hash_iter != (uint32_t)-1) {
        zend_hash_iterator_del(iterator->queue_hash_iter);
        iterator->queue_hash_iter = (uint32_t)-1;
    }

    if (iterator->child_hash_iter != (uint32_t)-1) {
        zend_hash_iterator_del(iterator->child_hash_iter);
        iterator->child_hash_iter = (uint32_t)-1;
    }

    iterator->current_parent = NULL;

    /* Start from first parent in queue */
    future_iterator_next_parent(iterator);
}

/* Forward declarations */
static void process_future_mapper(zend_future_t *parent_future, async_future_t *child_future_obj, future_iterator_t *iterator);
static zend_result future_mappers_handler(async_iterator_t *iterator, zval *current, zval *key);
static void async_future_callback_handler(zend_async_event_t *event, zend_async_event_callback_t *callback, void *result, zend_object *exception);
static void async_future_callback_dispose(zend_async_event_callback_t *callback, zend_async_event_t *event);
static void async_future_create_mapper(INTERNAL_FUNCTION_PARAMETERS, async_future_mapper_type_t mapper_type);
static async_future_t *async_future_create_internal(void);
static void future_mapper_coroutine_entry(void);
static void future_mapper_context_dispose(zend_coroutine_t *coroutine);

///////////////////////////////////////////////////////////
/// zend_future_t event handlers
///////////////////////////////////////////////////////////

static bool zend_future_event_start(zend_async_event_t *event)
{
    /* Nothing to start for zend_future_t */
    return true;
}

/**
 * The method that is called to resolve the Future (complete or reject).
 * This method must be triggered only once.
 * This is the proper method for completing futures, NOT stop().
 *
 * @param event The future event
 * @param iterator If not NULL, the future_iterator_t to add children to
 */
static bool zend_future_resolve(zend_async_event_t *event, void *iterator)
{
    if (ZEND_ASYNC_EVENT_IS_CLOSED(event)) {
        return true;
    }

    ZEND_ASYNC_EVENT_SET_CLOSED(event);

    zend_future_t *future = (zend_future_t *)event;

    /* Record where the Future was completed */
    zend_apply_current_filename_and_line(&future->completed_filename, &future->completed_lineno);

    // Notify regular callbacks (awaiters) with result/exception
    ZEND_ASYNC_CALLBACKS_NOTIFY(event, &future->result, future->exception);

    // Notify resolve_callbacks (map/catch/finally chains) with iterator
    zend_async_callbacks_vector_notify(&future->resolve_callbacks, event, iterator);

    return true;
}

/**
 * Standard event stop method.
 * For futures, this does nothing - all completion logic is in resolve().
 */
static bool zend_future_event_stop(zend_async_event_t *event)
{
    return true;
}

static bool zend_future_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
    return zend_async_callbacks_push(event, callback);
}

static bool zend_future_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
    return zend_async_callbacks_remove(event, callback);
}

static bool zend_future_replay(zend_async_event_t *event, zend_async_event_callback_t *callback, zval *result, zend_object **exception)
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

static zend_string* zend_future_info(zend_async_event_t *event)
{
    const zend_future_t *future = (zend_future_t *) event;

    return zend_strpprintf(0, "FutureState(%s)", ZEND_FUTURE_IS_COMPLETED(future) ? "completed" : "pending");
}

static bool zend_future_dispose(zend_async_event_t *event)
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
    zend_async_callbacks_vector_free(&future->resolve_callbacks, event);

    efree(future);

    return true;
}

static zend_always_inline void init_zend_future(zend_async_event_t *event)
{
    zend_future_t *future = (zend_future_t *)event;

    event->start = zend_future_event_start;
    event->stop = zend_future_event_stop;
    event->add_callback = zend_future_add_callback;
    event->del_callback = zend_future_del_callback;
    event->replay = zend_future_replay;
    event->info = zend_future_info;
    event->dispose = zend_future_dispose;
    event->ref_count = 1;

    /* Set the resolve method for completing the future */
    future->resolve = zend_future_resolve;

    /* Initialize resolve_callbacks vector (for map/catch/finally chains) */
    future->resolve_callbacks.data = NULL;
    future->resolve_callbacks.length = 0;
    future->resolve_callbacks.capacity = 0;

    /* Initialize file/line tracking fields */
    future->filename = NULL;
    future->lineno = 0;
    future->completed_filename = NULL;
    future->completed_lineno = 0;
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
    init_zend_future(event);

    /* Record where the Future was created */
    zend_apply_current_filename_and_line(&future->filename, &future->lineno);

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

FUTURE_STATE_METHOD(getCreatedFileAndLine)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_state_t *state = THIS_FUTURE_STATE;
    zend_future_t *future = (zend_future_t *)state->event;

    array_init(return_value);

    if (future != NULL && future->filename != NULL) {
        add_next_index_str(return_value, zend_string_copy(future->filename));
    } else {
        add_next_index_null(return_value);
    }

    add_next_index_long(return_value, future != NULL ? future->lineno : 0);
}

FUTURE_STATE_METHOD(getCreatedLocation)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_state_t *state = THIS_FUTURE_STATE;
    zend_future_t *future = (zend_future_t *)state->event;

    if (future != NULL && future->filename != NULL) {
        RETURN_STR(zend_strpprintf(0, "%s:%d", ZSTR_VAL(future->filename), future->lineno));
    } else {
        RETURN_STRING("unknown");
    }
}

FUTURE_STATE_METHOD(getCompletedFileAndLine)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_state_t *state = THIS_FUTURE_STATE;
    zend_future_t *future = (zend_future_t *)state->event;

    array_init(return_value);

    if (future != NULL && future->completed_filename != NULL) {
        add_next_index_str(return_value, zend_string_copy(future->completed_filename));
    } else {
        add_next_index_null(return_value);
    }

    add_next_index_long(return_value, future != NULL ? future->completed_lineno : 0);
}

FUTURE_STATE_METHOD(getCompletedLocation)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_state_t *state = THIS_FUTURE_STATE;
    zend_future_t *future = (zend_future_t *)state->event;

    if (future != NULL && future->completed_filename != NULL) {
        RETURN_STR(zend_strpprintf(0, "%s:%d", ZSTR_VAL(future->completed_filename), future->completed_lineno));
    } else {
        RETURN_STRING("unknown");
    }
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
 * @param iterator The future iterator (for chained resolution)
 */
static void process_future_mapper(zend_future_t *parent_future, async_future_t *child_future_obj, future_iterator_t *iterator)
{
	zend_future_t *child_future = (zend_future_t *)child_future_obj->event;

	if (UNEXPECTED(child_future == NULL || ZEND_FUTURE_IS_COMPLETED(child_future))) {
		return;
	}

	zend_object *fallback_exception = NULL;
	zval args[1];
    ZVAL_NULL(&args[0]);
	bool should_call = true;

    if (EXPECTED(parent_future->exception == NULL)) {
        if (!Z_ISUNDEF(parent_future->result)) {
            ZVAL_COPY(&args[0], &parent_future->result);
        }
    } else {
        fallback_exception = parent_future->exception;
        GC_ADDREF(fallback_exception);
    }

	switch (child_future_obj->mapper_type) {
		case ASYNC_FUTURE_MAPPER_SUCCESS:
			if (UNEXPECTED(parent_future->exception != NULL)) {
		        // Called only on success, when there are no exceptions.
			    should_call = false;
			}
			break;

		case ASYNC_FUTURE_MAPPER_CATCH:
			if (fallback_exception != NULL) {
			    // fallback_exception is passed as a function parameter.
				ZVAL_OBJ(&args[0], fallback_exception);
			    fallback_exception = NULL;
			} else {
			    // Not called in case of success.
			    should_call = false;
			}
			break;

		case ASYNC_FUTURE_MAPPER_FINALLY:
	        // Always call
	        if (fallback_exception != NULL) {
                // fallback_exception is passed as a function parameter.
                ZVAL_OBJ(&args[0], fallback_exception);
	            GC_ADDREF(fallback_exception);
                // The finally method, unlike catch, does not swallow the exception,
                // and it continues further down the chain.
	            // So fallback_exception != NULL
            }

			break;
	}

    zval retval;
    ZVAL_UNDEF(&retval);

	if (should_call) {
		call_user_function(NULL, NULL, &child_future_obj->mapper, &retval, 1, args);
	}

    zval_ptr_dtor(&args[0]);

    // For finally, the result of the parent future is passed to the child unchanged.
    if (child_future_obj->mapper_type == ASYNC_FUTURE_MAPPER_FINALLY) {
        zval_ptr_dtor(&retval);

        if (!Z_ISUNDEF(parent_future->result)) {
            ZVAL_COPY(&retval, &parent_future->result);
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
		ZEND_FUTURE_REJECT_WITH_ITERATOR(child_future, fallback_exception, iterator);

	    if (UNEXPECTED(EG(exception) != NULL)) {
	        zend_exception_set_previous(EG(exception), fallback_exception);
	    } else if (UNEXPECTED(false == ZEND_ASYNC_EVENT_IS_EXCEPTION_HANDLED(&child_future->event))) {
	        // We throw the fallback_exception only if it was not handled by anyone.
	        EG(exception) = fallback_exception;
	    } else {
	        // Exception was handled, release our reference
	        OBJ_RELEASE(fallback_exception);
	        fallback_exception = NULL;
	    }

	} else {

	    if (Z_TYPE(retval) == IS_UNDEF) {
	        // Callback was not called - pass through parent result
	        if (!Z_ISUNDEF(parent_future->result)) {
	            ZVAL_COPY(&retval, &parent_future->result);
	        } else {
	            ZVAL_NULL(&retval);
	        }
	    }

	    ZEND_FUTURE_COMPLETE_WITH_ITERATOR(child_future, &retval, iterator);
	}

    zval_ptr_dtor(&retval);
}

/**
 * Iterator handler that processes child futures when parent resolves.
 * Called once for each child future (from map/catch/finally).
 */
static zend_result future_mappers_handler(async_iterator_t *async_iter, zval *current, zval *key)
{
	future_iterator_t *iterator = (future_iterator_t *)async_iter->extended_data;

	if (UNEXPECTED(iterator == NULL || iterator->current_parent == NULL)) {
		return FAILURE;
	}

	async_future_t *child_future_obj = ASYNC_FUTURE_FROM_OBJ(Z_OBJ_P(current));

	// Get parent future from the iterator's current_parent
	zend_future_t *parent_future = (zend_future_t *)iterator->current_parent->event;

	process_future_mapper(parent_future, child_future_obj, iterator);

	return SUCCESS;
}

///////////////////////////////////////////////////////////
/// Future callback implementation
///////////////////////////////////////////////////////////

/**
 * Callback handler invoked when parent future completes.
 *
 * If result (iterator) is not NULL, we add this future to the existing
 * iterator's queue. Otherwise, we create a new future_iterator_t and
 * start processing.
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

    // We mark that the exceptions have been handled.
    ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(event);

    future_iterator_t *existing_iterator = (future_iterator_t *)result;

    if (existing_iterator != NULL) {
        // Iterator already exists - add this future to its queue
        zval self;
        ZVAL_OBJ(&self, &future_obj->std);
        Z_ADDREF(self);
        zend_hash_next_index_insert(existing_iterator->future_queue, &self);
        return;
    }

    // First level - create new future_iterator_t
    future_iterator_t *new_iterator = future_iterator_create(future_obj);

    if (UNEXPECTED(new_iterator == NULL)) {
        return;
    }

    // Create async_iterator with our zend_object_iterator
    async_iterator_t *async_iter = async_iterator_new(
        NULL,
        &new_iterator->it,
        NULL,
        future_mappers_handler,
        ZEND_ASYNC_CURRENT_SCOPE,
        0, /* concurrency: default */
        ZEND_COROUTINE_NORMAL, /* priority: default */
        0 /* iterator size: default */
    );

    if (UNEXPECTED(async_iter == NULL)) {
        zend_iterator_dtor(&new_iterator->it);
        return;
    }

    // Store iterator in extended_data so handler can access it
    async_iter->extended_data = new_iterator;
    async_iter->extended_dtor = future_async_iterator_dtor;
    async_iterator_run_in_coroutine(async_iter, 0, true);
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
    process_future_mapper(context->parent_future, context->child_future_obj, NULL);
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
        // We do not increment the object's reference count because this is a "weak reference".
        // No GC_ADDREF(&source->std);

        // Add to resolve_callbacks (not regular callbacks) for chain processing
        if (UNEXPECTED(!zend_async_callbacks_vector_push(&source_future->resolve_callbacks, &callback->base))) {
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

FUTURE_METHOD(getCreatedFileAndLine)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_t *future_obj = THIS_FUTURE;
    zend_future_t *future = (zend_future_t *)future_obj->event;

    array_init(return_value);

    if (future != NULL && future->filename != NULL) {
        add_next_index_str(return_value, zend_string_copy(future->filename));
    } else {
        add_next_index_null(return_value);
    }

    add_next_index_long(return_value, future != NULL ? future->lineno : 0);
}

FUTURE_METHOD(getCreatedLocation)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_t *future_obj = THIS_FUTURE;
    zend_future_t *future = (zend_future_t *)future_obj->event;

    if (future != NULL && future->filename != NULL) {
        RETURN_STR(zend_strpprintf(0, "%s:%d", ZSTR_VAL(future->filename), future->lineno));
    } else {
        RETURN_STRING("unknown");
    }
}

FUTURE_METHOD(getCompletedFileAndLine)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_t *future_obj = THIS_FUTURE;
    zend_future_t *future = (zend_future_t *)future_obj->event;

    array_init(return_value);

    if (future != NULL && future->completed_filename != NULL) {
        add_next_index_str(return_value, zend_string_copy(future->completed_filename));
    } else {
        add_next_index_null(return_value);
    }

    add_next_index_long(return_value, future != NULL ? future->completed_lineno : 0);
}

FUTURE_METHOD(getCompletedLocation)
{
    ZEND_PARSE_PARAMETERS_NONE();

    const async_future_t *future_obj = THIS_FUTURE;
    zend_future_t *future = (zend_future_t *)future_obj->event;

    if (future != NULL && future->completed_filename != NULL) {
        RETURN_STR(zend_strpprintf(0, "%s:%d", ZSTR_VAL(future->completed_filename), future->completed_lineno));
    } else {
        RETURN_STRING("unknown");
    }
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

    init_zend_future(event);

    /* Record where the Future was created */
    zend_apply_current_filename_and_line(&future->filename, &future->lineno);

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