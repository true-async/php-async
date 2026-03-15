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

#include "thread.h"
#include "thread_arginfo.h"
#include "coroutine.h"
#include "exceptions.h"
#include "php_async.h"
#include "zend.h"
#include "zend_API.h"
#include "zend_compile.h"
#include "zend_autoload.h"
#include "zend_hash.h"
#include "zend_exceptions.h"

///////////////////////////////////////////////////////////
/// 1. Snapshot — transfer compiled code between threads
///////////////////////////////////////////////////////////

/**
 * Create a snapshot of the current thread's user-defined functions and classes.
 *
 * Strategy:
 * - Iterate CG(function_table) / CG(class_table), skip internal entries.
 * - If entry has ZEND_ACC_IMMUTABLE (OPcache SHM) → store pointer (shallow copy).
 * - Otherwise → TODO: deep copy (see task_deep_copy_opcodes.md).
 * - Clone autoloader registrations via spl_autoload_functions().
 */
async_thread_snapshot_t *async_thread_snapshot_create(bool inherit)
{
	async_thread_snapshot_t *snapshot = pecalloc(1, sizeof(async_thread_snapshot_t), 1);

	/* Capture autoloaders by calling spl_autoload_functions() */
	{
		zval tmp_retval;
		ZVAL_UNDEF(&tmp_retval);

		zend_function *func = zend_hash_str_find_ptr(
			CG(function_table), "spl_autoload_functions", sizeof("spl_autoload_functions") - 1);
		if (func) {
			zend_fcall_info fci;
			zend_fcall_info_cache fcc;
			zval func_name;
			ZVAL_STRING(&func_name, "spl_autoload_functions");
			if (zend_fcall_info_init(&func_name, 0, &fci, &fcc, NULL, NULL) == SUCCESS) {
				fci.retval = &tmp_retval;
				fci.param_count = 0;
				fci.params = NULL;
				zend_call_function(&fci, &fcc);
			}
			zval_ptr_dtor(&func_name);
		}

		if (Z_TYPE(tmp_retval) == IS_ARRAY) {
			ZVAL_COPY(&snapshot->autoload_functions, &tmp_retval);
		} else {
			ZVAL_EMPTY_ARRAY(&snapshot->autoload_functions);
		}
		zval_ptr_dtor(&tmp_retval);
	}

	/* Capture included files list */
	zend_hash_init(&snapshot->included_files, 8, NULL, NULL, 1);

	zend_string *filename;
	ZEND_HASH_MAP_FOREACH_STR_KEY(&EG(included_files), filename) {
		if (filename) {
			zend_string *dup = zend_string_dup(filename, 1);
			zval empty_zv;
			ZVAL_NULL(&empty_zv);
			zend_hash_add(&snapshot->included_files, dup, &empty_zv);
			zend_string_release(dup);
		}
	} ZEND_HASH_FOREACH_END();

	if (!inherit) {
		zend_hash_init(&snapshot->function_table, 0, NULL, NULL, 1);
		zend_hash_init(&snapshot->class_table, 0, NULL, NULL, 1);
		return snapshot;
	}

	snapshot->copied_functions_count = CG(copied_functions_count);

	/* Snapshot user functions */
	const uint32_t func_count = zend_hash_num_elements(CG(function_table));
	const uint32_t user_func_count = func_count > snapshot->copied_functions_count
		? func_count - snapshot->copied_functions_count : 0;

	zend_hash_init(&snapshot->function_table, user_func_count, NULL, NULL, 1);

	if (user_func_count > 0) {
		zend_string *key;
		zend_function *func;
		uint32_t idx = 0;

		ZEND_HASH_MAP_FOREACH_STR_KEY_PTR(CG(function_table), key, func) {
			idx++;
			if (idx <= snapshot->copied_functions_count) {
				continue;
			}
			if (UNEXPECTED(key == NULL)) {
				continue;
			}
			if (func->type != ZEND_USER_FUNCTION) {
				continue;
			}

			if (func->op_array.fn_flags & ZEND_ACC_IMMUTABLE) {
				zend_hash_add_ptr(&snapshot->function_table, key, func);
			} else {
				/* TODO: deep copy op_array for non-OPcache path */
			}
		} ZEND_HASH_FOREACH_END();
	}

	/* Snapshot user classes */
	zend_hash_init(&snapshot->class_table, 64, NULL, NULL, 1);

	{
		zend_string *key;
		zend_class_entry *ce;

		ZEND_HASH_MAP_FOREACH_STR_KEY_PTR(CG(class_table), key, ce) {
			if (UNEXPECTED(key == NULL)) {
				continue;
			}
			if (ce->type != ZEND_USER_CLASS) {
				continue;
			}

			if (ce->ce_flags & ZEND_ACC_IMMUTABLE) {
				zend_hash_add_ptr(&snapshot->class_table, key, ce);
			} else {
				/* TODO: deep copy class entry for non-OPcache path */
			}
		} ZEND_HASH_FOREACH_END();
	}

	return snapshot;
}

/**
 * Load a snapshot into the current thread's compiler tables.
 * Called from child thread after TSRM has initialized CG/EG.
 */
