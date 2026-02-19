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

#include "zend_exceptions.h"
#ifdef HAVE_CONFIG_H
#include <config.h>
#endif

#include "php_async.h"
#include "fs_watcher.h"
#include "fs_watcher_arginfo.h"
#include "exceptions.h"
#include "scheduler.h"
#include "zend_interfaces.h"

zend_class_entry *async_ce_fs_watcher = NULL;
zend_class_entry *async_ce_filesystem_event = NULL;
static zend_object_handlers fs_watcher_handlers;

#define FS_WATCHER_METHOD(name) ZEND_METHOD(Async_FileSystemWatcher, name)
#define THIS_WATCHER ASYNC_FS_WATCHER_FROM_OBJ(Z_OBJ_P(ZEND_THIS))

///////////////////////////////////////////////////////////////
/// Callback structure for fs_event notifications
///////////////////////////////////////////////////////////////

typedef struct {
	zend_async_event_callback_t base;
	async_fs_watcher_t *watcher;
} fs_watcher_fs_callback_t;

///////////////////////////////////////////////////////////////
/// Helpers
///////////////////////////////////////////////////////////////

static zend_string *fs_watcher_build_key(
	const zend_string *path, const zend_string *filename)
{
	if (filename != NULL) {
		return zend_strpprintf(0, "%s/%s", ZSTR_VAL(path), ZSTR_VAL(filename));
	}
	return zend_string_copy((zend_string *)path);
}

static void fs_watcher_create_event_zval(
	zval *result, const zend_async_filesystem_event_t *fs)
{
	object_init_ex(result, async_ce_filesystem_event);
	zend_object *obj = Z_OBJ_P(result);

	ZVAL_STR_COPY(OBJ_PROP_NUM(obj, 0), fs->path);

	if (fs->triggered_filename != NULL) {
		ZVAL_STR_COPY(OBJ_PROP_NUM(obj, 1), fs->triggered_filename);
	} else {
		ZVAL_NULL(OBJ_PROP_NUM(obj, 1));
	}

	ZVAL_BOOL(OBJ_PROP_NUM(obj, 2), (fs->triggered_events & ZEND_ASYNC_FS_EVENT_RENAME) != 0);
	ZVAL_BOOL(OBJ_PROP_NUM(obj, 3), (fs->triggered_events & ZEND_ASYNC_FS_EVENT_CHANGE) != 0);
}

static bool fs_watcher_pop_event(async_fs_watcher_t *watcher, zval *result)
{
	if (ASYNC_FS_WATCHER_IS_COALESCE(watcher)) {
		HashTable *ht = &watcher->coalesce_ht;
		HashPosition pos;

		zend_hash_internal_pointer_reset_ex(ht, &pos);
		const zval *entry = zend_hash_get_current_data_ex(ht, &pos);

		if (entry == NULL) {
			return false;
		}

		ZVAL_COPY(result, entry);

		zend_string *key;
		zend_ulong idx;

		if (zend_hash_get_current_key_ex(ht, &key, &idx, &pos) == HASH_KEY_IS_STRING) {
			zend_hash_del(ht, key);
		} else {
			zend_hash_index_del(ht, idx);
		}

		return true;
	}

	if (EXPECTED(circular_buffer_is_not_empty(&watcher->raw_buffer))) {
		return zval_circular_buffer_pop(&watcher->raw_buffer, result) == SUCCESS;
	}
	return false;
}

///////////////////////////////////////////////////////////////
/// FS event callback (reactor → watcher buffer)
///////////////////////////////////////////////////////////////

static void fs_watcher_callback_dispose(
	zend_async_event_callback_t *callback, zend_async_event_t *event)
{
	efree(callback);
}

