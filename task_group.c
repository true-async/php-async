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

#include "task_group.h"
#include "task_group_arginfo.h"
#include "exceptions.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"

/* Task entry states — stored as IS_PTR in unified tasks HashTable */
typedef enum {
	TASK_STATE_PENDING,    /* callable waiting in queue (owns zend_fcall_t) */
	TASK_STATE_RUNNING,    /* coroutine executing */
	TASK_STATE_ERROR       /* coroutine finished with exception */
} task_state_t;

typedef struct {
	task_state_t state;
	union {
		zend_fcall_t *fcall;       /* TASK_STATE_PENDING (owned, freed on transition or cancel) */
		zend_object *coroutine;    /* TASK_STATE_RUNNING (ref-counted) */
		zend_object *exception;    /* TASK_STATE_ERROR (ref-counted) */
	};
} task_entry_t;

/* Waiter types determine notification and lifetime semantics */
typedef enum {
	WAITER_TYPE_RACE,     /* notify on any completion, then remove */
	WAITER_TYPE_ANY,      /* notify on success only, then remove */
	WAITER_TYPE_ITERATOR  /* notify on any completion, lives until iterator/group dies */
} task_group_waiter_type_t;

/* Waiter event for race/any/iterator — lightweight event allocated per wait call.
 * Registered in group->waiter_events, notified on coroutine completion.
 * Coroutine subscribes via zend_async_resume_when on this event. */
struct _task_group_waiter_event_s {
	zend_async_event_t event;
	async_task_group_t *group;
	task_group_waiter_type_t type;
};

/* Forward declarations for waiter event */
static void task_group_waiter_event_remove(const task_group_waiter_event_t *waiter);

///////////////////////////////////////////////////////////
/// Waiter event vtable
///////////////////////////////////////////////////////////

static bool waiter_event_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_push(event, callback);
}

static bool waiter_event_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_remove(event, callback);
}

static bool waiter_event_start(zend_async_event_t *event)
{
	return true;
}

static bool waiter_event_stop(zend_async_event_t *event)
{
	return true;
}

static bool waiter_event_dispose(zend_async_event_t *event)
{
	task_group_waiter_event_t *waiter = (task_group_waiter_event_t *)event;

	/* Remove from group vector */
	task_group_waiter_event_remove(waiter);

	/* Free callbacks */
	zend_async_callbacks_free(event);

	efree(waiter);
	return true;
}

static task_group_waiter_event_t *task_group_waiter_event_new(
	async_task_group_t *group, const task_group_waiter_type_t type)
{
	task_group_waiter_event_t *waiter = ecalloc(1, sizeof(task_group_waiter_event_t));

	waiter->event.ref_count = 1;
	waiter->event.add_callback = waiter_event_add_callback;
	waiter->event.del_callback = waiter_event_del_callback;
	waiter->event.start = waiter_event_start;
	waiter->event.stop = waiter_event_stop;
	waiter->event.dispose = waiter_event_dispose;
	waiter->group = group;
	waiter->type = type;

	/* Add to group vector */
	if (group->waiter_events_length == group->waiter_events_capacity) {
		const uint32_t new_cap = group->waiter_events_capacity ? group->waiter_events_capacity * 2 : 4;
		group->waiter_events = safe_erealloc(group->waiter_events, new_cap, sizeof(task_group_waiter_event_t *), 0);
		group->waiter_events_capacity = new_cap;
	}

	group->waiter_events[group->waiter_events_length++] = waiter;

	return waiter;
}

static void task_group_waiter_event_remove(const task_group_waiter_event_t *waiter)
{
	async_task_group_t *group = waiter->group;

	if (UNEXPECTED(group == NULL)) {
		return;
	}

	for (uint32_t i = 0; i < group->waiter_events_length; i++) {
		if (group->waiter_events[i] == waiter) {
			/* Swap with last */
			group->waiter_events[i] = group->waiter_events[--group->waiter_events_length];

			/* Adjust active iterator */
			if (i < group->waiter_notify_index) {
				group->waiter_notify_index--;
			}
			return;
		}
	}
}

#define METHOD(name) PHP_METHOD(Async_TaskGroup, name)
#define THIS_GROUP() ASYNC_TASK_GROUP_FROM_OBJ(Z_OBJ_P(ZEND_THIS))

zend_class_entry *async_ce_task_group = NULL;
static zend_object_handlers task_group_handlers;

/* Forward declarations */
static void task_group_try_complete(async_task_group_t *group);
static void task_group_drain(async_task_group_t *group);
static void task_group_on_coroutine_complete(
	zend_async_event_t *event, zend_async_event_callback_t *callback, void *result, zend_object *exception);

///////////////////////////////////////////////////////////
/// task_entry_t lifecycle
///////////////////////////////////////////////////////////

static task_entry_t *task_entry_new_pending(zend_fcall_t *fcall)
{
	task_entry_t *entry = emalloc(sizeof(task_entry_t));
	entry->state = TASK_STATE_PENDING;
	entry->fcall = fcall;
	return entry;
}

static task_entry_t *task_entry_new_running(zend_object *coroutine)
{
	task_entry_t *entry = emalloc(sizeof(task_entry_t));
	entry->state = TASK_STATE_RUNNING;
	entry->coroutine = coroutine;
	GC_ADDREF(coroutine);
	return entry;
}

static void task_entry_free(task_entry_t *entry)
{
	switch (entry->state) {
		case TASK_STATE_PENDING:
			zend_fcall_release(entry->fcall);
			break;
		case TASK_STATE_RUNNING:
			OBJ_RELEASE(entry->coroutine);
			break;
		case TASK_STATE_ERROR:
			OBJ_RELEASE(entry->exception);
			break;
	}
	efree(entry);
}

/* Custom dtor for tasks HashTable elements */
static void task_zval_dtor(zval *zv)
{
	if (Z_TYPE_P(zv) == IS_PTR) {
		task_entry_free((task_entry_t *)Z_PTR_P(zv));
	} else {
		zval_ptr_dtor(zv);
	}
}

///////////////////////////////////////////////////////////
/// Helpers: check task state in unified array
///////////////////////////////////////////////////////////

static zend_always_inline bool task_is_pending(const zval *zv)
{
	return Z_TYPE_P(zv) == IS_PTR && ((task_entry_t *)Z_PTR_P(zv))->state == TASK_STATE_PENDING;
}