void async_thread_snapshot_load(const async_thread_snapshot_t *snapshot)
{
	/* Load user functions into CG(function_table) */
	if (zend_hash_num_elements(&snapshot->function_table) > 0) {
		zend_string *key;
		zend_function *func;

		zend_hash_extend(CG(function_table),
			zend_hash_num_elements(CG(function_table)) +
			zend_hash_num_elements(&snapshot->function_table), 0);

		ZEND_HASH_MAP_FOREACH_STR_KEY_PTR(&snapshot->function_table, key, func) {
			if (key && !zend_hash_exists(CG(function_table), key)) {
				_zend_hash_append_ptr(CG(function_table), key, func);
			}
		} ZEND_HASH_FOREACH_END();
	}

	/* Load user classes into CG(class_table) */
	if (zend_hash_num_elements(&snapshot->class_table) > 0) {
		zend_string *key;
		zend_class_entry *ce;

		zend_hash_extend(CG(class_table),
			zend_hash_num_elements(CG(class_table)) +
			zend_hash_num_elements(&snapshot->class_table), 0);

		ZEND_HASH_MAP_FOREACH_STR_KEY_PTR(&snapshot->class_table, key, ce) {
			if (key && !zend_hash_exists(CG(class_table), key)) {
				_zend_hash_append_ptr(CG(class_table), key, ce);
				if ((ce->ce_flags & ZEND_ACC_LINKED) && ZSTR_HAS_CE_CACHE(ce->name)) {
					ZSTR_SET_CE_CACHE_EX(ce->name, ce, 0);
				}
			}
		} ZEND_HASH_FOREACH_END();
	}

	/* Register autoloaders in child thread */
	if (Z_TYPE(snapshot->autoload_functions) == IS_ARRAY) {
		zval *entry;
		ZEND_HASH_FOREACH_VAL(Z_ARRVAL(snapshot->autoload_functions), entry) {
			zend_fcall_info fci;
			zend_fcall_info_cache fcc;
			char *error = NULL;
			if (zend_fcall_info_init(entry, 0, &fci, &fcc, NULL, &error) == SUCCESS) {
				zend_autoload_register_class_loader(&fcc, false);
			}
			if (error) {
				efree(error);
			}
		} ZEND_HASH_FOREACH_END();
	}
}

/**
 * Free snapshot resources.
 */
void async_thread_snapshot_destroy(async_thread_snapshot_t *snapshot)
{
	zend_hash_destroy(&snapshot->function_table);
	zend_hash_destroy(&snapshot->class_table);
	zend_hash_destroy(&snapshot->included_files);
	zval_ptr_dtor(&snapshot->autoload_functions);
	pefree(snapshot, 1);
}

///////////////////////////////////////////////////////////
/// 2. Thread PHP object — Async\Thread class
///////////////////////////////////////////////////////////

#define METHOD(name) PHP_METHOD(Async_Thread, name)
#define THIS_THREAD() Z_ASYNC_THREAD_P(ZEND_THIS)

zend_class_entry *async_ce_thread = NULL;

static zend_object_handlers thread_object_handlers;

/* ---- Object Lifecycle ---- */

static zend_object *thread_object_create(zend_class_entry *class_entry)
{
	async_thread_object_t *thread = zend_object_alloc(sizeof(async_thread_object_t), class_entry);

	ZEND_ASYNC_EVENT_REF_SET(thread, XtOffsetOf(async_thread_object_t, std), NULL);

	thread->thread_event = NULL;
	thread->finally_handlers = NULL;

	zend_object_std_init(&thread->std, class_entry);
	object_properties_init(&thread->std, class_entry);

	return &thread->std;
}

static void thread_object_dtor(zend_object *object)
{
	async_thread_object_t *thread = async_thread_object_from_obj(object);

	if (thread->thread_event != NULL) {
		zend_async_event_t *event = &thread->thread_event->base;

		/* Call finally handlers if the thread completed */
		if (thread->finally_handlers != NULL
			&& zend_hash_num_elements(thread->finally_handlers) > 0
			&& ZEND_ASYNC_EVENT_IS_CLOSED(event)) {

			finally_handlers_context_t *finally_context = ecalloc(1, sizeof(finally_handlers_context_t));
			finally_context->target = thread;
			finally_context->scope = NULL;
			finally_context->dtor = NULL;
			finally_context->params_count = 1;
			ZVAL_OBJ(&finally_context->params[0], &thread->std);

			HashTable *handlers = thread->finally_handlers;
			thread->finally_handlers = NULL;

			if (async_call_finally_handlers(handlers, finally_context, 0)) {
				GC_ADDREF(&thread->std);
			} else {
				efree(finally_context);
				zend_array_destroy(handlers);
			}
		}

		/* Dispose the underlying event (Thread object is the sole owner) */
		if (event->dispose != NULL) {
			event->dispose(event);
		}
		thread->thread_event = NULL;
	}

	if (thread->finally_handlers) {
		zend_array_destroy(thread->finally_handlers);
		thread->finally_handlers = NULL;
	}
}

static void thread_object_free(zend_object *object)
{
	zend_object_std_dtor(object);
}