static void fs_watcher_on_fs_event(
	zend_async_event_t *event, zend_async_event_callback_t *callback,
	void *result, zend_object *exception)
{
	const fs_watcher_fs_callback_t *cb = (fs_watcher_fs_callback_t *)callback;
	async_fs_watcher_t *watcher = cb->watcher;

	if (UNEXPECTED(ASYNC_FS_WATCHER_IS_CLOSED(watcher))) {
		return;
	}

	if (UNEXPECTED(exception != NULL)) {
		ZEND_ASYNC_EVENT_SET_CLOSED(watcher->event);
		return;
	}

	const zend_async_filesystem_event_t *fs = ASYNC_FS_WATCHER_FS_EVENT(watcher);

	if (ASYNC_FS_WATCHER_IS_COALESCE(watcher)) {
		zend_string *key = fs_watcher_build_key(fs->path, fs->triggered_filename);
		const zval *existing = zend_hash_find(&watcher->coalesce_ht, key);

		if (existing != NULL) {
			zend_object *obj = Z_OBJ_P(existing);

			if ((fs->triggered_events & ZEND_ASYNC_FS_EVENT_RENAME) != 0) {
				ZVAL_TRUE(OBJ_PROP_NUM(obj, 2));
			}
			if ((fs->triggered_events & ZEND_ASYNC_FS_EVENT_CHANGE) != 0) {
				ZVAL_TRUE(OBJ_PROP_NUM(obj, 3));
			}

			zend_string_release(key);
		} else {
			zval event_obj;
			fs_watcher_create_event_zval(&event_obj, fs);
			zend_hash_add_new(&watcher->coalesce_ht, key, &event_obj);
			zend_string_release(key);
		}
	} else {
		zval event_obj;
		fs_watcher_create_event_zval(&event_obj, fs);
		zval_circular_buffer_push(&watcher->raw_buffer, &event_obj, true);
		zval_ptr_dtor(&event_obj);
	}
}

///////////////////////////////////////////////////////////////
/// Close logic (shared by close() method and dtor)
///////////////////////////////////////////////////////////////

static void fs_watcher_do_close(async_fs_watcher_t *watcher)
{
	if (ASYNC_FS_WATCHER_IS_CLOSED(watcher)) {
		return;
	}

	// We intentionally remove all references to the event in
	// the event loop in order to close it permanently.
	watcher->event->loop_ref_count = 1;
	watcher->event->stop(watcher->event);
	ZEND_ASYNC_EVENT_SET_CLOSED(watcher->event);
	ZEND_ASYNC_CALLBACKS_NOTIFY(watcher->event, NULL, NULL);
}

///////////////////////////////////////////////////////////////
/// Object lifecycle
///////////////////////////////////////////////////////////////

static zend_object *fs_watcher_create_object(zend_class_entry *ce)
{
	async_fs_watcher_t *watcher = zend_object_alloc(sizeof(async_fs_watcher_t), ce);

	zend_object_std_init(&watcher->std, ce);
	watcher->std.handlers = &fs_watcher_handlers;

	ZEND_ASYNC_EVENT_REF_SET(watcher, XtOffsetOf(async_fs_watcher_t, std), NULL);

	watcher->fs_callback = NULL;
	watcher->watcher_flags = 0;

	return &watcher->std;
}

static void fs_watcher_dtor_object(zend_object *object)
{
	async_fs_watcher_t *watcher = ASYNC_FS_WATCHER_FROM_OBJ(object);
	fs_watcher_do_close(watcher);
	zend_objects_destroy_object(object);
}

static void fs_watcher_free_object(zend_object *object)
{
	async_fs_watcher_t *watcher = ASYNC_FS_WATCHER_FROM_OBJ(object);

	if (ASYNC_FS_WATCHER_IS_COALESCE(watcher)) {
		zend_hash_destroy(&watcher->coalesce_ht);
	} else if (watcher->raw_buffer.data != NULL) {
		zval tmp;
		while (circular_buffer_is_not_empty(&watcher->raw_buffer)
				&& zval_circular_buffer_pop(&watcher->raw_buffer, &tmp) == SUCCESS) {
			zval_ptr_dtor(&tmp);
		}
		circular_buffer_dtor(&watcher->raw_buffer);
	}

	if (watcher->event != NULL) {
		if (watcher->fs_callback != NULL) {
			watcher->event->del_callback(watcher->event, watcher->fs_callback);
			watcher->fs_callback = NULL;
		}

		ZEND_ASYNC_EVENT_RELEASE(watcher->event);
		watcher->event = NULL;
	}

	zend_object_std_dtor(object);
}

