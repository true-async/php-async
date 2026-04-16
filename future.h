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
#ifndef FUTURE_H
#define FUTURE_H

#include <php.h>
#include <Zend/zend_async_API.h>

typedef struct _async_future_state_s async_future_state_t;
typedef struct _async_future_s async_future_t;
typedef struct _zend_future_shared_state_s zend_future_shared_state_t;

/* Mapper types for Future transformations */
typedef enum
{
	ASYNC_FUTURE_MAPPER_SUCCESS = 0, /* map() - transforms successful result */
	ASYNC_FUTURE_MAPPER_CATCH = 1,   /* catch() - handles errors */
	ASYNC_FUTURE_MAPPER_FINALLY = 2  /* finally() - always executes */
} async_future_mapper_type_t;

/**
 * FutureState object structure.
 * Holds a reference to the underlying zend_future_t event.
 * Allows modification through complete() and error() methods.
 */
struct _async_future_state_s
{
	ZEND_ASYNC_EVENT_REF_FIELDS                    /* Reference to zend_future_t */
	zend_future_shared_state_t *shared_state;      /* Non-NULL after transfer to another thread */
	zend_object std;                               /* Standard object */
};

/**
 * Future object structure.
 * Holds a reference to the same zend_future_t event as FutureState.
 * Provides readonly access (await, isComplete, ignore, map, catch, finally).
 * Both structures have identical beginning (ZEND_ASYNC_EVENT_REF_FIELDS).
 */
struct _async_future_s
{
	ZEND_ASYNC_EVENT_REF_FIELDS             /* Reference to zend_future_t (same as FutureState) */
	HashTable *child_futures;				/* Child futures created by map/catch/finally */
	zval mapper;                            /* Mapper callable (used when this future is a child) */
	async_future_mapper_type_t mapper_type; /* Type of mapper transformation */
	zend_object std;                        /* Standard object - MUST BE LAST! */
};

/* Class entry declarations */
extern zend_class_entry *async_ce_future_state;
extern zend_class_entry *async_ce_future;

/* Convert zend_object to async_future_state_t */
#define ASYNC_FUTURE_STATE_FROM_OBJ(obj) ((async_future_state_t *) ((char *) (obj) - (obj)->handlers->offset))

/* Convert zend_object to async_future_t */
#define ASYNC_FUTURE_FROM_OBJ(obj) ((async_future_t *) ((char *) (obj) - (obj)->handlers->offset))


/* Registration function */
void async_register_future_ce(void);

/* API function implementations */
zend_future_t *async_future_create(void);
zend_future_t *async_new_future(bool thread_safe, size_t extra_size);
zend_object *async_new_future_obj(zend_future_t *future);
/* Internal helper functions */
async_future_state_t *async_future_state_create(void);

///////////////////////////////////////////////////////////
/// Shared future state — cross-thread future bridge
///
/// Bridges two futures across threads via persistent memory.
///
/// Ownership graph:
///   FutureSRC -> source_cb -> shared_state <- trigger_cb <- FutureDST
///
/// The shared state is created without a trigger. The destination thread
/// binds its trigger and target future via async_future_shared_state_bind()
/// or by creating a zend_future_remote_t which does this automatically.
///////////////////////////////////////////////////////////

/**
 * @brief Cross-thread shared future state.
 *
 * Allocated in persistent memory (pemalloc) so it can be safely
 * accessed from multiple threads. Holds transferred result/exception
 * and coordinates notification between source and destination threads.
 */
typedef struct _zend_future_shared_state_s {
	/** Atomic reference count (+1 source callback, +1 dest callback) */
	zend_atomic_int ref_count;

	/** Atomic completion flag (0 = pending, 1 = completed) */
	zend_atomic_int completed;

	/** Mutex protecting the transition from pending to completed.
	 *  Present only in ZTS builds — threading is ZTS-only. */
#ifdef ZTS
	MUTEX_T mutex;
#endif

	/** Transferred result value in persistent memory */
	zval transferred_result;

	/** Transferred exception in persistent memory (UNDEF if success) */
	zval transferred_exception;

	/** Trigger event bound to the destination thread's event loop */
	zend_async_trigger_event_t *trigger;

	/** Target future in the destination thread (emalloc, not owned) */
	zend_future_t *target_future;
} zend_future_shared_state_t;

