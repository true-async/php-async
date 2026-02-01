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
#ifndef ASYNC_CHANNEL_H
#define ASYNC_CHANNEL_H

#include "php_async_api.h"
#include <Zend/zend_async_API.h>
#include "internal/circular_buffer.h"

/* Channel event types passed as result in notify */
typedef enum {
	ASYNC_CHANNEL_EVENT_DATA_PUSHED = 1,
	ASYNC_CHANNEL_EVENT_DATA_POPPED = 2,
	ASYNC_CHANNEL_EVENT_CLOSED = 3
} async_channel_event_t;

typedef struct _async_channel_s async_channel_t;

struct _async_channel_s {
	/* ABI structure (must be first) */
	zend_async_channel_t channel;

	/* Channel capacity: 0 = rendezvous, >0 = buffered */
	int32_t capacity;

	/* For buffered channels (capacity > 0) */
	circular_buffer_t buffer;

	/* For rendezvous channels (capacity = 0): single value storage */
	zval rendezvous_value;
	bool rendezvous_has_value;

	/* Waiting queues (like Go's recvq/sendq) */
	zend_async_callbacks_vector_t waiting_receivers;  /* coroutines waiting for data */
	zend_async_callbacks_vector_t waiting_senders;    /* coroutines waiting for space */

	/* PHP object handle (must be last for final class) */
	zend_object std;
};

/* Class entries */
extern zend_class_entry *async_ce_channel;
extern zend_class_entry *async_ce_channel_exception;

/* Convert zend_object to async_channel_t */
#define ASYNC_CHANNEL_FROM_OBJ(obj) \
	((async_channel_t *)((char *)(obj) - XtOffsetOf(async_channel_t, std)))

/* Convert zend_async_channel_t to async_channel_t */
#define ASYNC_CHANNEL_FROM_ZEND(zend_channel) \
	((async_channel_t *)(zend_channel))

/* Registration function */
void async_register_channel_ce(void);

#endif /* ASYNC_CHANNEL_H */