static HashTable *fs_watcher_get_gc(zend_object *object, zval **table, int *n)
{
	const async_fs_watcher_t *watcher = ASYNC_FS_WATCHER_FROM_OBJ(object);
	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();

	if (ASYNC_FS_WATCHER_IS_COALESCE(watcher)) {
		zval *zv;
		ZEND_HASH_FOREACH_VAL(&watcher->coalesce_ht, zv) {
			zend_get_gc_buffer_add_zval(buf, zv);
		} ZEND_HASH_FOREACH_END();
	}

	zend_get_gc_buffer_use(buf, table, n);
	return NULL;
}

///////////////////////////////////////////////////////////////
/// PHP methods
///////////////////////////////////////////////////////////////

FS_WATCHER_METHOD(__construct)
{
	zend_string *path = NULL;
	bool recursive = false;
	bool coalesce = true;

	ZEND_PARSE_PARAMETERS_START(1, 3)
		Z_PARAM_STR(path)
		Z_PARAM_OPTIONAL
		Z_PARAM_BOOL(recursive)
		Z_PARAM_BOOL(coalesce)
	ZEND_PARSE_PARAMETERS_END();

	async_fs_watcher_t *watcher = THIS_WATCHER;

	if (coalesce) {
		watcher->watcher_flags |= ASYNC_FS_WATCHER_F_COALESCE;
		zend_hash_init(&watcher->coalesce_ht, 8, NULL, ZVAL_PTR_DTOR, 0);
	} else {
		if (UNEXPECTED(circular_buffer_ctor(
				&watcher->raw_buffer, 8, sizeof(zval),
				&zend_std_persistent_allocator) == FAILURE)) {
			async_throw_error("Failed to allocate event buffer");
			RETURN_THROWS();
		}
		watcher->raw_buffer.auto_optimize = true;
	}

	const unsigned int flags = recursive ? ZEND_ASYNC_FS_EVENT_RECURSIVE : 0;
	zend_async_filesystem_event_t *fs_event = ZEND_ASYNC_NEW_FILESYSTEM_EVENT(path, flags);

	if (UNEXPECTED(fs_event == NULL)) {
		RETURN_THROWS();
	}

	watcher->event = &fs_event->base;

	fs_watcher_fs_callback_t *cb = ecalloc(1, sizeof(fs_watcher_fs_callback_t));
	cb->base.ref_count = 0;
	cb->base.callback = fs_watcher_on_fs_event;
	cb->base.dispose = fs_watcher_callback_dispose;
	cb->watcher = watcher;

	watcher->fs_callback = &cb->base;
	fs_event->base.add_callback(&fs_event->base, &cb->base);

	if (UNEXPECTED(!fs_event->base.start(&fs_event->base))) {
		fs_event->base.del_callback(&fs_event->base, &cb->base);
		watcher->fs_callback = NULL;
		ZEND_ASYNC_EVENT_RELEASE(&fs_event->base);
		watcher->event = NULL;
		RETURN_THROWS();
	}
}

FS_WATCHER_METHOD(close)
{
	ZEND_PARSE_PARAMETERS_NONE();

	async_fs_watcher_t *watcher = THIS_WATCHER;
	fs_watcher_do_close(watcher);
}

FS_WATCHER_METHOD(isClosed)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_fs_watcher_t *watcher = THIS_WATCHER;
	RETURN_BOOL(ASYNC_FS_WATCHER_IS_CLOSED(watcher));
}

///////////////////////////////////////////////////////////////
/// Iterator
///////////////////////////////////////////////////////////////

static void fs_watcher_iterator_dtor(zend_object_iterator *iter)
{
	fs_watcher_iterator_t *iterator = (fs_watcher_iterator_t *)iter;
	zval_ptr_dtor(&iterator->current);
	zval_ptr_dtor(&iterator->it.data);
}

static zend_result fs_watcher_iterator_valid(zend_object_iterator *iter)
{
	const fs_watcher_iterator_t *iterator = (fs_watcher_iterator_t *)iter;
	return iterator->valid ? SUCCESS : FAILURE;
}

