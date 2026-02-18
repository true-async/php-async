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
#ifndef ASYNC_TASK_GROUP_H
#define ASYNC_TASK_GROUP_H

#include "php_async_api.h"
#include <Zend/zend_async_API.h>
#include "scope.h"
#include "coroutine.h"

/* TaskGroup-specific event flags (bits 11+) */
#define ASYNC_TASK_GROUP_F_COMPLETED (1u << 11)
#define ASYNC_TASK_GROUP_F_SEALED    (1u << 12)

#define ASYNC_TASK_GROUP_IS_COMPLETED(group) \
	(((group)->event.flags & ASYNC_TASK_GROUP_F_COMPLETED) != 0)
#define ASYNC_TASK_GROUP_SET_COMPLETED(group) \
	((group)->event.flags |= ASYNC_TASK_GROUP_F_COMPLETED)

#define ASYNC_TASK_GROUP_IS_SEALED(group) \
	(((group)->event.flags & ASYNC_TASK_GROUP_F_SEALED) != 0)
#define ASYNC_TASK_GROUP_SET_SEALED(group) \
	((group)->event.flags |= ASYNC_TASK_GROUP_F_SEALED)

typedef struct _async_task_group_s async_task_group_t;
typedef struct _task_group_waiter_event_s task_group_waiter_event_t;

struct _async_task_group_s {
	/* Event (must be first) — TaskGroup IS an event (Awaitable).
	 * group->event == all() semantics.
	 * Flags used:
	 *   ASYNC_TASK_GROUP_F_SEALED          — sealed for new tasks (spawn rejected)
	 *   ZEND_ASYNC_EVENT_F_EXCEPTION_HANDLED — errors handled (toggle)
	 *   ASYNC_TASK_GROUP_F_COMPLETED       — terminal: all tasks done, event CLOSED */
	zend_async_event_t event;

	/* Child scope (always owned, dispose is safe) */
	async_scope_t *scope;

	/* Concurrency settings */
	uint32_t concurrency;      /* 0 = unlimited */
	int32_t active_coroutines; /* currently running coroutines */

	/* Unified tasks array — preserves spawn() order.
	 * Values:
	 *   IS_PTR → task_entry_t* (pending/running/error)
	 *   anything else → successful result
	 * Internal pointer used for drain (next pending to spawn).
	 * Keys: string|int as provided to spawn(). */
	HashTable tasks;

	/* Race/any/all/iterator waiter events — pointer vector with safe iteration */
	task_group_waiter_event_t **waiter_events;
	uint32_t waiter_events_length;
	uint32_t waiter_events_capacity;
	uint32_t waiter_notify_index;

	/* Finally handlers (lazy-init) */
	HashTable *finally_handlers;

	/* Auto-increment key counter */
	uint32_t next_key;

	/* PHP object (must be last — XtOffsetOf) */
	zend_object std;
};

/* Iterator for foreach support */
typedef struct {
	zend_object_iterator it;
	async_task_group_t *group;
	zval current;       /* [result, error] array */
	zval current_key;   /* string|int key */
	uint32_t position;  /* current HashTable index for ordered iteration */
	bool valid;
	bool started;
} task_group_iterator_t;

/* Class entry */
extern zend_class_entry *async_ce_task_group;

/* Convert zend_object → async_task_group_t */
#define ASYNC_TASK_GROUP_FROM_OBJ(obj) \
	((async_task_group_t *)((char *)(obj) - XtOffsetOf(async_task_group_t, std)))

/* Convert zend_async_event_t → async_task_group_t (event is first field) */
#define ASYNC_TASK_GROUP_FROM_EVENT(ev) \
	((async_task_group_t *)(ev))

/* API */
zend_async_group_t *async_new_group(uint32_t concurrency, zend_object *scope);
void async_register_task_group_ce(void);

#endif /* ASYNC_TASK_GROUP_H */