static zend_always_inline bool task_is_running(const zval *zv)
{
	return Z_TYPE_P(zv) == IS_PTR && ((task_entry_t *)Z_PTR_P(zv))->state == TASK_STATE_RUNNING;
}

static zend_always_inline bool task_is_error(const zval *zv)
{
	return Z_TYPE_P(zv) == IS_PTR && ((task_entry_t *)Z_PTR_P(zv))->state == TASK_STATE_ERROR;
}

static zend_always_inline bool task_is_completed(const zval *zv)
{
	return Z_TYPE_P(zv) != IS_PTR;
}

static zend_always_inline bool task_is_settled(const zval *zv)
{
	return Z_TYPE_P(zv) != IS_PTR
		|| ((task_entry_t *)Z_PTR_P(zv))->state == TASK_STATE_ERROR;
}

/* Check if all tasks in the group are settled (success or error) */
static zend_always_inline bool task_group_all_settled(const async_task_group_t *group)
{
	return group->active_coroutines == 0;
}

/* Check if there are any pending tasks */
static bool task_group_has_pending(const async_task_group_t *group)
{
	zval *zv;
	ZEND_HASH_FOREACH_VAL(&group->tasks, zv) {
		if (task_is_pending(zv)) {
			return true;
		}
	} ZEND_HASH_FOREACH_END();
	return false;
}

///////////////////////////////////////////////////////////
/// Extended callback — holds task key reference
///////////////////////////////////////////////////////////

typedef struct {
	zend_coroutine_event_callback_t base;
	async_task_group_t *group;
	zval key;
} task_group_coroutine_callback_t;

static void task_group_callback_dispose(zend_async_event_callback_t *callback, zend_async_event_t *event)
{
	task_group_coroutine_callback_t *cb = (task_group_coroutine_callback_t *)callback;
	zval_ptr_dtor(&cb->key);
	efree(cb);
}

///////////////////////////////////////////////////////////
/// Event vtable
///////////////////////////////////////////////////////////

static bool task_group_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_push(event, callback);
}

static bool task_group_del_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_remove(event, callback);
}

static bool task_group_start(zend_async_event_t *event)
{
	return true;
}

static bool task_group_stop(zend_async_event_t *event)
{
	return true;
}

static bool task_group_dispose(zend_async_event_t *event)
{
	return true;
}

static bool task_group_replay(zend_async_event_t *event,
	zend_async_event_callback_t *callback, zval *result, zend_object **exception)
{
	const async_task_group_t *group = ASYNC_TASK_GROUP_FROM_EVENT(event);

	if (task_group_all_settled(group) && !task_group_has_pending(group)) {
		if (callback != NULL) {
			zval undef;
			ZVAL_UNDEF(&undef);
			callback->callback(event, callback, &undef, NULL);
			return true;
		}
		if (result != NULL) {
			ZVAL_NULL(result);
		}
		if (exception != NULL) {
			*exception = NULL;
		}
		return true;
	}

	return false;
}

static zend_string *task_group_info(zend_async_event_t *event)
{
	const async_task_group_t *group = ASYNC_TASK_GROUP_FROM_EVENT(event);
	const uint32_t total = zend_hash_num_elements(&group->tasks);
	return zend_strpprintf(0, "TaskGroup(total=%u, active=%d)", total, group->active_coroutines);
}

static void task_group_event_init(async_task_group_t *group)
{
	zend_async_event_t *event = &group->event;
	memset(event, 0, sizeof(zend_async_event_t));

	event->flags = ZEND_ASYNC_EVENT_F_ZEND_OBJ;
	event->zend_object_offset = XtOffsetOf(async_task_group_t, std);
	event->add_callback = task_group_add_callback;
	event->del_callback = task_group_del_callback;
	event->start = task_group_start;
	event->stop = task_group_stop;
	event->dispose = task_group_dispose;
	event->replay = task_group_replay;
	event->info = task_group_info;
}

///////////////////////////////////////////////////////////
/// Object lifecycle
///////////////////////////////////////////////////////////

static zend_object *task_group_create_object(zend_class_entry *ce)
{
	async_task_group_t *group = zend_object_alloc(sizeof(async_task_group_t), ce);

	zend_object_std_init(&group->std, ce);
	group->std.handlers = &task_group_handlers;

	task_group_event_init(group);

	group->scope = NULL;
	group->concurrency = 0;
	group->active_coroutines = 0;
	group->next_key = 0;
	group->finally_handlers = NULL;

	zend_hash_init(&group->tasks, 8, NULL, task_zval_dtor, 0);

	group->waiter_events = NULL;
	group->waiter_events_length = 0;
	group->waiter_events_capacity = 0;

	return &group->std;
}

static void task_group_dtor_object(zend_object *object)
{
	async_task_group_t *group = ASYNC_TASK_GROUP_FROM_OBJ(object);

	/* Report unhandled errors to scope exception handler */
	if (!ZEND_ASYNC_EVENT_IS_EXCEPTION_HANDLED(&group->event) && group->scope != NULL) {
		bool has_errors = false;
		zend_object *composite = NULL;
		zval *zv;

		ZEND_HASH_FOREACH_VAL(&group->tasks, zv) {
			if (task_is_error(zv)) {
				if (!has_errors) {
					composite = async_new_composite_exception();
					has_errors = true;
				}

				async_composite_exception_add_exception(composite, ((task_entry_t *)Z_PTR_P(zv))->exception, false);
			}
		} ZEND_HASH_FOREACH_END();

		if (has_errors) {
			ZEND_ASYNC_SCOPE_CATCH(&group->scope->scope, NULL, NULL, composite, true, false);
		}
	}

	/* Dispose owned scope (analogous to scope_destroy for Scope objects).
	 * Must happen in dtor, not free, because coroutines may still be alive. */
	if (group->scope != NULL) {
		async_scope_t *scope = group->scope;
		group->scope = NULL;

		if (false == scope->scope.try_to_dispose(&scope->scope)) {
			zend_object *exception = async_new_exception(async_ce_cancellation_exception,
														 "Scope is being disposed due to TaskGroup destruction");

			ZEND_ASYNC_SCOPE_CANCEL(&scope->scope, exception, true, ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(&scope->scope));
		}
	}

	zend_object_std_dtor(object);
}