static HashTable *thread_object_gc(zend_object *object, zval **table, int *num)
{
	async_thread_object_t *thread = async_thread_object_from_obj(object);

	zend_get_gc_buffer *buf = zend_get_gc_buffer_create();

	if (thread->thread_event != NULL) {
		if (!Z_ISUNDEF(thread->thread_event->result)) {
			zend_get_gc_buffer_add_zval(buf, &thread->thread_event->result);
		}
		if (thread->thread_event->exception != NULL) {
			zend_get_gc_buffer_add_obj(buf, thread->thread_event->exception);
		}
	}

	if (thread->finally_handlers) {
		zval *val;
		ZEND_HASH_FOREACH_VAL(thread->finally_handlers, val)
		{
			zend_get_gc_buffer_add_zval(buf, val);
		}
		ZEND_HASH_FOREACH_END();
	}

	zend_get_gc_buffer_use(buf, table, num);

	return NULL;
}

/* ---- PHP Methods ---- */

METHOD(__construct)
{
	zend_throw_error(NULL, "Cannot directly construct Async\\Thread");
}

METHOD(isRunning)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)) {
		RETURN_FALSE;
	}

	const zend_async_event_t *event = &thread->thread_event->base;

	RETURN_BOOL(event->loop_ref_count > 0
		&& !ZEND_ASYNC_EVENT_IS_CLOSED(event));
}

METHOD(isCompleted)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)) {
		RETURN_TRUE;
	}

	RETURN_BOOL(ZEND_ASYNC_EVENT_IS_CLOSED(&thread->thread_event->base));
}

METHOD(isCancelled)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)) {
		RETURN_FALSE;
	}

	/* TODO: thread cancellation not yet implemented */
	RETURN_FALSE;
}

METHOD(getResult)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)
		|| !ZEND_ASYNC_EVENT_IS_CLOSED(&thread->thread_event->base)) {
		RETURN_NULL();
	}

	if (!Z_ISUNDEF(thread->thread_event->result)) {
		RETURN_COPY(&thread->thread_event->result);
	}

	RETURN_NULL();
}

METHOD(getException)
{
	ZEND_PARSE_PARAMETERS_NONE();

	const async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)
		|| !ZEND_ASYNC_EVENT_IS_CLOSED(&thread->thread_event->base)) {
		RETURN_NULL();
	}

	if (thread->thread_event->exception != NULL) {
		RETURN_OBJ_COPY(thread->thread_event->exception);
	}

	RETURN_NULL();
}

METHOD(cancel)
{
	zval *cancellation = NULL;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancellation, async_ce_completable)
	ZEND_PARSE_PARAMETERS_END();

	async_thread_object_t *thread = THIS_THREAD();

	if (UNEXPECTED(thread->thread_event == NULL)) {
		return;
	}

	zend_async_event_t *event = &thread->thread_event->base;

	if (ZEND_ASYNC_EVENT_IS_CLOSED(event)) {
		return;
	}

	/* TODO: implement thread cancellation mechanism */
	async_throw_error("Thread cancellation is not yet implemented");
}

METHOD(finally)
{
	zval *callable;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(callable)
	ZEND_PARSE_PARAMETERS_END();

	if (UNEXPECTED(!zend_is_callable(callable, 0, NULL))) {
		async_throw_error("Argument #1 ($callback) must be callable");
		RETURN_THROWS();
	}

	async_thread_object_t *thread = THIS_THREAD();

	/* If the thread is already completed, call the callback immediately */
	if (thread->thread_event != NULL
		&& ZEND_ASYNC_EVENT_IS_CLOSED(&thread->thread_event->base)) {
		zval rv;
		ZVAL_UNDEF(&rv);

		zval param;
		ZVAL_OBJ(&param, &thread->std);

		zend_fcall_info fci;
		zend_fcall_info_cache fcc;
		if (zend_fcall_info_init(callable, 0, &fci, &fcc, NULL, NULL) == SUCCESS) {
			fci.retval = &rv;
			fci.param_count = 1;
			fci.params = &param;
			zend_call_function(&fci, &fcc);
		}

		zval_ptr_dtor(&rv);
		return;
	}

	if (thread->finally_handlers == NULL) {
		thread->finally_handlers = zend_new_array(0);
	}

	if (UNEXPECTED(zend_hash_next_index_insert(thread->finally_handlers, callable) == NULL)) {
		async_throw_error("Failed to add finally handler to thread");
		RETURN_THROWS();
	}

	Z_TRY_ADDREF_P(callable);
}

/* ---- Class Registration ---- */

void async_register_thread_ce(void)
{
	async_ce_thread = register_class_Async_Thread(async_ce_completable);
	async_ce_thread->create_object = thread_object_create;
	async_ce_thread->default_object_handlers = &thread_object_handlers;

	thread_object_handlers = std_object_handlers;
	thread_object_handlers.offset = XtOffsetOf(async_thread_object_t, std);
	thread_object_handlers.clone_obj = NULL;
	thread_object_handlers.dtor_obj = thread_object_dtor;
	thread_object_handlers.free_obj = thread_object_free;
	thread_object_handlers.get_gc = thread_object_gc;
}
