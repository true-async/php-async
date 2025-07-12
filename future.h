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

/**
 * FutureState object structure.
 * Inherits from zend_async_event_t to participate in the event system.
 */
struct _async_future_state_s {
    ZEND_ASYNC_EVENT_REF_FIELDS
    zend_object std;
};

/**
 * Future object structure.
 * Holds a reference to FutureState.
 */
struct _async_future_s {
    ZEND_ASYNC_EVENT_REF_FIELDS
    zend_object std;                /* Standard object */
};

/* Class entry declarations */
extern zend_class_entry *async_ce_future_state;
extern zend_class_entry *async_ce_future;

#define ASYNC_FUTURE_STATE_FROM_EVENT(ev) ((async_future_state_t *)(ev)->extra_offset)
#define ASYNC_FUTURE_STATE_FROM_OBJ(obj) ((async_future_state_t *)((char *)(obj) - (obj)->handlers->offset))

#define ASYNC_FUTURE_FROM_EVENT(ev) ((async_future_t *)(ev)->extra_offset)
#define ASYNC_FUTURE_FROM_OBJ(obj) ((async_future_t *)((char *)(obj) - (obj)->handlers->offset))

/* Registration function */
void async_register_future_ce(void);

/* API function implementations */
zend_future_t *async_future_create(void);
void async_future_complete(zend_future_t *future, zval *value);
void async_future_error(zend_future_t *future, zend_object *exception);

/* Internal helper functions */
async_future_state_t *async_future_state_create(void);
async_future_t *async_future_wrap_state(async_future_state_t *state);

#endif /* FUTURE_H */