static void task_group_free_object(zend_object *object)
{
	async_task_group_t *group = ASYNC_TASK_GROUP_FROM_OBJ(object);

	zend_hash_destroy(&group->tasks);

	/* Free remaining waiter events */
	if (group->waiter_events != NULL) {
		for (uint32_t i = 0; i < group->waiter_events_length; i++) {
			task_group_waiter_event_t *waiter = group->waiter_events[i];
			waiter->group = NULL;
			zend_async_callbacks_free(&waiter->event);
			efree(waiter);
		}
		efree(group->waiter_events);
		group->waiter_events = NULL;
	}

	/* Free finally handlers */
	if (group->finally_handlers != NULL) {
		zend_array_destroy(group->finally_handlers);
		group->finally_handlers = NULL;
	}

	/* Free event callbacks */
	zend_async_callbacks_free(&group->event);

	/* Scope is released in dtor_obj, but guard against leaks */
	ZEND_ASSERT(group->scope == NULL && "Scope should have been released in dtor");
}

static HashTable *task_group_get_gc(zend_object *object, zval **table, int *n)
{
	async_task_group_t *group = ASYNC_TASK_GROUP_FROM_OBJ(object);
	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();

	/* GC all task entries */
	zval *zv;
	ZEND_HASH_FOREACH_VAL(&group->tasks, zv) {
		if (Z_TYPE_P(zv) == IS_PTR) {
			task_entry_t *entry = (task_entry_t *)Z_PTR_P(zv);
			switch (entry->state) {
				case TASK_STATE_PENDING:
					if (entry->fcall != NULL) {
						zend_get_gc_buffer_add_zval(buf, &entry->fcall->fci.function_name);
						for (uint32_t p = 0; p < entry->fcall->fci.param_count; p++) {
							zend_get_gc_buffer_add_zval(buf, &entry->fcall->fci.params[p]);
						}
					}
					break;
				case TASK_STATE_RUNNING:
					zend_get_gc_buffer_add_obj(buf, entry->coroutine);
					break;
				case TASK_STATE_ERROR:
					zend_get_gc_buffer_add_obj(buf, entry->exception);
					break;
			}
		} else {
			zend_get_gc_buffer_add_zval(buf, zv);
		}
	} ZEND_HASH_FOREACH_END();

	/* GC finally handlers */
	if (group->finally_handlers != NULL) {
		ZEND_HASH_FOREACH_VAL(group->finally_handlers, zv) {
			zend_get_gc_buffer_add_zval(buf, zv);
		} ZEND_HASH_FOREACH_END();
	}

	zend_get_gc_buffer_use(buf, table, n);
	return NULL;
}

///////////////////////////////////////////////////////////
/// Internal functions
///////////////////////////////////////////////////////////

static void task_group_finally_handlers_dtor(finally_handlers_context_t *context)
{
	async_task_group_t *group = (async_task_group_t *) context->target;
	if (group != NULL) {
		context->target = NULL;
		OBJ_RELEASE(&group->std);
	}
}

static void task_group_try_complete(async_task_group_t *group)
{
	if (!ASYNC_TASK_GROUP_IS_SEALED(group)) {
		return;
	}

	if (group->active_coroutines > 0 || task_group_has_pending(group)) {
		return;
	}

	if (ASYNC_TASK_GROUP_IS_COMPLETED(group)) {
		return;
	}

	ASYNC_TASK_GROUP_SET_COMPLETED(group);

	/* Notify waiter events — wake any suspended any()/race()/iterator.
	 * This is critical for any() when all tasks failed: the per-task notification
	 * skips ANY waiters on error, so they must be woken here at terminal state. */
	for (uint32_t i = 0; i < group->waiter_events_length; i++) {
		ZEND_ASYNC_CALLBACKS_NOTIFY(&group->waiter_events[i]->event, NULL, NULL);
	}

	/* Notify all/await waiters — group is fully settled */
	ZEND_ASYNC_CALLBACKS_NOTIFY(&group->event, NULL, NULL);
	ZEND_ASYNC_EVENT_SET_CLOSED(&group->event);

	/* Fire finally handlers asynchronously */
	if (group->finally_handlers != NULL && zend_hash_num_elements(group->finally_handlers) > 0) {
		HashTable *handlers = group->finally_handlers;
		group->finally_handlers = NULL;

		finally_handlers_context_t *context = ecalloc(1, sizeof(finally_handlers_context_t));
		context->target = group;
		context->scope = &group->scope->scope;
		context->dtor = task_group_finally_handlers_dtor;
		context->params_count = 1;
		ZVAL_OBJ(&context->params[0], &group->std);

		if (async_call_finally_handlers(handlers, context, 0)) {
			GC_ADDREF(&group->std);
		} else {
			efree(context);
			zend_array_destroy(handlers);
		}
	}
}

static bool task_group_has_slot(const async_task_group_t *group)
{
	return group->concurrency == 0 || group->active_coroutines < (int32_t)group->concurrency;
}

static void task_group_spawn_coroutine(async_task_group_t *group, zend_fcall_t *fcall, zval *key, task_entry_t *pending_entry)
{
	/* Create coroutine in group's scope */
	async_coroutine_t *coroutine = (async_coroutine_t *) ZEND_ASYNC_SPAWN_WITH(&group->scope->scope);

	if (UNEXPECTED(coroutine == NULL || EG(exception))) {
		return;
	}

	/* Transfer fcall ownership to coroutine */
	coroutine->coroutine.fcall = fcall;

	/* Transition entry: PENDING → RUNNING */
	if (pending_entry != NULL) {
		/* fcall ownership transferred to coroutine, clear pointer without releasing */
		pending_entry->fcall = NULL;
		pending_entry->state = TASK_STATE_RUNNING;
		pending_entry->coroutine = &coroutine->std;
		GC_ADDREF(&coroutine->std);
	} else {
		/* Direct spawn — create RUNNING entry in tasks */
		task_entry_t *entry = task_entry_new_running(&coroutine->std);
		zval ptr_zv;
		ZVAL_PTR(&ptr_zv, entry);

		if (Z_TYPE_P(key) == IS_STRING) {
			zend_hash_add_new(&group->tasks, Z_STR_P(key), &ptr_zv);
		} else {
			zend_hash_index_add_new(&group->tasks, Z_LVAL_P(key), &ptr_zv);
		}
	}

	/* Create extended callback with key */
	task_group_coroutine_callback_t *cb = ecalloc(1, sizeof(task_group_coroutine_callback_t));
	cb->base.base.ref_count = 0;
	cb->base.base.callback = task_group_on_coroutine_complete;
	cb->base.base.dispose = task_group_callback_dispose;
	cb->base.coroutine = NULL;
	cb->base.event = &coroutine->coroutine.event;
	cb->group = group;
	ZVAL_COPY(&cb->key, key);

	coroutine->coroutine.event.add_callback(&coroutine->coroutine.event, &cb->base.base);

	group->active_coroutines++;
	GC_ADDREF(&group->std);
}

