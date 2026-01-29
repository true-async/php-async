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

/* Mapper types for Future transformations */
typedef enum {
    ASYNC_FUTURE_MAPPER_SUCCESS = 0,   /* map() - transforms successful result */
    ASYNC_FUTURE_MAPPER_CATCH = 1,     /* catch() - handles errors */
    ASYNC_FUTURE_MAPPER_FINALLY = 2    /* finally() - always executes */
} async_future_mapper_type_t;

/**
 * FutureState object structure.
 * Holds a reference to the underlying zend_future_t event.
 * Allows modification through complete() and error() methods.
 */
struct _async_future_state_s {
    ZEND_ASYNC_EVENT_REF_FIELDS        /* Reference to zend_future_t */
    zend_object std;                   /* Standard object */
};

/**
 * Future object structure.
 * Holds a reference to the same zend_future_t event as FutureState.
 * Provides readonly access (await, isComplete, ignore, map, catch, finally).
 * Both structures have identical beginning (ZEND_ASYNC_EVENT_REF_FIELDS).
 */
struct _async_future_s {
    ZEND_ASYNC_EVENT_REF_FIELDS        /* Reference to zend_future_t (same as FutureState) */
    HashTable *child_futures;          /* Child futures created by map/catch/finally */
    zval mapper;                       /* Mapper callable (used when this future is a child) */
    async_future_mapper_type_t mapper_type; /* Type of mapper transformation */
    zend_object std;                   /* Standard object - MUST BE LAST! */
};

/* Class entry declarations */
extern zend_class_entry *async_ce_future_state;
extern zend_class_entry *async_ce_future;

/* Convert zend_object to async_future_state_t */
#define ASYNC_FUTURE_STATE_FROM_OBJ(obj) ((async_future_state_t *)((char *)(obj) - (obj)->handlers->offset))

/* Convert zend_object to async_future_t */
#define ASYNC_FUTURE_FROM_OBJ(obj) ((async_future_t *)((char *)(obj) - (obj)->handlers->offset))


/* Registration function */
void async_register_future_ce(void);

/* API function implementations */
zend_future_t *async_future_create(void);
/* Internal helper functions */
async_future_state_t *async_future_state_create(void);

#endif /* FUTURE_H */