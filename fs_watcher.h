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
#ifndef ASYNC_FS_WATCHER_H
#define ASYNC_FS_WATCHER_H

#include "php_async_api.h"
#include <Zend/zend_async_API.h>
#include "internal/circular_buffer.h"

/* Watcher-specific flags (stored in watcher_flags) */
#define ASYNC_FS_WATCHER_F_COALESCE  (1u << 0)

#define ASYNC_FS_WATCHER_IS_CLOSED(w) \
	((w)->event == NULL || ZEND_ASYNC_EVENT_IS_CLOSED((w)->event))

#define ASYNC_FS_WATCHER_IS_COALESCE(w) \
	(((w)->watcher_flags & ASYNC_FS_WATCHER_F_COALESCE) != 0)

/* Cast event* back to zend_async_filesystem_event_t* */
#define ASYNC_FS_WATCHER_FS_EVENT(w) \
	((zend_async_filesystem_event_t *)((char *)(w)->event \
		- XtOffsetOf(zend_async_filesystem_event_t, base)))

typedef struct _async_fs_watcher_s async_fs_watcher_t;

struct _async_fs_watcher_s {
	/* REF pattern: reference to reactor's filesystem event (Awaitable support) */
	ZEND_ASYNC_EVENT_REF_FIELDS

	/* Our callback registered on the filesystem event */
	zend_async_event_callback_t *fs_callback;

	/* Storage: coalesce mode uses HashTable, raw mode uses circular_buffer.
	 * Both store zval(FileSystemEvent objects). */
	union {
		HashTable coalesce_ht;
		circular_buffer_t raw_buffer;
	};

	/* Watcher state flags */
	uint32_t watcher_flags;

	/* PHP object (must be last — XtOffsetOf) */
	zend_object std;
};

/* Iterator for foreach support */
typedef struct {
	zend_object_iterator it;
	async_fs_watcher_t *watcher;
	zval current;
	bool valid;
	bool started;
} fs_watcher_iterator_t;

/* Class entries */
extern zend_class_entry *async_ce_fs_watcher;
extern zend_class_entry *async_ce_filesystem_event;

/* Convert zend_object → async_fs_watcher_t */
#define ASYNC_FS_WATCHER_FROM_OBJ(obj) \
	((async_fs_watcher_t *)((char *)(obj) - XtOffsetOf(async_fs_watcher_t, std)))

/* API */
void async_register_fs_watcher_ce(void);

#endif /* ASYNC_FS_WATCHER_H */