/* Drain pending tasks using internal pointer */
static void task_group_drain(async_task_group_t *group)
{
	zval *zv;

	zend_hash_internal_pointer_reset(&group->tasks);

	while (task_group_has_slot(group)) {
		/* Scan forward to find next PENDING entry */
		bool found = false;
		while ((zv = zend_hash_get_current_data(&group->tasks)) != NULL) {
			if (task_is_pending(zv)) {
				found = true;
				break;
			}
			zend_hash_move_forward(&group->tasks);
		}

		if (!found) {
			break;
		}

		task_entry_t *entry = (task_entry_t *)Z_PTR_P(zv);

		zval key_zv;
		zend_hash_get_current_key_zval(&group->tasks, &key_zv);

		task_group_spawn_coroutine(group, entry->fcall, &key_zv, entry);
		zval_ptr_dtor(&key_zv);

		zend_hash_move_forward(&group->tasks);

		if (UNEXPECTED(EG(exception))) {
			break;
		}
	}
}

static void task_group_on_coroutine_complete(
	zend_async_event_t *event, zend_async_event_callback_t *callback, void *result, zend_object *exception)
{
	const task_group_coroutine_callback_t *group_callback = (task_group_coroutine_callback_t *)callback;
	async_task_group_t *group = group_callback->group;
	zval *slot;
	task_entry_t *old_entry;

	/* Find the slot in tasks HashTable */
	if (Z_TYPE(group_callback->key) == IS_STRING) {
		slot = zend_hash_find(&group->tasks, Z_STR(group_callback->key));
	} else {
		slot = zend_hash_index_find(&group->tasks, Z_LVAL(group_callback->key));
	}

	if (UNEXPECTED(slot == NULL)) {
		goto done;
	}

	old_entry = (task_entry_t *)Z_PTR_P(slot);

	if (exception != NULL) {
		/* Mark exception as handled on the coroutine so it won't propagate */
		ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(event);

		/* Transition: RUNNING → ERROR */
		OBJ_RELEASE(old_entry->coroutine);
		old_entry->state = TASK_STATE_ERROR;
		old_entry->exception = exception;
		GC_ADDREF(exception);

		/* New error → reset EXCEPTION_HANDLED on group */
		ZEND_ASYNC_EVENT_CLR_EXCEPTION_HANDLED(&group->event);
	} else {
		/* Transition: RUNNING → success (replace IS_PTR with result zval) */
		const zval *result_zv = (zval *)result;
		zval result_copy;

		if (result_zv != NULL && Z_TYPE_P(result_zv) != IS_UNDEF) {
			ZVAL_COPY(&result_copy, result_zv);
		} else {
			ZVAL_NULL(&result_copy);
		}

		/* Release coroutine ref (added in task_entry_new_running), then free entry */
		OBJ_RELEASE(old_entry->coroutine);
		efree(old_entry);
		ZVAL_COPY_VALUE(slot, &result_copy);
	}

done:
	group->active_coroutines--;

	/* Drain pending tasks */
	task_group_drain(group);

	/* Notify waiter events based on type.
	 * Coroutine owns the waiter (trans_event=true), so dispose handles cleanup.
	 * Use waiter_notify_index for safe iteration — dispose may remove from vector. */
	const bool is_success = (exception == NULL);

	group->waiter_notify_index = 0;
	while (group->waiter_notify_index < group->waiter_events_length) {
		task_group_waiter_event_t *waiter = group->waiter_events[group->waiter_notify_index];

		if (waiter->type == WAITER_TYPE_ANY && !is_success) {
			group->waiter_notify_index++;
			continue;
		}

		group->waiter_notify_index++;
		ZEND_ASYNC_CALLBACKS_NOTIFY(&waiter->event, result, NULL);
	}

	/* Check terminal state */
	task_group_try_complete(group);

	/* Release group reference (added in spawn_coroutine) */
	OBJ_RELEASE(&group->std);
}

///////////////////////////////////////////////////////////
/// Helper: build results/errors arrays from unified tasks
///////////////////////////////////////////////////////////

static HashTable *task_group_collect_results(const async_task_group_t *group)
{
	HashTable *ht = zend_new_array(zend_hash_num_elements(&group->tasks));
	zval *zv;
	zend_string *str_key;
	zend_ulong num_key;

	ZEND_HASH_FOREACH_KEY_VAL(&group->tasks, num_key, str_key, zv) {
		if (task_is_completed(zv)) {
			zval copy;
			ZVAL_COPY(&copy, zv);
			if (str_key != NULL) {
				zend_hash_add_new(ht, str_key, &copy);
			} else {
				zend_hash_index_add_new(ht, num_key, &copy);
			}
		}
	} ZEND_HASH_FOREACH_END();

	return ht;
}

static bool task_group_has_errors(const async_task_group_t *group)
{
	zval *zv;
	ZEND_HASH_FOREACH_VAL(&group->tasks, zv) {
		if (task_is_error(zv)) {
			return true;
		}
	} ZEND_HASH_FOREACH_END();
	return false;
}

static zend_object *task_group_collect_composite_exception(const async_task_group_t *group)
{
	zend_object *composite = async_new_composite_exception();
	zval *zv;

	ZEND_HASH_FOREACH_VAL(&group->tasks, zv) {
		if (task_is_error(zv)) {
			async_composite_exception_add_exception(composite, ((task_entry_t *)Z_PTR_P(zv))->exception, false);
		}
	} ZEND_HASH_FOREACH_END();

	return composite;
}

