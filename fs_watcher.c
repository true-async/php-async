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

typedef struct
{
	zend_async_event_callback_t base;
	async_fs_watcher_t *watcher;
} fs_watcher_fs_callback_t;

///////////////////////////////////////////////////////////////
/// Helpers
///////////////////////////////////////////////////////////////

static zend_string *fs_watcher_build_key(const zend_string *path, const zend_string *filename)
{
	if (filename != NULL) {
		return zend_strpprintf(0, "%s/%s", ZSTR_VAL(path), ZSTR_VAL(filename));
	}
	return zend_string_copy((zend_string *) path);
}

static void fs_watcher_create_event_zval(zval *result, const zend_async_filesystem_event_t *fs)
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

static void fs_watcher_callback_dispose(zend_async_event_callback_t *callback, zend_async_event_t *event)
{
	efree(callback);
}

///////////////////////////////////////////////////////////////
/// Debounce (collapse a burst into one delayed wakeup)
///////////////////////////////////////////////////////////////

#define ASYNC_FS_WATCHER_HAS_DEBOUNCE(w) ((w)->debounce_ms != 0)

/* Case-insensitive extension filter over the basename of `filename`. No filter
 * (extensions == NULL) matches everything. */
static bool fs_watcher_ext_matches(const async_fs_watcher_t *watcher, const zend_string *filename)
{
	if (watcher->extensions == NULL) {
		return true;
	}

	if (filename == NULL) {
		return false;
	}

	const char *name  = ZSTR_VAL(filename);
	const char *slash = strrchr(name, '/');
	const char *base  = slash != NULL ? slash + 1 : name;
	const char *dot   = strrchr(base, '.');

	if (dot == NULL || dot[1] == '\0') {
		return false;
	}

	const size_t elen = strlen(dot + 1);

	if (elen >= 16) {
		return false;
	}

	char lower[16];
	for (size_t i = 0; i < elen; i++) {
		const char c = dot[1 + i];
		lower[i] = (c >= 'A' && c <= 'Z') ? (char) (c + 32) : c;
	}

	return zend_hash_str_exists(watcher->extensions, lower, elen);
}

/* Deliver the collapsed burst: push one filename=null event and wake the
 * iterator through deliver_event. Called from the debounce timer callback. */
static void fs_watcher_debounce_flush(async_fs_watcher_t *watcher)
{
	if (false == watcher->dirty) {
		return;
	}

	const zend_async_filesystem_event_t *fs = ASYNC_FS_WATCHER_FS_EVENT(watcher);

	zval event_obj;
	object_init_ex(&event_obj, async_ce_filesystem_event);
	zend_object *obj = Z_OBJ(event_obj);
	ZVAL_STR_COPY(OBJ_PROP_NUM(obj, 0), fs->path);
	ZVAL_NULL(OBJ_PROP_NUM(obj, 1));
	ZVAL_BOOL(OBJ_PROP_NUM(obj, 2), (watcher->pending_events & ZEND_ASYNC_FS_EVENT_RENAME) != 0);
	ZVAL_BOOL(OBJ_PROP_NUM(obj, 3), (watcher->pending_events & ZEND_ASYNC_FS_EVENT_CHANGE) != 0);

	if (ASYNC_FS_WATCHER_IS_COALESCE(watcher)) {
		zend_string *key = zend_string_copy(fs->path);

		if (zend_hash_find(&watcher->coalesce_ht, key) == NULL) {
			zend_hash_add_new(&watcher->coalesce_ht, key, &event_obj);
		} else {
			zval_ptr_dtor(&event_obj);
		}

		zend_string_release(key);
	} else {
		zval_circular_buffer_push(&watcher->raw_buffer, &event_obj, true);
		zval_ptr_dtor(&event_obj);
	}

	watcher->dirty          = false;
	watcher->first_change   = 0;
	watcher->pending_events = 0;

	if (watcher->deliver_event != NULL) {
		zend_async_trigger_event_t *deliver = (zend_async_trigger_event_t *) watcher->deliver_event;
		deliver->trigger(deliver);
	}
}