/**
 * @brief Create a shared state (without trigger or target).
 *
 * Allocates shared state in persistent memory. The trigger and target future
 * are left NULL — the destination thread must call async_future_shared_state_bind()
 * or create a zend_future_remote_t to set them up.
 *
 * @return Shared state with ref_count = 1 (caller owns one ref).
 */
zend_future_shared_state_t *async_future_shared_state_create(void);

/**
 * @brief Bind a trigger and target future to the shared state.
 *
 * Must be called from the destination thread. Creates a trigger event
 * on the current thread's event loop and subscribes a callback that will
 * load the transferred result and complete @p target_future.
 *
 * @param state          The shared state to bind.
 * @param target_future  The future to complete when the shared state is resolved.
 * @return true on success, false if trigger creation failed.
 */
bool async_future_shared_state_bind(zend_future_shared_state_t *state, zend_future_t *target_future);

/**
 * @brief Complete a shared state with a result. Thread-safe.
 *
 * Transfers @p result to persistent memory and fires the trigger to wake
 * the destination thread. No-op if already completed.
 *
 * @param state   The shared state to complete.
 * @param result  The result value (will be deep-copied to persistent memory).
 */
void async_future_shared_state_complete(zend_future_shared_state_t *state, zval *result);

/**
 * @brief Reject a shared state with an exception. Thread-safe.
 *
 * Transfers @p exception to persistent memory and fires the trigger to wake
 * the destination thread. No-op if already completed.
 *
 * @param state      The shared state to reject.
 * @param exception  The exception object (will be deep-copied to persistent memory).
 */
void async_future_shared_state_reject(zend_future_shared_state_t *state, zend_object *exception);

/**
 * @brief Increment the shared state reference count. Thread-safe.
 *
 * @param state  The shared state.
 */
static zend_always_inline void async_future_shared_state_addref(zend_future_shared_state_t *state)
{
	int old;
	do {
		old = zend_atomic_int_load(&state->ref_count);
	} while (!zend_atomic_int_compare_exchange(&state->ref_count, &old, old + 1));
}

/**
 * @brief Free shared state resources (called when ref_count reaches 0).
 *
 * Releases any unretrieved transferred values and frees the persistent memory.
 * Do not call directly — use async_future_shared_state_delref().
 *
 * @param state  The shared state to destroy.
 */
void async_future_shared_state_destroy(zend_future_shared_state_t *state);

/**
 * @brief Decrement the shared state reference count. Thread-safe.
 *
 * Destroys the shared state when the last reference is released.
 *
 * @param state  The shared state.
 */
static zend_always_inline void async_future_shared_state_delref(zend_future_shared_state_t *state)
{
	int old;
	do {
		old = zend_atomic_int_load(&state->ref_count);
	} while (!zend_atomic_int_compare_exchange(&state->ref_count, &old, old - 1));

	if (old == 1) {
		async_future_shared_state_destroy(state);
	}
}

/**
 * @brief Create a source callback for subscribing to a source future.
 *
 * When the source future completes, the callback transfers its result
 * (or exception) into the shared state and fires the trigger. The callback
 * holds one reference to the shared state.
 *
 * @param state  The shared state (ref_count is incremented).
 * @return Event callback suitable for add_callback() on the source future.
 */
zend_async_event_callback_t *async_future_shared_state_source_cb(zend_future_shared_state_t *state);

///////////////////////////////////////////////////////////
/// Remote future — local future bound to a cross-thread shared state
///
/// Created in the destination thread. Proxies start/stop/dispose
/// through the trigger so the event loop handle is properly managed.
///////////////////////////////////////////////////////////

/**
 * @brief Future that receives its result from another thread via shared state.
 *
 * Extends zend_future_t with a pointer to the shared state. The start/stop/dispose
 * handlers proxy to the trigger event so the uv_async handle is ref'd/unref'd
 * correctly in the event loop.
 */
typedef struct _zend_future_remote_s {
	/** Base future (must be first for casting) */
	zend_future_t future;

	/** Shared state connecting this future to the source thread */
	zend_future_shared_state_t *state;
} zend_future_remote_t;

/**
 * @brief Create a remote future bound to an existing shared state.
 *
 * Must be called from the destination thread. Creates the trigger on the
 * current thread's event loop and binds it to the shared state.
 *
 * @param state  The shared state (ref_count is incremented).
 * @return Remote future, or NULL on failure.
 */
zend_future_remote_t *async_new_remote_future(zend_future_shared_state_t *state);

#endif /* FUTURE_H */