static HashTable *task_group_collect_errors(const async_task_group_t *group)
{
	HashTable *ht = zend_new_array(4);
	zval *zv;
	zend_string *str_key;
	zend_ulong num_key;

	ZEND_HASH_FOREACH_KEY_VAL(&group->tasks, num_key, str_key, zv) {
		if (task_is_error(zv)) {
			zval err;
			ZVAL_OBJ_COPY(&err, ((task_entry_t *)Z_PTR_P(zv))->exception);
			if (str_key != NULL) {
				zend_hash_add_new(ht, str_key, &err);
			} else {
				zend_hash_index_add_new(ht, num_key, &err);
			}
		}
	} ZEND_HASH_FOREACH_END();

	return ht;
}

static bool task_group_has_success(const async_task_group_t *group)
{
	zval *zv;
	ZEND_HASH_FOREACH_VAL(&group->tasks, zv) {
		if (task_is_completed(zv)) {
			return true;
		}
	} ZEND_HASH_FOREACH_END();
	return false;
}

///////////////////////////////////////////////////////////
/// Iterator
///////////////////////////////////////////////////////////

static void task_group_iterator_dtor(zend_object_iterator *iter)
{
	task_group_iterator_t *iterator = (task_group_iterator_t *)iter;
	zval_ptr_dtor(&iterator->current);
	zval_ptr_dtor(&iterator->current_key);
	zval_ptr_dtor(&iter->data);
}

static zend_result task_group_iterator_valid(zend_object_iterator *iter)
{
	return ((task_group_iterator_t *)iter)->valid ? SUCCESS : FAILURE;
}

static zval *task_group_iterator_get_current_data(zend_object_iterator *iter)
{
	return &((task_group_iterator_t *)iter)->current;
}

static void task_group_iterator_get_current_key(zend_object_iterator *iter, zval *key)
{
	task_group_iterator_t *iterator = (task_group_iterator_t *)iter;
	ZVAL_COPY(key, &iterator->current_key);
}

static void task_group_iterator_set_current(task_group_iterator_t *iterator,
	zval *key, zval *result, zend_object *error)
{
	zval_ptr_dtor(&iterator->current);
	zval_ptr_dtor(&iterator->current_key);

	/* Build [result, error] array */
	zval pair;
	array_init_size(&pair, 2);

	if (result != NULL) {
		Z_TRY_ADDREF_P(result);
		zend_hash_next_index_insert(Z_ARRVAL(pair), result);
	} else {
		zval null_zv;
		ZVAL_NULL(&null_zv);
		zend_hash_next_index_insert(Z_ARRVAL(pair), &null_zv);
	}

	if (error != NULL) {
		zval err_zv;
		ZVAL_OBJ_COPY(&err_zv, error);
		zend_hash_next_index_insert(Z_ARRVAL(pair), &err_zv);
	} else {
		zval null_zv;
		ZVAL_NULL(&null_zv);
		zend_hash_next_index_insert(Z_ARRVAL(pair), &null_zv);
	}

	ZVAL_COPY_VALUE(&iterator->current, &pair);
	ZVAL_COPY(&iterator->current_key, key);
	iterator->position++;
}

static void task_group_iterator_move_forward(zend_object_iterator *iter)
{
	task_group_iterator_t *iterator = (task_group_iterator_t *)iter;
	async_task_group_t *group = iterator->group;
	uint32_t idx;
	zval *zv;
	zend_string *str_key;
	zend_ulong num_key;
	zend_coroutine_t *current;

retry:
	/* Walk tasks in spawn order, starting from current position */
	idx = 0;

	ZEND_HASH_FOREACH_KEY_VAL(&group->tasks, num_key, str_key, zv) {
		if (idx < iterator->position) {
			idx++;
			continue;
		}

		/* Found our position — check if settled */
		if (task_is_completed(zv)) {
			zval key_zv;
			if (str_key != NULL) {
				ZVAL_STR_COPY(&key_zv, str_key);
			} else {
				ZVAL_LONG(&key_zv, num_key);
			}
			task_group_iterator_set_current(iterator, &key_zv, zv, NULL);
			zval_ptr_dtor(&key_zv);
			iterator->valid = true;
			return;
		}

		if (task_is_error(zv)) {
			task_entry_t *entry = (task_entry_t *)Z_PTR_P(zv);
			zval key_zv;
			if (str_key != NULL) {
				ZVAL_STR_COPY(&key_zv, str_key);
			} else {
				ZVAL_LONG(&key_zv, num_key);
			}
			task_group_iterator_set_current(iterator, &key_zv, NULL, entry->exception);
			zval_ptr_dtor(&key_zv);
			iterator->valid = true;
			return;
		}

		/* PENDING or RUNNING — need to wait */
		break;
	} ZEND_HASH_FOREACH_END();

	/* Check termination: past all elements */
	if (iterator->position >= zend_hash_num_elements(&group->tasks)) {
		if (ASYNC_TASK_GROUP_IS_SEALED(group) && group->active_coroutines == 0
			&& !task_group_has_pending(group)) {
			iterator->valid = false;
			return;
		}
	}

	/* Async — suspend and wait for next result */
	current = (zend_coroutine_t *)ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(current == NULL)) {
		async_throw_error("TaskGroup iterator can only be used inside a coroutine");
		iterator->valid = false;
		return;
	}

	zend_async_waker_new(current);

	if (UNEXPECTED(EG(exception))) {
		iterator->valid = false;
		return;
	}

	{
		task_group_waiter_event_t *waiter = task_group_waiter_event_new(group, WAITER_TYPE_ITERATOR);

		zend_async_resume_when(current, &waiter->event, true, zend_async_waker_callback_resolve, NULL);

		if (UNEXPECTED(EG(exception))) {
			zend_async_waker_clean(current);
			iterator->valid = false;
			return;
		}

		ZEND_ASYNC_SUSPEND();
		zend_async_waker_clean(current);
	}

	if (UNEXPECTED(EG(exception))) {
		iterator->valid = false;
		return;
	}

	goto retry;
}

static void task_group_iterator_rewind(zend_object_iterator *iter)
{
	task_group_iterator_t *iterator = (task_group_iterator_t *)iter;
	if (!iterator->started) {
		iterator->started = true;
		task_group_iterator_move_forward(iter);
	}
}