static void fs_watcher_on_debounce_timer(zend_async_event_t *event,
									   zend_async_event_callback_t *callback,
									   void *result,
									   zend_object *exception)
{
	const fs_watcher_fs_callback_t *cb = (fs_watcher_fs_callback_t *) callback;
	async_fs_watcher_t *watcher = cb->watcher;

	if (UNEXPECTED(ASYNC_FS_WATCHER_IS_CLOSED(watcher))) {
		return;
	}

	fs_watcher_debounce_flush(watcher);
}

/* Mark a matching change pending and (re)arm the quiet timer: it fires after
 * debounce_ms of silence, but no later than max_hold_ms after the first change. */
static void fs_watcher_debounce_arm(async_fs_watcher_t *watcher)
{
	const zend_hrtime_t now = zend_hrtime();
	const zend_async_filesystem_event_t *fs = ASYNC_FS_WATCHER_FS_EVENT(watcher);

	if (false == watcher->dirty) {
		watcher->first_change   = now;
		watcher->pending_events = 0;
	}

	watcher->dirty = true;
	watcher->pending_events |= fs->triggered_events;

	zend_ulong delay = watcher->debounce_ms;

	if (watcher->max_hold_ms > 0) {
		const zend_hrtime_t deadline = watcher->first_change + (zend_hrtime_t) watcher->max_hold_ms * 1000000ULL;
		const zend_ulong remaining = deadline > now ? (zend_ulong) ((deadline - now) / 1000000ULL) : 0;

		if (remaining < delay) {
			delay = remaining;
		}
	}

	if (watcher->debounce_timer != NULL) {
		zend_async_timer_rearm_fn(watcher->debounce_timer, delay, 0);
		return;
	}

	zend_async_timer_event_t *timer = ZEND_ASYNC_NEW_TIMER_EVENT(delay, false);

	if (UNEXPECTED(timer == NULL)) {
		return;
	}

	ZEND_ASYNC_TIMER_SET_MULTISHOT(timer);
	ZEND_ASYNC_EVENT_SET_HIDDEN(&timer->base);

	fs_watcher_fs_callback_t *tc = ecalloc(1, sizeof(*tc));
	tc->base.ref_count = 0;
	tc->base.callback = fs_watcher_on_debounce_timer;
	tc->base.dispose = fs_watcher_callback_dispose;
	tc->watcher = watcher;

	watcher->timer_callback = &tc->base;
	timer->base.add_callback(&timer->base, &tc->base);

	watcher->debounce_timer = timer;
	timer->base.start(&timer->base);
}

static void fs_watcher_on_fs_event(zend_async_event_t *event,
								   zend_async_event_callback_t *callback,
								   void *result,
								   zend_object *exception)
{
	const fs_watcher_fs_callback_t *cb = (fs_watcher_fs_callback_t *) callback;
	async_fs_watcher_t *watcher = cb->watcher;

	if (UNEXPECTED(ASYNC_FS_WATCHER_IS_CLOSED(watcher))) {
		return;
	}

	if (UNEXPECTED(exception != NULL)) {
		ZEND_ASYNC_EVENT_SET_CLOSED(watcher->event);
		return;
	}

	const zend_async_filesystem_event_t *fs = ASYNC_FS_WATCHER_FS_EVENT(watcher);

	/* Debounce mode: filter by extension, mark dirty and (re)arm the quiet timer
	 * — the timer callback delivers one collapsed event once the burst settles. */
	if (ASYNC_FS_WATCHER_HAS_DEBOUNCE(watcher)) {
		if (fs_watcher_ext_matches(watcher, fs->triggered_filename)) {
			fs_watcher_debounce_arm(watcher);
		}

		return;
	}

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

	/* Debounce mode: stop a pending quiet timer and wake an iterator parked on
	 * deliver_event so it observes the close and ends iteration. */
	if (watcher->debounce_timer != NULL && watcher->debounce_timer->base.loop_ref_count > 0) {
		watcher->debounce_timer->base.stop(&watcher->debounce_timer->base);
	}

	if (watcher->deliver_event != NULL) {
		zend_async_trigger_event_t *deliver = (zend_async_trigger_event_t *) watcher->deliver_event;
		deliver->trigger(deliver);
	}
}