static zval *fs_watcher_iterator_get_current_data(zend_object_iterator *iter)
{
	return &((fs_watcher_iterator_t *)iter)->current;
}

static void fs_watcher_iterator_move_forward(zend_object_iterator *iter)
{
	fs_watcher_iterator_t *iterator = (fs_watcher_iterator_t *)iter;
	async_fs_watcher_t *watcher = iterator->watcher;

retry:
	zval_ptr_dtor(&iterator->current);
	ZVAL_UNDEF(&iterator->current);

	if (fs_watcher_pop_event(watcher, &iterator->current)) {
		iterator->valid = true;
		return;
	}

	if (ASYNC_FS_WATCHER_IS_CLOSED(watcher)) {
		iterator->valid = false;
		return;
	}

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		async_throw_error("FileSystemWatcher iterator can only be used inside a coroutine");
		iterator->valid = false;
		return;
	}

	zend_async_waker_new(coroutine);

	if (UNEXPECTED(EG(exception))) {
		iterator->valid = false;
		return;
	}

	zend_async_resume_when(
		coroutine, watcher->event, false,
		zend_async_waker_callback_resolve, NULL);

	if (UNEXPECTED(EG(exception))) {
		zend_async_waker_clean(coroutine);
		iterator->valid = false;
		return;
	}

	ZEND_ASYNC_SUSPEND();
	zend_async_waker_clean(coroutine);

	if (UNEXPECTED(EG(exception))) {
		iterator->valid = false;
		return;
	}

	goto retry;
}

static void fs_watcher_iterator_rewind(zend_object_iterator *iter)
{
	fs_watcher_iterator_t *iterator = (fs_watcher_iterator_t *)iter;
	if (!iterator->started) {
		iterator->started = true;
		fs_watcher_iterator_move_forward(iter);
	}
}

static const zend_object_iterator_funcs fs_watcher_iterator_funcs = {
	.dtor = fs_watcher_iterator_dtor,
	.valid = fs_watcher_iterator_valid,
	.get_current_data = fs_watcher_iterator_get_current_data,
	.get_current_key = NULL,
	.move_forward = fs_watcher_iterator_move_forward,
	.rewind = fs_watcher_iterator_rewind,
};

static zend_object_iterator *fs_watcher_get_iterator(
	zend_class_entry *ce, zval *object, int by_ref)
{
	if (UNEXPECTED(by_ref)) {
		zend_throw_error(NULL, "Cannot iterate FileSystemWatcher by reference");
		return NULL;
	}

	fs_watcher_iterator_t *iterator = ecalloc(1, sizeof(fs_watcher_iterator_t));
	zend_iterator_init(&iterator->it);

	iterator->it.funcs = &fs_watcher_iterator_funcs;
	ZVAL_COPY(&iterator->it.data, object);
	iterator->watcher = ASYNC_FS_WATCHER_FROM_OBJ(Z_OBJ_P(object));
	ZVAL_UNDEF(&iterator->current);
	iterator->valid = true;
	iterator->started = false;

	return &iterator->it;
}

FS_WATCHER_METHOD(getIterator)
{
	ZEND_PARSE_PARAMETERS_NONE();

	zend_create_internal_iterator_zval(return_value, ZEND_THIS);
}

///////////////////////////////////////////////////////////////
/// Class registration
///////////////////////////////////////////////////////////////

void async_register_fs_watcher_ce(void)
{
	async_ce_filesystem_event = register_class_Async_FileSystemEvent();

	async_ce_fs_watcher = register_class_Async_FileSystemWatcher(
		async_ce_awaitable, zend_ce_aggregate);

	async_ce_fs_watcher->create_object = fs_watcher_create_object;
	async_ce_fs_watcher->get_iterator = fs_watcher_get_iterator;

	memcpy(&fs_watcher_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	fs_watcher_handlers.offset = XtOffsetOf(async_fs_watcher_t, std);
	fs_watcher_handlers.get_gc = fs_watcher_get_gc;
	fs_watcher_handlers.dtor_obj = fs_watcher_dtor_object;
	fs_watcher_handlers.free_obj = fs_watcher_free_object;
	fs_watcher_handlers.clone_obj = NULL;
}