static const zend_object_iterator_funcs task_group_iterator_funcs = {
	.dtor = task_group_iterator_dtor,
	.valid = task_group_iterator_valid,
	.get_current_data = task_group_iterator_get_current_data,
	.get_current_key = task_group_iterator_get_current_key,
	.move_forward = task_group_iterator_move_forward,
	.rewind = task_group_iterator_rewind,
};

static zend_object_iterator *task_group_get_iterator(zend_class_entry *ce, zval *object, int by_ref)
{
	if (UNEXPECTED(by_ref)) {
		zend_throw_error(NULL, "Cannot iterate TaskGroup by reference");
		return NULL;
	}

	task_group_iterator_t *iterator = ecalloc(1, sizeof(task_group_iterator_t));
	zend_iterator_init(&iterator->it);

	iterator->it.funcs = &task_group_iterator_funcs;
	ZVAL_COPY(&iterator->it.data, object);
	iterator->group = ASYNC_TASK_GROUP_FROM_OBJ(Z_OBJ_P(object));
	ZVAL_UNDEF(&iterator->current);
	ZVAL_UNDEF(&iterator->current_key);
	iterator->position = 0;
	iterator->valid = true;
	iterator->started = false;

	/* Mark errors as handled — user takes responsibility via iterator */
	ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(&iterator->group->event);

	return &iterator->it;
}

///////////////////////////////////////////////////////////
/// API
///////////////////////////////////////////////////////////

zend_async_group_t *async_new_group(uint32_t concurrency, zend_object *scope_obj)
{
	zval zv;
	object_init_ex(&zv, async_ce_task_group);

	if (UNEXPECTED(EG(exception))) {
		zval_ptr_dtor(&zv);
		return NULL;
	}

	async_task_group_t *group = ASYNC_TASK_GROUP_FROM_OBJ(Z_OBJ(zv));
	group->concurrency = concurrency;

	if (scope_obj != NULL) {
		const async_scope_object_t *scope_object = (async_scope_object_t *)scope_obj;
		if (UNEXPECTED(scope_object->scope == NULL)) {
			async_throw_error("Cannot use a disposed Scope for TaskGroup");
			zval_ptr_dtor(&zv);
			return NULL;
		}
		ZEND_ASYNC_EVENT_ADD_REF(&scope_object->scope->scope.event);
		group->scope = scope_object->scope;
	} else {
		zend_async_scope_t *child_scope = async_new_scope(ZEND_ASYNC_CURRENT_SCOPE, false);

		if (UNEXPECTED(child_scope == NULL)) {
			async_throw_error("Failed to create child scope for TaskGroup");
			zval_ptr_dtor(&zv);
			return NULL;
		}

		group->scope = (async_scope_t *)child_scope;
	}

	return (zend_async_group_t *)group;
}

///////////////////////////////////////////////////////////
/// PHP Methods
///////////////////////////////////////////////////////////

METHOD(__construct)
{
	zend_long concurrency = 0;
	zend_object *scope_obj = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 2)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(concurrency)
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(scope_obj, async_ce_scope)
	ZEND_PARSE_PARAMETERS_END();

	async_task_group_t *group = THIS_GROUP();

	if (UNEXPECTED(concurrency < 0)) {
		zend_argument_value_error(1, "must be greater than or equal to 0");
		RETURN_THROWS();
	}
	group->concurrency = (uint32_t)concurrency;

	if (scope_obj != NULL) {
		const async_scope_object_t *scope_object = (async_scope_object_t *)scope_obj;
		if (UNEXPECTED(scope_object->scope == NULL)) {
			async_throw_error("Cannot use a disposed Scope for TaskGroup");
			RETURN_THROWS();
		}
		ZEND_ASYNC_EVENT_ADD_REF(&scope_object->scope->scope.event);
		group->scope = scope_object->scope;
	} else {
		zend_async_scope_t *child_scope = async_new_scope(ZEND_ASYNC_CURRENT_SCOPE, false);

		if (UNEXPECTED(child_scope == NULL)) {
			async_throw_error("Failed to create child scope for TaskGroup");
			RETURN_THROWS();
		}

		group->scope = (async_scope_t *)child_scope;
	}
}

/* Internal spawn implementation shared by spawn() and spawnWithKey() */
static void task_group_do_spawn(async_task_group_t *group, zval *key_zv,
	zend_fcall_info *fci, zend_fcall_info_cache *fcc, zval *args, int args_count, HashTable *named_args)
{
	/* Check sealed/completed */
	if (UNEXPECTED(ASYNC_TASK_GROUP_IS_SEALED(group))) {
		async_throw_error("Cannot spawn tasks on a sealed TaskGroup");
		return;
	}

	if (UNEXPECTED(ASYNC_TASK_GROUP_IS_COMPLETED(group))) {
		async_throw_error("Cannot spawn tasks on a completed TaskGroup");
		return;
	}

	/* Check duplicate key */
	bool duplicate;
	if (Z_TYPE_P(key_zv) == IS_STRING) {
		duplicate = zend_hash_exists(&group->tasks, Z_STR_P(key_zv));
	} else {
		duplicate = zend_hash_index_exists(&group->tasks, Z_LVAL_P(key_zv));
	}

	if (UNEXPECTED(duplicate)) {
		if (Z_TYPE_P(key_zv) == IS_STRING) {
			async_throw_error("Duplicate key \"%s\" in TaskGroup", ZSTR_VAL(Z_STR_P(key_zv)));
		} else {
			async_throw_error("Duplicate key " ZEND_LONG_FMT " in TaskGroup", Z_LVAL_P(key_zv));
		}
		return;
	}

	/* Build zend_fcall_t */
	ZEND_ASYNC_FCALL_DEFINE(fcall, (*fci), (*fcc), args, args_count, named_args);

	/* Spawn immediately or queue as pending */
	if (task_group_has_slot(group)) {
		task_group_spawn_coroutine(group, fcall, key_zv, NULL);
	} else {
		task_entry_t *entry = task_entry_new_pending(fcall);
		zval ptr_zv;
		ZVAL_PTR(&ptr_zv, entry);

		if (Z_TYPE_P(key_zv) == IS_STRING) {
			zend_hash_add_new(&group->tasks, Z_STR_P(key_zv), &ptr_zv);
		} else {
			zend_hash_index_add_new(&group->tasks, Z_LVAL_P(key_zv), &ptr_zv);
		}
	}
}