///////////////////////////////////////////////////////////////
/// Object lifecycle
///////////////////////////////////////////////////////////////

static zend_object *fs_watcher_create_object(zend_class_entry *ce)
{
	async_fs_watcher_t *watcher = zend_object_alloc(sizeof(async_fs_watcher_t), ce);

	zend_object_std_init(&watcher->std, ce);
	watcher->std.handlers = &fs_watcher_handlers;

	ZEND_ASYNC_EVENT_REF_SET(watcher, offsetof(async_fs_watcher_t, std), NULL);

	watcher->fs_callback = NULL;
	watcher->watcher_flags = 0;

	watcher->debounce_ms = 0;
	watcher->max_hold_ms = 0;
	watcher->extensions = NULL;
	watcher->debounce_timer = NULL;
	watcher->deliver_event = NULL;
	watcher->timer_callback = NULL;
	watcher->first_change = 0;
	watcher->pending_events = 0;
	watcher->dirty = false;

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
		while (circular_buffer_is_not_empty(&watcher->raw_buffer) &&
			   zval_circular_buffer_pop(&watcher->raw_buffer, &tmp) == SUCCESS) {
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

	if (watcher->debounce_timer != NULL) {
		if (watcher->timer_callback != NULL) {
			watcher->debounce_timer->base.del_callback(&watcher->debounce_timer->base, watcher->timer_callback);
			watcher->timer_callback = NULL;
		}

		watcher->debounce_timer->base.dispose(&watcher->debounce_timer->base);
		watcher->debounce_timer = NULL;
	}

	if (watcher->deliver_event != NULL) {
		watcher->deliver_event->dispose(watcher->deliver_event);
		watcher->deliver_event = NULL;
	}

	if (watcher->extensions != NULL) {
		zend_hash_destroy(watcher->extensions);
		FREE_HASHTABLE(watcher->extensions);
		watcher->extensions = NULL;
	}

	zend_object_std_dtor(object);
}

static HashTable *fs_watcher_get_gc(zend_object *object, zval **table, int *n)
{
	const async_fs_watcher_t *watcher = ASYNC_FS_WATCHER_FROM_OBJ(object);
	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();

	if (ASYNC_FS_WATCHER_IS_COALESCE(watcher)) {
		zval *zv;
		ZEND_HASH_FOREACH_VAL(&watcher->coalesce_ht, zv)
		{
			zend_get_gc_buffer_add_zval(buf, zv);
		}
		ZEND_HASH_FOREACH_END();
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
	zend_long debounce_ms = 0;
	zend_long max_hold_ms = 0;
	HashTable *extensions_arg = NULL;

	ZEND_PARSE_PARAMETERS_START(1, 6)
	Z_PARAM_STR(path)
	Z_PARAM_OPTIONAL
	Z_PARAM_BOOL(recursive)
	Z_PARAM_BOOL(coalesce)
	Z_PARAM_LONG(debounce_ms)
	Z_PARAM_LONG(max_hold_ms)
	Z_PARAM_ARRAY_HT_OR_NULL(extensions_arg)
	ZEND_PARSE_PARAMETERS_END();

	async_fs_watcher_t *watcher = THIS_WATCHER;

	watcher->debounce_ms = debounce_ms > 0 ? (uint32_t) debounce_ms : 0;
	watcher->max_hold_ms = max_hold_ms > 0 ? (uint32_t) max_hold_ms : 0;

	/* Build the lowercase extension set once, so the per-event filter is a plain
	 * hash lookup. A leading '.' is accepted (".php" == "php"). */
	if (extensions_arg != NULL && zend_hash_num_elements(extensions_arg) > 0) {
		ALLOC_HASHTABLE(watcher->extensions);
		zend_hash_init(watcher->extensions, zend_hash_num_elements(extensions_arg), NULL, NULL, 0);

		zval *ext_zv;
		ZEND_HASH_FOREACH_VAL(extensions_arg, ext_zv)
		{
			if (Z_TYPE_P(ext_zv) != IS_STRING) {
				continue;
			}

			const char *e = Z_STRVAL_P(ext_zv);
			size_t el = Z_STRLEN_P(ext_zv);

			if (el > 0 && e[0] == '.') {
				e++;
				el--;
			}

			if (el == 0 || el >= 16) {
				continue;
			}

			char lower[16];
			for (size_t i = 0; i < el; i++) {
				lower[i] = (e[i] >= 'A' && e[i] <= 'Z') ? (char) (e[i] + 32) : e[i];
			}

			zend_hash_str_add_empty_element(watcher->extensions, lower, el);
		}
		ZEND_HASH_FOREACH_END();

		if (zend_hash_num_elements(watcher->extensions) == 0) {
			zend_hash_destroy(watcher->extensions);
			FREE_HASHTABLE(watcher->extensions);
			watcher->extensions = NULL;
		}
	}

	/* Debounce delivers through a trigger event the iterator waits on, so the
	 * reactor's per-event notify on the fs event no longer wakes it. */
	if (watcher->debounce_ms > 0) {
		zend_async_trigger_event_t *deliver = ZEND_ASYNC_NEW_TRIGGER_EVENT();

		if (UNEXPECTED(deliver == NULL)) {
			RETURN_THROWS();
		}

		watcher->deliver_event = &deliver->base;
	}

	if (coalesce) {
		watcher->watcher_flags |= ASYNC_FS_WATCHER_F_COALESCE;
		zend_hash_init(&watcher->coalesce_ht, 8, NULL, ZVAL_PTR_DTOR, 0);
	} else {
		if (UNEXPECTED(circular_buffer_ctor(&watcher->raw_buffer, 8, sizeof(zval), &zend_std_persistent_allocator) ==
					   FAILURE)) {
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
	fs_watcher_iterator_t *iterator = (fs_watcher_iterator_t *) iter;
	zval_ptr_dtor(&iterator->current);
	zval_ptr_dtor(&iterator->it.data);
}

static zend_result fs_watcher_iterator_valid(zend_object_iterator *iter)
{
	const fs_watcher_iterator_t *iterator = (fs_watcher_iterator_t *) iter;
	return iterator->valid ? SUCCESS : FAILURE;
}

static zval *fs_watcher_iterator_get_current_data(zend_object_iterator *iter)
{
	return &((fs_watcher_iterator_t *) iter)->current;
}

static void fs_watcher_iterator_move_forward(zend_object_iterator *iter)
{
	fs_watcher_iterator_t *iterator = (fs_watcher_iterator_t *) iter;
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

	ZEND_ASYNC_WAKER_NEW(coroutine);

	if (UNEXPECTED(EG(exception))) {
		iterator->valid = false;
		return;
	}

	/* In debounce mode the iterator parks on deliver_event (fired by the quiet
	 * timer), so a burst of raw fs events does not wake it — only the settled
	 * collapsed event does. */
	zend_async_event_t *wait_on = (ASYNC_FS_WATCHER_HAS_DEBOUNCE(watcher) && watcher->deliver_event != NULL)
			? watcher->deliver_event
			: watcher->event;

	zend_async_resume_when(coroutine, wait_on, false, zend_async_waker_callback_resolve, NULL);

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
	fs_watcher_iterator_t *iterator = (fs_watcher_iterator_t *) iter;
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

static zend_object_iterator *fs_watcher_get_iterator(zend_class_entry *ce, zval *object, int by_ref)
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

	async_ce_fs_watcher = register_class_Async_FileSystemWatcher(async_ce_awaitable, zend_ce_aggregate);

	async_ce_fs_watcher->create_object = fs_watcher_create_object;
	async_ce_fs_watcher->get_iterator = fs_watcher_get_iterator;

	memcpy(&fs_watcher_handlers, &std_object_handlers, sizeof(zend_object_handlers));
	fs_watcher_handlers.offset = offsetof(async_fs_watcher_t, std);
	fs_watcher_handlers.get_gc = fs_watcher_get_gc;
	fs_watcher_handlers.dtor_obj = fs_watcher_dtor_object;
	fs_watcher_handlers.free_obj = fs_watcher_free_object;
	fs_watcher_handlers.clone_obj = NULL;
}