METHOD(spawn)
{
	zval *args = NULL;
	int args_count = 0;
	HashTable *named_args = NULL;
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(1, -1)
		Z_PARAM_FUNC(fci, fcc)
		Z_PARAM_VARIADIC_WITH_NAMED(args, args_count, named_args)
	ZEND_PARSE_PARAMETERS_END();

	async_task_group_t *group = THIS_GROUP();

	zval key_zv;
	ZVAL_LONG(&key_zv, group->next_key++);

	task_group_do_spawn(group, &key_zv, &fci, &fcc, args, args_count, named_args);
}

METHOD(spawnWithKey)
{
	zval *key = NULL;
	zval *args = NULL;
	int args_count = 0;
	HashTable *named_args = NULL;
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;

	ZEND_PARSE_PARAMETERS_START(2, -1)
		Z_PARAM_ZVAL(key)
		Z_PARAM_FUNC(fci, fcc)
		Z_PARAM_VARIADIC_WITH_NAMED(args, args_count, named_args)
	ZEND_PARSE_PARAMETERS_END();

	async_task_group_t *group = THIS_GROUP();

	zval key_zv;
	ZVAL_COPY(&key_zv, key);

	task_group_do_spawn(group, &key_zv, &fci, &fcc, args, args_count, named_args);
	zval_ptr_dtor(&key_zv);
}

METHOD(all)
{
	bool ignore_errors = false;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_BOOL(ignore_errors)
	ZEND_PARSE_PARAMETERS_END();

	async_task_group_t *group = THIS_GROUP();

retry:
	/* Check if all settled */
	if (task_group_all_settled(group) && !task_group_has_pending(group)) {
		/* Check errors */
		if (!ignore_errors && task_group_has_errors(group)) {
			zval composite_zv;

			ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(&group->event);
			zend_object *composite = task_group_collect_composite_exception(group);
			ZVAL_OBJ(&composite_zv, composite);
			zend_throw_exception_object(&composite_zv);
			RETURN_THROWS();
		}

		/* Return results in spawn order */
		RETURN_ARR(task_group_collect_results(group));
	}

	/* Suspend and wait */
	zend_coroutine_t *current = (zend_coroutine_t *) ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(current == NULL)) {
		async_throw_error("TaskGroup::all() can only be called inside a coroutine");
		RETURN_THROWS();
	}

	zend_async_waker_new(current);

	if (UNEXPECTED(EG(exception))) {
		RETURN_THROWS();
	}

	zend_async_resume_when(current, &group->event, false, zend_async_waker_callback_resolve, NULL);

	if (UNEXPECTED(EG(exception))) {
		zend_async_waker_clean(current);
		RETURN_THROWS();
	}

	ZEND_ASYNC_SUSPEND();
	zend_async_waker_clean(current);

	if (UNEXPECTED(EG(exception))) {
		RETURN_THROWS();
	}

	goto retry;
}

METHOD(race)
{
	async_task_group_t *group;
	zval *zv;
	zend_coroutine_t *current;

	ZEND_PARSE_PARAMETERS_NONE();

	group = THIS_GROUP();

	/* Empty group check */
	if (UNEXPECTED(zend_hash_num_elements(&group->tasks) == 0)) {
		async_throw_error("Cannot race on an empty TaskGroup");
		RETURN_THROWS();
	}

	/* Check already settled (first in spawn order) */
	ZEND_HASH_FOREACH_VAL(&group->tasks, zv) {
		if (task_is_completed(zv)) {
			RETURN_COPY(zv);
		}
		if (task_is_error(zv)) {
			task_entry_t *e = (task_entry_t *)Z_PTR_P(zv);
			zval ex_zv;
			GC_ADDREF(e->exception);
			ZVAL_OBJ(&ex_zv, e->exception);
			zend_throw_exception_object(&ex_zv);
			RETURN_THROWS();
		}
	} ZEND_HASH_FOREACH_END();

	/* Suspend — wait for first completion (success or error) */
	current = (zend_coroutine_t *)ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(current == NULL)) {
		async_throw_error("TaskGroup::race() can only be called inside a coroutine");
		RETURN_THROWS();
	}

	task_group_waiter_event_t *waiter = task_group_waiter_event_new(group, WAITER_TYPE_RACE);

	zend_async_waker_new(current);

	if (UNEXPECTED(EG(exception))) {
		RETURN_THROWS();
	}

	/* trans_event=true: waker takes ownership, dispose frees waiter */
	zend_async_resume_when(current, &waiter->event, true, zend_async_waker_callback_resolve, NULL);

	if (UNEXPECTED(EG(exception))) {
		zend_async_waker_clean(current);
		RETURN_THROWS();
	}

	ZEND_ASYNC_SUSPEND();
	zend_async_waker_clean(current);

	if (UNEXPECTED(EG(exception))) {
		RETURN_THROWS();
	}

	/* race() returns first settled — find it */
	ZEND_HASH_FOREACH_VAL(&group->tasks, zv) {
		if (task_is_completed(zv)) {
			RETURN_COPY(zv);
		}
		if (task_is_error(zv)) {
			task_entry_t *e = (task_entry_t *)Z_PTR_P(zv);
			zval ex_zv;
			GC_ADDREF(e->exception);
			ZVAL_OBJ(&ex_zv, e->exception);
			zend_throw_exception_object(&ex_zv);
			RETURN_THROWS();
		}
	} ZEND_HASH_FOREACH_END();
}

METHOD(any)
{
	zval *zv;

	ZEND_PARSE_PARAMETERS_NONE();

	async_task_group_t *group = THIS_GROUP();

	/* Empty group check */
	if (UNEXPECTED(zend_hash_num_elements(&group->tasks) == 0)) {
		async_throw_error("Cannot call any() on an empty TaskGroup");
		RETURN_THROWS();
	}

retry:
	/* Check for first success */
	if (task_group_has_success(group)) {
		ZEND_HASH_FOREACH_VAL(&group->tasks, zv) {
			if (task_is_completed(zv)) {
				RETURN_COPY(zv);
			}
		} ZEND_HASH_FOREACH_END();
	}

	/* All settled with errors only → throw composite */
	if (task_group_all_settled(group) && !task_group_has_pending(group)) {
		zval composite_zv;

		ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(&group->event);
		zend_object *composite = task_group_collect_composite_exception(group);
		ZVAL_OBJ(&composite_zv, composite);
		zend_throw_exception_object(&composite_zv);
		RETURN_THROWS();
	}

	/* Suspend — wait for next completion */
	zend_coroutine_t *current = (zend_coroutine_t *) ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(current == NULL)) {
		async_throw_error("TaskGroup::any() can only be called inside a coroutine");
		RETURN_THROWS();
	}

	task_group_waiter_event_t *waiter = task_group_waiter_event_new(group, WAITER_TYPE_ANY);

	zend_async_waker_new(current);

	if (UNEXPECTED(EG(exception))) {
		RETURN_THROWS();
	}

	/* trans_event=true: waker takes ownership */
	zend_async_resume_when(current, &waiter->event, true, zend_async_waker_callback_resolve, NULL);

	if (UNEXPECTED(EG(exception))) {
		zend_async_waker_clean(current);
		RETURN_THROWS();
	}

	ZEND_ASYNC_SUSPEND();
	zend_async_waker_clean(current);

	if (UNEXPECTED(EG(exception))) {
		RETURN_THROWS();
	}

	goto retry;
}

METHOD(getResults)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_task_group_t *group = THIS_GROUP();
	RETURN_ARR(task_group_collect_results(group));
}

METHOD(getErrors)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_task_group_t *group = THIS_GROUP();
	ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(&group->event);
	RETURN_ARR(task_group_collect_errors(group));
}

METHOD(suppressErrors)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_task_group_t *group = THIS_GROUP();
	ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(&group->event);
}

METHOD(cancel)
{
	zend_object *cancellation_obj = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJ_OF_CLASS_OR_NULL(cancellation_obj, ZEND_ASYNC_GET_EXCEPTION_CE(ZEND_ASYNC_EXCEPTION_CANCELLATION))
	ZEND_PARSE_PARAMETERS_END();

	async_task_group_t *group = THIS_GROUP();

	if (ASYNC_TASK_GROUP_IS_SEALED(group)) {
		return;
	}

	ASYNC_TASK_GROUP_SET_SEALED(group);
	ZEND_ASYNC_EVENT_SET_CLOSED(&group->event);
	ZEND_ASYNC_EVENT_SET_EXCEPTION_HANDLED(&group->event);

	/* Cancel running coroutines via scope */
	if (group->scope != NULL && false == group->scope->scope.try_to_dispose(&group->scope->scope)) {
		zend_object *exception;

		if (cancellation_obj != NULL) {
			exception = cancellation_obj;
			GC_ADDREF(exception);
		} else {
			exception = async_new_exception(async_ce_cancellation_exception, "TaskGroup cancelled");
		}

		ZEND_ASYNC_SCOPE_CANCEL(&group->scope->scope, exception, true,
			ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(&group->scope->scope));
	}

	task_group_try_complete(group);
}

METHOD(seal)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_task_group_t *group = THIS_GROUP();

	if (ASYNC_TASK_GROUP_IS_SEALED(group)) {
		return;
	}

	ASYNC_TASK_GROUP_SET_SEALED(group);
	task_group_try_complete(group);
}

METHOD(dispose)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_task_group_t *group = THIS_GROUP();

	if (group->scope != NULL && false == group->scope->scope.try_to_dispose(&group->scope->scope)) {
		zend_object *exception = async_new_exception(async_ce_cancellation_exception,
			"Scope is being disposed due to TaskGroup disposal");

		ZEND_ASYNC_SCOPE_CANCEL(&group->scope->scope, exception, true,
			ZEND_ASYNC_SCOPE_IS_DISPOSE_SAFELY(&group->scope->scope));
	}
}

METHOD(isFinished)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_task_group_t *group = THIS_GROUP();
	RETURN_BOOL(task_group_all_settled(group) && !task_group_has_pending(group));
}

METHOD(isSealed)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_task_group_t *group = THIS_GROUP();
	RETURN_BOOL(ASYNC_TASK_GROUP_IS_SEALED(group));
}

METHOD(count)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_task_group_t *group = THIS_GROUP();
	RETURN_LONG(zend_hash_num_elements(&group->tasks));
}

METHOD(onFinally)
{
	zval *callback;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(callback)
	ZEND_PARSE_PARAMETERS_END();

	async_task_group_t *group = THIS_GROUP();

	/* If already completed — call immediately */
	if (ASYNC_TASK_GROUP_IS_COMPLETED(group)) {
		zval result;
		zval param;
		ZVAL_OBJ(&param, &group->std);

		if (UNEXPECTED(call_user_function(NULL, NULL, callback, &result, 1, &param) == FAILURE)) {
			async_throw_error("Failed to call finally handler on completed TaskGroup");
			zval_ptr_dtor(&result);
			RETURN_THROWS();
		}

		zval_ptr_dtor(&result);
		return;
	}

	/* Lazy init */
	if (group->finally_handlers == NULL) {
		group->finally_handlers = zend_new_array(0);
	}

	if (UNEXPECTED(zend_hash_next_index_insert(group->finally_handlers, callback) == NULL)) {
		async_throw_error("Failed to add finally handler to TaskGroup");
		RETURN_THROWS();
	}

	Z_TRY_ADDREF_P(callback);
}

METHOD(getIterator)
{
	ZEND_PARSE_PARAMETERS_NONE();

	/* getIterator is handled by get_iterator handler */
	zend_throw_error(NULL, "An object of class Async\\TaskGroup is not a traversable object in an invalid state");
}

///////////////////////////////////////////////////////////
/// Registration
///////////////////////////////////////////////////////////

void async_register_task_group_ce(void)
{
	async_ce_task_group = register_class_Async_TaskGroup(
		async_ce_awaitable,
		zend_ce_countable,
		zend_ce_aggregate
	);

	async_ce_task_group->create_object = task_group_create_object;
	async_ce_task_group->get_iterator = task_group_get_iterator;

	memcpy(&task_group_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	task_group_handlers.offset = XtOffsetOf(async_task_group_t, std);
	task_group_handlers.get_gc = task_group_get_gc;
	task_group_handlers.dtor_obj = task_group_dtor_object;
	task_group_handlers.free_obj = task_group_free_object;
	task_group_handlers.clone_obj = NULL;
}
