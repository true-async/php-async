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
#include "libuv_reactor.h"
#include <Zend/zend_async_API.h>

#include "exceptions.h"
#include "php_async.h"
#include "zend_common.h"

#ifdef PHP_WIN32
#include "win32/unistd.h"
#else
#include <sys/wait.h>
#include <signal.h>
#include <unistd.h>
#include <errno.h>
#endif

static void libuv_reactor_stop_with_exception(void);

// Forward declarations for global signal management
static void libuv_add_signal_event(int signum, zend_async_event_t *event);
static void libuv_remove_signal_event(int signum, zend_async_event_t *event);
static void libuv_add_process_event(zend_async_event_t *event);
static void libuv_remove_process_event(zend_async_event_t *event);
static void libuv_handle_process_events(void);
static void libuv_handle_signal_events(const int signum);
static void libuv_signal_close_cb(uv_handle_t *handle);

// Forward declarations for cleanup functions
static void libuv_cleanup_signal_handlers(void);
static void libuv_cleanup_signal_events(void);
static void libuv_cleanup_process_events(void);

#define UVLOOP (&ASYNC_G(uvloop))
#define LIBUV_REACTOR ((zend_async_globals *) ASYNC_GLOBALS)
#define LIBUV_REACTOR_VAR zend_async_globals *reactor = LIBUV_REACTOR;

#define LIBUV_REACTOR_VAR_FROM(var) zend_async_globals *reactor = (zend_async_globals *) var;
#define WATCHER ASYNC_G(watcherThread)
#define IF_EXCEPTION_STOP_REACTOR \
	if (UNEXPECTED(EG(exception) != NULL)) { \
		libuv_reactor_stop_with_exception(); \
	}

#define ASYNC_OF_EXCEPTION_MESSAGE "Async mode is disabled. Reactor API cannot be used."

#define START_REACTOR_OR_RETURN \
	if (UNEXPECTED(ASYNC_G(reactor_started) == false)) { \
		libuv_reactor_startup(); \
		if (UNEXPECTED(EG(exception) != NULL)) { \
			return NULL; \
		} \
	}

#define START_REACTOR_OR_RETURN_NULL \
	if (UNEXPECTED(ASYNC_G(reactor_started) == false)) { \
		libuv_reactor_startup(); \
		if (UNEXPECTED(EG(exception) != NULL)) { \
			return NULL; \
		} \
	}

#define EVENT_START_PROLOGUE(event) \
	if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(event))) { \
		return true; \
	} \
	if (event->loop_ref_count > 0) { \
		event->loop_ref_count++; \
		return true; \
	}

#define EVENT_STOP_PROLOGUE(event) \
	if (event->loop_ref_count > 1) { \
		event->loop_ref_count--; \
		if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(event))) { \
			event->loop_ref_count = 0; \
		} else { \
			return true; \
		} \
	} \
	if (UNEXPECTED(ZEND_ASYNC_EVENT_IS_CLOSED(event))) { \
		event->loop_ref_count = 0; \
		return true; \
	}

static zend_always_inline void close_event(zend_async_event_t *event)
{
	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
		ZEND_ASYNC_EVENT_SET_CLOSED(event);
	}
}

/* {{{ libuv_reactor_startup */
bool libuv_reactor_startup(void)
{
	if (ASYNC_G(reactor_started)) {
		return true;
	}

	if (ZEND_ASYNC_IS_OFF) {
		async_throw_error(ASYNC_OF_EXCEPTION_MESSAGE);
		return false;
	}

	const int result = uv_loop_init(UVLOOP);

	if (result != 0) {
		async_throw_error("Failed to initialize loop: %s", uv_strerror(result));
		return false;
	}

	uv_loop_set_data(UVLOOP, ASYNC_GLOBALS);
	ASYNC_G(reactor_started) = true;
	return true;
}

/* }}} */

/* {{{ libuv_reactor_stop_with_exception */
static void libuv_reactor_stop_with_exception(void)
{
	// TODO: implement libuv_reactor_stop_with_exception
}

/* }}} */

/* {{{ libuv_reactor_shutdown */
bool libuv_reactor_shutdown(void)
{
	if (EXPECTED(ASYNC_G(reactor_started))) {

		if (uv_loop_alive(UVLOOP) != 0) {
			// need to finish handlers
			uv_run(UVLOOP, UV_RUN_ONCE);
		}

		// Cleanup global signal management structures
		libuv_cleanup_signal_handlers();
		libuv_cleanup_signal_events();
		libuv_cleanup_process_events();

		uv_loop_close(UVLOOP);
		ASYNC_G(reactor_started) = false;
	}
	return true;
}

/* }}} */

/* {{{ libuv_reactor_execute */
bool libuv_reactor_execute(bool no_wait)
{
	// OPTIMIZATION: Skip uv_run() if no libuv handles to avoid unnecessary clock_gettime() calls
	if (!uv_loop_alive(UVLOOP)) {
		return false;
	}

	const bool has_handles = uv_run(UVLOOP, no_wait ? UV_RUN_NOWAIT : UV_RUN_ONCE);

	return has_handles && ZEND_ASYNC_ACTIVE_EVENT_COUNT > 0;
}

/* }}} */

/* {{{ libuv_reactor_loop_alive */
bool libuv_reactor_loop_alive(void)
{
	if (!ASYNC_G(reactor_started)) {
		return false;
	}

	return ZEND_ASYNC_ACTIVE_EVENT_COUNT > 0 && uv_loop_alive(UVLOOP) != 0;
}

/* }}} */

/* {{{ libuv_close_handle_cb */
static void libuv_close_handle_cb(uv_handle_t *handle)
{
	pefree(handle->data, 0);
}

/* }}} */

/* {{{ libuv_close_poll_handle_cb */
static void libuv_close_poll_handle_cb(uv_handle_t *handle)
{
	async_poll_event_t *poll = (async_poll_event_t *) handle->data;

	/* Check if PHP requested descriptor closure after event cleanup */
	if (ZEND_ASYNC_EVENT_SHOULD_CLOSE_FD(&poll->event.base)) {
		if (poll->event.is_socket && ZEND_VALID_SOCKET(poll->event.socket)) {
			/* Socket cleanup - just close, no blocking operations in LibUV callback */
#ifdef PHP_WIN32
			closesocket(poll->event.socket);
#else
			close(poll->event.socket);
#endif
		} else if (!poll->event.is_socket && poll->event.file != ZEND_FD_NULL) {
			/* File descriptor cleanup */
#ifdef PHP_WIN32
			CloseHandle((HANDLE) poll->event.file);
#else
			close(poll->event.file);
#endif
		}
	}

	pefree(poll, 0);
}

/* }}} */

/* {{{ libuv_add_callback */
static bool libuv_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_push(event, callback);
}

/* }}} */

/* {{{ libuv_remove_callback */
static bool libuv_remove_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	return zend_async_callbacks_remove(event, callback);
}

/* }}} */

/////////////////////////////////////////////////////////////////////////////
/// Poll API
//////////////////////////////////////////////////////////////////////////////

/* Forward declaration */
static zend_always_inline void
async_poll_notify_proxies(async_poll_event_t *poll, async_poll_event triggered_events, zend_object *exception);

/* {{{ on_poll_event */
static void on_poll_event(uv_poll_t *handle, int status, int events)
{
	async_poll_event_t *poll = handle->data;
	zend_object *exception = NULL;

	if (status < 0 && status != UV_EBADF) {
		exception = async_new_exception(async_ce_input_output_exception, "Input output error: %s", uv_strerror(status));
	}

	// !WARNING!
	// LibUV may return the UV_EBADF code when the remote host closes
	// the connection while the descriptor is still present in the EventLoop.
	// For POLL events, we handle this by ignoring the situation
	// so that the coroutine receives the ASYNC_DISCONNECT flag.
	// This code can be considered "incorrect"; however, this solution is acceptable.
	//
	if (UNEXPECTED(status == UV_EBADF)) {
		events = ASYNC_DISCONNECT;
	}

	/* Filter spurious READABLE events on sockets.
	 * libuv uv_poll may signal readable when no data is actually available.
	 * Use recv(MSG_PEEK) to verify; if WOULDBLOCK — remove the flag. */
	if (status >= 0 && poll->event.is_socket && (events & ASYNC_READABLE)) {
		char peek_buf;
		const int peek_ret = recv(poll->event.socket, &peek_buf, 1, MSG_PEEK);

		if (peek_ret < 0) {
#ifdef PHP_WIN32
			const int err = WSAGetLastError();
			if (err == WSAEWOULDBLOCK) {
				events &= ~ASYNC_READABLE;
			}
#else
			if (errno == EAGAIN || errno == EWOULDBLOCK) {
				events &= ~ASYNC_READABLE;
			}
#endif
			if (events == 0) {
				return;
			}
		}
	}

	poll->event.triggered_events = events;

	/* Check if there are active proxies */
	if (poll->proxies_count > 0) {
		/* Notify all matching proxies */
		async_poll_notify_proxies(poll, events, exception);
	} else {
		/* Standard base event notification */
		ZEND_ASYNC_CALLBACKS_NOTIFY(&poll->event.base, NULL, exception);
	}

	if (exception != NULL) {
		zend_object_release(exception);
	}

	IF_EXCEPTION_STOP_REACTOR;
}

/* }}} */

/* {{{ libuv_poll_start */
static bool libuv_poll_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	async_poll_event_t *poll = (async_poll_event_t *) (event);

	const int error = uv_poll_start(&poll->uv_handle, poll->event.events, on_poll_event);

	if (error < 0) {
		async_throw_error("Failed to start poll handle: %s", uv_strerror(error));
		return false;
	}

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_poll_stop */
static bool libuv_poll_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);

	async_poll_event_t *poll = (async_poll_event_t *) (event);

	const int error = uv_poll_stop(&poll->uv_handle);

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);

	if (error < 0) {
		async_throw_error("Failed to stop poll handle: %s", uv_strerror(error));
		return false;
	}

	return true;
}

/* }}} */

/* {{{ libuv_poll_dispose */
static bool libuv_poll_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REFCOUNT(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return true;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	async_poll_event_t *poll = (async_poll_event_t *) (event);

	/* Free proxies array if exists */
	if (poll->proxies != NULL) {
		pefree(poll->proxies, 0);
		poll->proxies = NULL;
	}

	/* Use poll-specific callback for poll events that may need descriptor cleanup */
	uv_close((uv_handle_t *) &poll->uv_handle, libuv_close_poll_handle_cb);
	return true;
}

/* }}} */

/* {{{ async_poll_notify_proxies */
static zend_always_inline void
async_poll_notify_proxies(async_poll_event_t *poll, async_poll_event triggered_events, zend_object *exception)
{
	/* Process each proxy that matches triggered events */
	for (uint32_t i = 0; i < poll->proxies_count; i++) {
		zend_async_poll_proxy_t *proxy = poll->proxies[i];

		if ((triggered_events & proxy->events) != 0) {
			/* Increase ref count to prevent disposal during processing */
			ZEND_ASYNC_EVENT_ADD_REF(&proxy->base);

			/* Calculate events relevant to this proxy */
			async_poll_event proxy_events = triggered_events & proxy->events;

			/* Set triggered events and notify callbacks */
			proxy->triggered_events = proxy_events;
			ZEND_ASYNC_CALLBACKS_NOTIFY_FROM_HANDLER(&proxy->base, &proxy_events, exception);

			/* Release reference after processing */
			ZEND_ASYNC_EVENT_RELEASE(&proxy->base);
		}
	}
}

/* }}} */

/* {{{ async_poll_add_proxy */
static zend_always_inline void async_poll_add_proxy(async_poll_event_t *poll, zend_async_poll_proxy_t *proxy)
{
	if (poll->proxies == NULL) {
		poll->proxies = (zend_async_poll_proxy_t **) pecalloc(4, sizeof(zend_async_poll_proxy_t *), 0);
		poll->proxies_capacity = 2;
	}

	if (poll->proxies_count == poll->proxies_capacity) {
		poll->proxies_capacity *= 2;
		poll->proxies = (zend_async_poll_proxy_t **) perealloc(
				poll->proxies, poll->proxies_capacity * sizeof(zend_async_poll_proxy_t *), 0);
	}

	poll->proxies[poll->proxies_count++] = proxy;
}

/* }}} */

/* {{{ async_poll_remove_proxy */
static zend_always_inline void async_poll_remove_proxy(async_poll_event_t *poll, zend_async_poll_proxy_t *proxy)
{
	for (uint32_t i = 0; i < poll->proxies_count; i++) {
		if (poll->proxies[i] == proxy) {
			/* Move last element to this position */
			poll->proxies[i] = poll->proxies[--poll->proxies_count];
			break;
		}
	}
}

/* }}} */

/* {{{ async_poll_aggregate_events */
static zend_always_inline async_poll_event async_poll_aggregate_events(async_poll_event_t *poll)
{
	async_poll_event aggregated = 0;

	for (uint32_t i = 0; i < poll->proxies_count; i++) {
		aggregated |= poll->proxies[i]->events;

		/* Early exit if all possible events are set */
		if (aggregated == (ASYNC_READABLE | ASYNC_WRITABLE | ASYNC_DISCONNECT | ASYNC_PRIORITIZED)) {
			break;
		}
	}

	return aggregated;
}

/* }}} */

/* {{{ libuv_poll_proxy_start */
static bool libuv_poll_proxy_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	zend_async_poll_proxy_t *proxy = (zend_async_poll_proxy_t *) event;
	async_poll_event_t *poll = (async_poll_event_t *) proxy->poll_event;

	/* Add proxy to the array */
	async_poll_add_proxy(poll, proxy);

	/* Check if all proxy events are already set in base event */
	if ((poll->event.events & proxy->events) != proxy->events) {
		/* Add missing proxy events to base event */
		poll->event.events |= proxy->events;

		const int error = uv_poll_start(&poll->uv_handle, poll->event.events, on_poll_event);

		if (error < 0) {
			async_throw_error("Failed to update poll handle events: %s", uv_strerror(error));
			return false;
		}
	}

	ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
	event->loop_ref_count = 1;
	return true;
}

/* }}} */

/* {{{ libuv_poll_proxy_stop */
static bool libuv_poll_proxy_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);

	zend_async_poll_proxy_t *proxy = (zend_async_poll_proxy_t *) event;
	async_poll_event_t *poll = (async_poll_event_t *) proxy->poll_event;

	/* Remove proxy from the array */
	async_poll_remove_proxy(poll, proxy);

	/* Recalculate events from remaining proxies */
	async_poll_event new_events = async_poll_aggregate_events(poll);

	/* Update base event */
	if (poll->event.events != new_events && poll->event.base.ref_count > 1) {
		poll->event.events = new_events;

		/* Restart with new events */
		const int error = uv_poll_start(&poll->uv_handle, new_events, on_poll_event);

		if (error < 0) {
			async_throw_error("Failed to update poll handle events: %s", uv_strerror(error));
		}
	}

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_poll_proxy_dispose */
static bool libuv_poll_proxy_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REFCOUNT(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return true;
	}

	zend_async_poll_proxy_t *proxy = (zend_async_poll_proxy_t *) event;
	async_poll_event_t *poll = (async_poll_event_t *) proxy->poll_event;

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	/* Release reference to base poll event */
	ZEND_ASYNC_EVENT_RELEASE(&poll->event.base);

	pefree(proxy, 0);
	return true;
}

/* }}} */

/* {{{ libuv_new_poll_event */
zend_async_poll_event_t *
libuv_new_poll_event(zend_file_descriptor_t fh, zend_socket_t socket, async_poll_event events, size_t extra_size)
{
	START_REACTOR_OR_RETURN_NULL;

	async_poll_event_t *poll =
			pecalloc(1, extra_size != 0 ? sizeof(async_poll_event_t) + extra_size : sizeof(async_poll_event_t), 0);

	int error = 0;

	if (socket != 0) {
		error = uv_poll_init_socket(UVLOOP, &poll->uv_handle, socket);
		poll->event.is_socket = true;
		poll->event.socket = socket;
	} else if (fh != ZEND_FD_NULL) {
#ifdef PHP_WIN32
		async_throw_error("Windows does not support file descriptor polling");
		pefree(poll, 0);
		return NULL;
#else
		error = uv_poll_init(UVLOOP, &poll->uv_handle, (int) fh);
		poll->event.is_socket = false;
		poll->event.file = fh;
#endif
	} else {
	}

	if (error < 0) {
		async_throw_error("Failed to initialize poll handle: %s", uv_strerror(error));
		pefree(poll, 0);
		return NULL;
	}

	// Link the handle to the loop.
	poll->uv_handle.data = poll;
	poll->event.events = events;
	poll->event.base.extra_offset = sizeof(async_poll_event_t);
	poll->event.base.ref_count = 1;

	// Initialize the event methods
	poll->event.base.add_callback = libuv_add_callback;
	poll->event.base.del_callback = libuv_remove_callback;
	poll->event.base.start = libuv_poll_start;
	poll->event.base.stop = libuv_poll_stop;
	poll->event.base.dispose = libuv_poll_dispose;

	return &poll->event;
}

/* }}} */

/* {{{ libuv_new_socket_event */
zend_async_poll_event_t *libuv_new_socket_event(zend_socket_t socket, async_poll_event events, size_t extra_size)
{
	return libuv_new_poll_event(ZEND_FD_NULL, socket, events, extra_size);
}

/* }}} */

/* {{{ libuv_new_poll_proxy_event */
zend_async_poll_proxy_t *
libuv_new_poll_proxy_event(zend_async_poll_event_t *poll_event, async_poll_event events, size_t extra_size)
{
	START_REACTOR_OR_RETURN_NULL;

	zend_async_poll_proxy_t *proxy = pecalloc(
			1, extra_size != 0 ? sizeof(zend_async_poll_proxy_t) + extra_size : sizeof(zend_async_poll_proxy_t), 0);

	/* Set up proxy */
	proxy->poll_event = poll_event;
	proxy->events = events;

	/* Add reference to base poll event */
	ZEND_ASYNC_EVENT_ADD_REF(&poll_event->base);

	/* Initialize base event structure */
	proxy->base.extra_offset = sizeof(zend_async_poll_proxy_t);
	proxy->base.ref_count = 1;

	/* Initialize proxy methods */
	proxy->base.add_callback = libuv_add_callback;
	proxy->base.del_callback = libuv_remove_callback;
	proxy->base.start = libuv_poll_proxy_start;
	proxy->base.stop = libuv_poll_proxy_stop;
	proxy->base.dispose = libuv_poll_proxy_dispose;

	return proxy;
}

/* }}} */

/////////////////////////////////////////////////////////////////////////////////
/// Timer API
/////////////////////////////////////////////////////////////////////////////////

/* {{{ on_timer_event */
static void on_timer_event(uv_timer_t *handle)
{
	async_timer_event_t *timer_event = handle->data;

	// If the timer is not periodic, we close it after the first execution.
	if (false == timer_event->event.is_periodic) {
		close_event(&timer_event->event.base);
	}

	ZEND_ASYNC_CALLBACKS_NOTIFY(&timer_event->event.base, NULL, NULL);

	IF_EXCEPTION_STOP_REACTOR;
}

/* }}} */

/* {{{ libuv_timer_start */
static bool libuv_timer_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	async_timer_event_t *timer = (async_timer_event_t *) (event);

	const int error = uv_timer_start(&timer->uv_handle,
									 on_timer_event,
									 timer->event.timeout,
									 timer->event.is_periodic ? timer->event.timeout : 0);

	if (error < 0) {
		async_throw_error("Failed to start timer handle: %s", uv_strerror(error));
		return false;
	}

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_timer_stop */
static bool libuv_timer_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);

	async_timer_event_t *timer = (async_timer_event_t *) (event);

	const int error = uv_timer_stop(&timer->uv_handle);

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);

	if (error < 0) {
		async_throw_error("Failed to stop timer handle: %s", uv_strerror(error));
		return false;
	}

	return true;
}

/* }}} */

/* {{{ libuv_timer_dispose */
static bool libuv_timer_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REFCOUNT(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return true;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	async_timer_event_t *timer = (async_timer_event_t *) (event);

	uv_close((uv_handle_t *) &timer->uv_handle, libuv_close_handle_cb);
	return true;
}

/* }}} */

/* {{{ libuv_new_timer_event */
zend_async_timer_event_t *
libuv_new_timer_event(const zend_ulong timeout, const zend_ulong nanoseconds, const bool is_periodic, size_t extra_size)
{
	START_REACTOR_OR_RETURN_NULL;

	async_timer_event_t *event =
			pecalloc(1, extra_size != 0 ? sizeof(async_timer_event_t) + extra_size : sizeof(async_timer_event_t), 0);

	const int error = uv_timer_init(UVLOOP, &event->uv_handle);

	if (error < 0) {
		async_throw_error("Failed to initialize timer handle: %s", uv_strerror(error));
		pefree(event, 0);
		return NULL;
	}

	event->uv_handle.data = event;

	// Calculate final timeout with nanoseconds support
	zend_ulong final_timeout = timeout;
	if (nanoseconds > 0 && timeout == 0) {
		// If only nanoseconds provided, convert to milliseconds with ceiling
		final_timeout = (nanoseconds + 999999) / 1000000; // Round up to next millisecond
	}

	event->event.timeout = final_timeout;
	event->event.is_periodic = is_periodic;
	event->event.base.extra_offset = sizeof(async_timer_event_t);
	event->event.base.ref_count = 1;

	event->event.base.add_callback = libuv_add_callback;
	event->event.base.del_callback = libuv_remove_callback;
	event->event.base.start = libuv_timer_start;
	event->event.base.stop = libuv_timer_stop;
	event->event.base.dispose = libuv_timer_dispose;

	return &event->event;
}

/* }}} */

/////////////////////////////////////////////////////////////////////////////////
///// Signal API
////////////////////////////////////////////////////////////////////////////////

/* NOTE: on_signal_event removed - now using global signal management */

/* {{{ libuv_signal_start */
static bool libuv_signal_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	async_signal_event_t *signal = (async_signal_event_t *) (event);

	libuv_add_signal_event(signal->event.signal, event);

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_signal_stop */
static bool libuv_signal_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);

	async_signal_event_t *signal = (async_signal_event_t *) (event);

	libuv_remove_signal_event(signal->event.signal, event);

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_signal_dispose */
static bool libuv_signal_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REFCOUNT(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return true;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	// Signal cleanup handled by global signal management
	pefree(event, 0);
	return true;
}

/* }}} */

/* {{{ libuv_new_signal_event */
zend_async_signal_event_t *libuv_new_signal_event(int signum, size_t extra_size)
{
	START_REACTOR_OR_RETURN_NULL;

	async_signal_event_t *signal =
			pecalloc(1, extra_size != 0 ? sizeof(async_signal_event_t) + extra_size : sizeof(async_signal_event_t), 0);
	signal->event.signal = signum;
	signal->event.base.extra_offset = sizeof(async_signal_event_t);
	signal->event.base.ref_count = 1;

	signal->event.base.add_callback = libuv_add_callback;
	signal->event.base.del_callback = libuv_remove_callback;
	signal->event.base.start = libuv_signal_start;
	signal->event.base.stop = libuv_signal_stop;
	signal->event.base.dispose = libuv_signal_dispose;

	return &signal->event;
}

/* }}} */

/////////////////////////////////////////////////////////////////////////////////
/// Global Signal Management API
/////////////////////////////////////////////////////////////////////////////////
/**
 * **UNIX Signal Handling**
 *
 * The Unix signal handling code allows creating multiple wait events for the same signal, but in reality,
 * there will be only one actual handler for the EventLoop.
 *
 * > Note that the `SIGCHLD` handler is also used to detect the process-event.
 *
 * **Implementation details of process handling.**
 *
 * Because the process exit code can only be retrieved once,
 * while multiple coroutines may want to wait for the same process,
 * we use a single EventLoop event for each unique process ID.
 *
 * Thus, ASYNC_G(process_events) is a hash table with the key as ProcessId
 * and the value as an Event for process handling.
 **/

/* {{{ libuv_signal_close_cb */
static void libuv_signal_close_cb(uv_handle_t *handle)
{
	pefree(handle, 0);
}

/* }}} */

/* {{{ libuv_global_signal_callback */
static void libuv_global_signal_callback(uv_signal_t *handle, int signum)
{
	// Handle regular signal events for ALL signals (including SIGCHLD)
	libuv_handle_signal_events(signum);

	// Additionally handle process events if this is SIGCHLD
	if (signum == SIGCHLD) {
		libuv_handle_process_events();
	}

	IF_EXCEPTION_STOP_REACTOR;
}

/* }}} */

/* {{{ libuv_get_or_create_signal_handler */
static uv_signal_t *libuv_get_or_create_signal_handler(int signum)
{
	if (ASYNC_G(signal_handlers) == NULL) {
		ASYNC_G(signal_handlers) = zend_new_array(0);
	}

	uv_signal_t *handler = zend_hash_index_find_ptr(ASYNC_G(signal_handlers), signum);
	if (handler != NULL) {
		return handler;
	}

	// Create new signal handler
	handler = pecalloc(1, sizeof(uv_signal_t), 0);

	int error = uv_signal_init(UVLOOP, handler);
	if (UNEXPECTED(error < 0)) {
		async_throw_error("Failed to initialize signal handle: %s", uv_strerror(error));
		pefree(handler, 0);
		return NULL;
	}

	error = uv_signal_start(handler, libuv_global_signal_callback, signum);
	if (UNEXPECTED(error < 0)) {
		async_throw_error("Failed to start signal handle: %s", uv_strerror(error));
		uv_close((uv_handle_t *) handler, libuv_signal_close_cb);
		return NULL;
	}

	if (UNEXPECTED(zend_hash_index_add_ptr(ASYNC_G(signal_handlers), signum, handler) == NULL)) {
		async_throw_error("Failed to store signal handler");
		uv_signal_stop(handler);
		uv_close((uv_handle_t *) handler, libuv_signal_close_cb);
		return NULL;
	}

	return handler;
}

/* }}} */

/* {{{ libuv_add_signal_event */
static void libuv_add_signal_event(int signum, zend_async_event_t *event)
{
	// Ensure signal handler exists
	uv_signal_t *handler = libuv_get_or_create_signal_handler(signum);
	if (handler == NULL) {
		return;
	}

	// Initialize signal_events if needed
	if (ASYNC_G(signal_events) == NULL) {
		ASYNC_G(signal_events) = zend_new_array(0);
	}

	// Get or create events list for this signal
	HashTable *events_list = zend_hash_index_find_ptr(ASYNC_G(signal_events), signum);
	if (events_list == NULL) {
		events_list = zend_new_array(0);
	}

	// Add event to the list (use pointer address as key)
	if (UNEXPECTED(zend_hash_index_add_ptr(events_list, async_ptr_to_index(event), event) == NULL)) {
		async_throw_error("Failed to store signal event");
		return;
	}
}

/* }}} */

/* {{{ libuv_remove_signal_event */
static void libuv_remove_signal_event(int signum, zend_async_event_t *event)
{
	if (ASYNC_G(signal_events) == NULL) {
		return;
	}

	HashTable *events_list = zend_hash_index_find_ptr(ASYNC_G(signal_events), signum);
	if (events_list == NULL) {
		return;
	}

	zend_hash_index_del(events_list, async_ptr_to_index(event));

	// If no more events for this signal, remove the handler (but check for process events if SIGCHLD)
	if (zend_hash_num_elements(events_list) == 0) {
		bool can_remove_handler = true;

		// For SIGCHLD, check if there are process events still active
		if (signum == SIGCHLD && ASYNC_G(process_events) != NULL) {
			if (zend_hash_num_elements(ASYNC_G(process_events)) > 0) {
				can_remove_handler = false;
			}
		}

		if (can_remove_handler && ASYNC_G(signal_handlers) != NULL) {
			uv_signal_t *handler = zend_hash_index_find_ptr(ASYNC_G(signal_handlers), signum);
			if (handler != NULL) {
				uv_signal_stop(handler);
				uv_close((uv_handle_t *) handler, libuv_signal_close_cb);
				zend_hash_index_del(ASYNC_G(signal_handlers), signum);
			}
		}

		zend_hash_destroy(events_list);
		pefree(events_list, 0);
		zend_hash_index_del(ASYNC_G(signal_events), signum);
	}
}

/* }}} */

/* {{{ libuv_handle_process_events */
static void libuv_handle_process_events(void)
{
	if (ASYNC_G(process_events) == NULL) {
		return;
	}

	// Create a copy of events to iterate safely (callbacks may modify the original HashTable)
	uint32_t num_events = zend_hash_num_elements(ASYNC_G(process_events));
	if (num_events == 0) {
		return;
	}

	zend_async_event_t **events_copy = pecalloc(num_events, sizeof(zend_async_event_t *), 0);
	if (events_copy == NULL) {
		return;
	}

	uint32_t i = 0;
	zend_async_event_t *event;
	ZEND_HASH_FOREACH_PTR(ASYNC_G(process_events), event)
	{
		events_copy[i++] = event;
	}
	ZEND_HASH_FOREACH_END();

	// Process events from the copy
	for (i = 0; i < num_events; i++) {
		event = events_copy[i];

		// Get PID to use as key for verification
		async_process_event_t *process = (async_process_event_t *) event;
		uintptr_t pid_key = (uintptr_t) process->event.process;

		// Verify event is still in the HashTable (might have been removed)
		if (ASYNC_G(process_events) == NULL || zend_hash_index_find_ptr(ASYNC_G(process_events), pid_key) == NULL) {
			continue;
		}

#ifndef PHP_WIN32
		pid_t pid = (pid_t) process->event.process;
		int status;
		pid_t result = waitpid(pid, &status, WNOHANG);

		if (result == pid) {
			// Process has exited
			if (WIFEXITED(status)) {
				process->event.exit_code = WEXITSTATUS(status);
			} else if (WIFSIGNALED(status)) {
				process->event.exit_code = -WTERMSIG(status);
			} else {
				process->event.exit_code = -1;
			}

			ZEND_ASYNC_CALLBACKS_NOTIFY(event, NULL, NULL);
			// Process event will be removed when stopped
		}
#endif
	}

	pefree(events_copy, 0);
}

/* }}} */

/* {{{ libuv_handle_signal_events */
static void libuv_handle_signal_events(const int signum)
{
	if (ASYNC_G(signal_events) == NULL) {
		return;
	}

	HashTable *events_list = zend_hash_index_find_ptr(ASYNC_G(signal_events), signum);
	if (events_list == NULL) {
		return;
	}

	zend_async_event_t *event;
	ZEND_HASH_FOREACH_PTR(events_list, event)
	{
		ZEND_ASYNC_CALLBACKS_NOTIFY(event, NULL, NULL);
	}
	ZEND_HASH_FOREACH_END();
}

/* }}} */

/* {{{ libuv_add_process_event */
static void libuv_add_process_event(zend_async_event_t *event)
{
	// Ensure SIGCHLD handler exists
	libuv_get_or_create_signal_handler(SIGCHLD);
}

/* }}} */

/* {{{ libuv_remove_process_event */
static void libuv_remove_process_event(zend_async_event_t *event)
{
	if (ASYNC_G(process_events) == NULL) {
		return;
	}

	// Get process handle from event to use as key
	async_process_event_t *process_event = (async_process_event_t *) event;

	zend_hash_index_del(ASYNC_G(process_events), (uintptr_t) process_event->event.process);

	// Only remove SIGCHLD handler if no more process events AND no regular signal events for SIGCHLD
	if (zend_hash_num_elements(ASYNC_G(process_events)) == 0) {
		bool has_sigchld_signal_events = false;

		// Check if there are regular signal events for SIGCHLD
		if (ASYNC_G(signal_events) != NULL) {
			HashTable *sigchld_events = zend_hash_index_find_ptr(ASYNC_G(signal_events), SIGCHLD);
			if (sigchld_events != NULL && zend_hash_num_elements(sigchld_events) > 0) {
				has_sigchld_signal_events = true;
			}
		}

		// Only remove handler if no signal events exist for SIGCHLD
		if (!has_sigchld_signal_events && ASYNC_G(signal_handlers) != NULL) {
			uv_signal_t *handler = zend_hash_index_find_ptr(ASYNC_G(signal_handlers), SIGCHLD);
			if (handler != NULL) {
				uv_signal_stop(handler);
				uv_close((uv_handle_t *) handler, libuv_signal_close_cb);
				zend_hash_index_del(ASYNC_G(signal_handlers), SIGCHLD);
			}
		}

		zend_hash_destroy(ASYNC_G(process_events));
		pefree(ASYNC_G(process_events), 0);
		ASYNC_G(process_events) = NULL;
	}
}

/* }}} */

/////////////////////////////////////////////////////////////////////////////////
/// Global Signal Management Cleanup Functions
/////////////////////////////////////////////////////////////////////////////////

/* {{{ libuv_cleanup_signal_handlers */
static void libuv_cleanup_signal_handlers(void)
{
	if (ASYNC_G(signal_handlers) != NULL) {
		uv_signal_t *handler;
		ZEND_HASH_FOREACH_PTR(ASYNC_G(signal_handlers), handler)
		{
			if (handler != NULL) {
				uv_signal_stop(handler);
				uv_close((uv_handle_t *) handler, libuv_signal_close_cb);
			}
		}
		ZEND_HASH_FOREACH_END();

		zend_array_destroy(ASYNC_G(signal_handlers));
		ASYNC_G(signal_handlers) = NULL;
	}
}

/* }}} */

/* {{{ libuv_cleanup_signal_events */
static void libuv_cleanup_signal_events(void)
{
	if (ASYNC_G(signal_events) != NULL) {
		HashTable *events_list;
		ZEND_HASH_FOREACH_PTR(ASYNC_G(signal_events), events_list)
		{
			if (events_list != NULL) {
				zend_hash_destroy(events_list);
				pefree(events_list, 0);
			}
		}
		ZEND_HASH_FOREACH_END();

		zend_array_destroy(ASYNC_G(signal_events));
		ASYNC_G(signal_events) = NULL;
	}
}

/* }}} */

/* {{{ libuv_cleanup_process_events */
static void libuv_cleanup_process_events(void)
{
	if (ASYNC_G(process_events) != NULL) {
		zend_array_destroy(ASYNC_G(process_events));
		ASYNC_G(process_events) = NULL;
	}
}

/* }}} */

/////////////////////////////////////////////////////////////////////////////////
/// Process API
/////////////////////////////////////////////////////////////////////////////////

#ifdef PHP_WIN32
static void process_watcher_thread(void *args)
{
	LIBUV_REACTOR_VAR_FROM(args);

	ULONG_PTR completionKey;

	while (reactor->isRunning && reactor->ioCompletionPort != NULL) {

		DWORD lpNumberOfBytesTransferred;
		// OVERLAPPED overlapped = {0};
		LPOVERLAPPED lpOverlapped = NULL;

		if (false ==
			GetQueuedCompletionStatus(
					reactor->ioCompletionPort, &lpNumberOfBytesTransferred, &completionKey, &lpOverlapped, INFINITE)) {
			break;
		}

		if (completionKey == 0) {
			continue;
		}

		if (reactor->isRunning == false) {
			break;
		}

		switch (lpNumberOfBytesTransferred) {
			case JOB_OBJECT_MSG_EXIT_PROCESS:
			case JOB_OBJECT_MSG_ABNORMAL_EXIT_PROCESS:
			case JOB_OBJECT_MSG_ACTIVE_PROCESS_ZERO:
				// Try to handle process exit
				goto handleExitCode;
			default:
				// Ignore other messages
				continue;
		}

	handleExitCode:

		async_process_event_t *process_event = (async_process_event_t *) completionKey;

		if (UNEXPECTED(circular_buffer_is_full(reactor->pid_queue))) {

			uv_async_send(reactor->uvloop_wakeup);

			unsigned int delay = 1;

			while (reactor->isRunning && circular_buffer_is_full(reactor->pid_queue)) {
				usleep(delay);
				delay = MIN(delay << 1, 1000);
			}

			if (false == reactor->isRunning) {
				break;
			}
		}

		circular_buffer_push(reactor->pid_queue, &process_event, false);
		uv_async_send(reactor->uvloop_wakeup);
	}
}

static void libuv_start_process_watcher(void);
static void libuv_stop_process_watcher(void);

static void on_process_event(uv_async_t *handle)
{
	LIBUV_REACTOR_VAR;

	if (reactor->pid_queue == NULL || circular_buffer_is_empty(reactor->pid_queue)) {
		return;
	}

	async_process_event_t *process_event;

	while (reactor->pid_queue && circular_buffer_is_not_empty(reactor->pid_queue)) {
		circular_buffer_pop(reactor->pid_queue, &process_event);

		DWORD exit_code;
		GetExitCodeProcess(process_event->event.process, &exit_code);

		process_event->event.exit_code = exit_code;

		if (reactor->countWaitingDescriptors > 0) {
			reactor->countWaitingDescriptors--;

			if (reactor->countWaitingDescriptors == 0) {
				libuv_stop_process_watcher();
			}
		}

		ZEND_ASYNC_CALLBACKS_NOTIFY(&process_event->event.base, NULL, NULL);
		process_event->event.base.stop(&process_event->event.base);
		IF_EXCEPTION_STOP_REACTOR;
	}
}

static void libuv_start_process_watcher(void)
{
	if (WATCHER != NULL) {
		return;
	}

	uv_thread_t *thread = pecalloc(1, sizeof(uv_thread_t), 0);

	if (thread == NULL) {
		return;
	}

	LIBUV_REACTOR_VAR;

	// Create IoCompletionPort
	reactor->ioCompletionPort = CreateIoCompletionPort(INVALID_HANDLE_VALUE, NULL, 0, 1);

	if (reactor->ioCompletionPort == NULL) {
		char *error_msg = php_win32_error_to_msg((HRESULT) GetLastError());
		php_error_docref(NULL, E_CORE_ERROR, "Failed to create IO completion port: %s", error_msg);
		php_win32_error_msg_free(error_msg);
		return;
	}

	reactor->isRunning = true;
	reactor->countWaitingDescriptors = 0;

	int error = uv_thread_create(thread, process_watcher_thread, reactor);

	if (error < 0) {
		uv_thread_detach(thread);
		pefree(thread, 0);
		reactor->isRunning = false;
		php_error_docref(NULL, E_CORE_ERROR, "Failed to create process watcher thread: %s", uv_strerror(error));
		return;
	}

	WATCHER = thread;
	reactor->uvloop_wakeup = pecalloc(1, sizeof(uv_async_t), 0);

	error = uv_async_init(UVLOOP, reactor->uvloop_wakeup, on_process_event);
	reactor->pid_queue = pecalloc(1, sizeof(circular_buffer_t), 0);
	circular_buffer_ctor(reactor->pid_queue, 64, sizeof(async_process_event_t *), NULL);

	if (error < 0) {
		uv_thread_detach(thread);
		reactor->isRunning = false;
		pefree(thread, 0);
		WATCHER = NULL;
		php_error_docref(NULL, E_CORE_ERROR, "Failed to initialize async handle: %s", uv_strerror(error));
	}
}

static void libuv_wakeup_close_cb(uv_handle_t *handle)
{
	pefree(handle, 0);
}

/* {{{ libuv_stop_process_watcher */
static void libuv_stop_process_watcher(void)
{
	if (WATCHER == NULL) {
		return;
	}

	LIBUV_REACTOR_VAR;

	reactor->isRunning = false;

	uv_close((uv_handle_t *) reactor->uvloop_wakeup, libuv_wakeup_close_cb);
	reactor->uvloop_wakeup = NULL;

	// send wake up event to stop the thread
	PostQueuedCompletionStatus(reactor->ioCompletionPort, 0, (ULONG_PTR) 0, NULL);
	uv_thread_detach(WATCHER);
	pefree(WATCHER, 0);
	WATCHER = NULL;

	// Stop IO completion port
	CloseHandle(reactor->ioCompletionPort);
	reactor->ioCompletionPort = NULL;

	// Stop circular buffer
	circular_buffer_destroy(reactor->pid_queue);
	efree(reactor->pid_queue);
	reactor->pid_queue = NULL;
}

/* }}} */

/* {{{ libuv_process_event_start */
static bool libuv_process_event_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	async_process_event_t *process = (async_process_event_t *) (event);

	if (process->hJob != NULL) {
		return true;
	}

	DWORD exitCode;
	if (GetExitCodeProcess(process->event.process, &exitCode) && exitCode != STILL_ACTIVE) {
		async_throw_error("Process has already terminated: %d", exitCode);
		return false;
	}

	process->hJob = CreateJobObject(NULL, NULL);

	DWORD error;

	if (AssignProcessToJobObject(process->hJob, process->event.process) == 0) {

		CloseHandle(process->hJob);
		process->hJob = NULL;

		error = GetLastError();
		if (error == ERROR_SUCCESS) {
			return true;
		}

		char *error_msg = php_win32_error_to_msg((HRESULT) error);
		async_throw_error("Failed to assign process to job object: %s", error_msg);
		php_win32_error_msg_free(error_msg);
		return false;
	}

	if (WATCHER == NULL) {
		libuv_start_process_watcher();
	}

	JOBOBJECT_ASSOCIATE_COMPLETION_PORT info = { 0 };
	info.CompletionKey = (PVOID) process;
	info.CompletionPort = LIBUV_REACTOR->ioCompletionPort;

	if (!SetInformationJobObject(process->hJob, JobObjectAssociateCompletionPortInformation, &info, sizeof(info))) {
		CloseHandle(process->hJob);
		process->hJob = NULL;

		error = GetLastError();
		if (error == ERROR_SUCCESS) {
			return true;
		}

		char *error_msg = php_win32_error_to_msg((HRESULT) error);
		async_throw_error("Failed to associate IO completion port with Job for process: %s", error_msg);
		php_win32_error_msg_free(error_msg);
		return false;
	}

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
	LIBUV_REACTOR->countWaitingDescriptors++;
	return true;
}

/* }}} */

/* {{{ libuv_process_event_stop */
static bool libuv_process_event_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);

	ZEND_ASYNC_EVENT_SET_CLOSED(event);
	async_process_event_t *process = (async_process_event_t *) event;
	event->loop_ref_count = 0;

	if (process->hJob != NULL) {
		CloseHandle(process->hJob);
		process->hJob = NULL;
	}

	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

#else
// Unix process handle
static bool libuv_process_event_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	async_process_event_t *process = (async_process_event_t *) (event);
	pid_t pid = (pid_t) process->event.process;

	// Add handler first to guarantee we catch SIGCHLD
	libuv_add_process_event(event);

	// Check if process already terminated (zombie state)
	int exit_status;
	pid_t result = waitpid(pid, &exit_status, WNOHANG);

	if (result == pid) {
		// Process already terminated, got exit code
		if (WIFEXITED(exit_status)) {
			process->event.exit_code = WEXITSTATUS(exit_status);
		} else if (WIFSIGNALED(exit_status)) {
			process->event.exit_code = -WTERMSIG(exit_status);
		} else {
			process->event.exit_code = -1;
		}

		event->stop(event);
		ZEND_ASYNC_CALLBACKS_NOTIFY(&process->event.base, NULL, NULL);
		return true;
	} else if (result == 0) {
		// Process still running, wait for SIGCHLD
		event->loop_ref_count = 1;
		ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
		return true;
	} else {
		// Error: process doesn't exist or already reaped
		libuv_remove_process_event(event);
		zend_object *exception = async_new_exception(
				async_ce_async_exception, "Failed to monitor process %d: %s", (int) pid, strerror(errno));
		ZEND_ASYNC_CALLBACKS_NOTIFY(&process->event.base, NULL, exception);
		OBJ_RELEASE(exception);
		return EG(exception) == NULL;
	}
}

static bool libuv_process_event_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);
	ZEND_ASYNC_EVENT_SET_CLOSED(event);

	// Remove from process monitoring
	libuv_remove_process_event(event);

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);
	return true;
}
#endif

/* {{{ libuv_process_event_dispose */
static bool libuv_process_event_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REFCOUNT(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return true;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

#ifdef PHP_WIN32

	async_process_event_t *process = (async_process_event_t *) (event);

	if (process->event.process != NULL) {
		process->event.process = NULL;
	}

	if (process->hJob != NULL) {
		CloseHandle(process->hJob);
		process->hJob = NULL;
	}
#endif

	pefree(event, 0);
	return true;
}

/* }}} */

/* {{{ libuv_new_process_event */
zend_async_process_event_t *libuv_new_process_event(zend_process_t process_handle, size_t extra_size)
{
	START_REACTOR_OR_RETURN_NULL;

	// Use process handle as key for hash lookup
	uintptr_t pid_key = (uintptr_t) process_handle;

	// Initialize process_events if needed
	if (ASYNC_G(process_events) == NULL) {
		ASYNC_G(process_events) = zend_new_array(0);
	}

	// Check if we already have an event for this process
	zend_async_process_event_t *existing_event = zend_hash_index_find_ptr(ASYNC_G(process_events), pid_key);
	if (existing_event != NULL) {
		// Found existing event - increment ref count and return it
		ZEND_ASYNC_EVENT_ADD_REF(&existing_event->base);
		return existing_event;
	}

	// Create new event only if one doesn't exist
	async_process_event_t *process_event = pecalloc(
			1, extra_size != 0 ? sizeof(async_process_event_t) + extra_size : sizeof(async_process_event_t), 0);

	process_event->event.process = process_handle;
	process_event->event.base.extra_offset = sizeof(async_process_event_t);
	process_event->event.base.ref_count = 1;

	process_event->event.base.add_callback = libuv_add_callback;
	process_event->event.base.del_callback = libuv_remove_callback;
	process_event->event.base.start = libuv_process_event_start;
	process_event->event.base.stop = libuv_process_event_stop;
	process_event->event.base.dispose = libuv_process_event_dispose;

	if (UNEXPECTED(zend_hash_index_add_ptr(ASYNC_G(process_events), pid_key, &process_event->event) == NULL)) {
		async_throw_error("Failed to store process event");
		return NULL;
	}

	return &process_event->event;
}

/* }}} */

/////////////////////////////////////////////////////////////////////////////////
/// Thread API
/////////////////////////////////////////////////////////////////////////////////

/* {{{ libuv_new_thread_event */
zend_async_thread_event_t *libuv_new_thread_event(zend_async_thread_entry_t entry, void *arg, size_t extra_size)
{
	// TODO: libuv_new_thread_event
	//  We need to design a mechanism for creating a Thread and running a function
	//  in another thread in such a way that it can be awaited like an event.
	//
	return NULL;
}

/* }}} */

/////////////////////////////////////////////////////////////////////////////////
/// File System API
/////////////////////////////////////////////////////////////////////////////////

/* {{{ on_filesystem_event */
static void on_filesystem_event(uv_fs_event_t *handle, const char *filename, int events, int status)
{
	async_filesystem_event_t *fs_event = handle->data;

	// Reset previous triggered filename
	if (fs_event->event.triggered_filename) {
		zend_string_release(fs_event->event.triggered_filename);
		fs_event->event.triggered_filename = NULL;
	}

	fs_event->event.triggered_events = 0;

	if (status < 0) {
		zend_object *exception = async_new_exception(
				async_ce_input_output_exception, "Filesystem monitoring error: %s", uv_strerror(status));
		ZEND_ASYNC_CALLBACKS_NOTIFY(&fs_event->event.base, NULL, exception);
		zend_object_release(exception);
		return;
	}

	fs_event->event.triggered_events = events;
	fs_event->event.triggered_filename = filename ? zend_string_init(filename, strlen(filename), 0) : NULL;

	ZEND_ASYNC_CALLBACKS_NOTIFY(&fs_event->event.base, NULL, NULL);

	IF_EXCEPTION_STOP_REACTOR;
}

/* }}} */

/* {{{ libuv_filesystem_start */
static bool libuv_filesystem_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	async_filesystem_event_t *fs_event = (async_filesystem_event_t *) (event);

	const int error = uv_fs_event_start(
			&fs_event->uv_handle, on_filesystem_event, ZSTR_VAL(fs_event->event.path), fs_event->event.flags);

	if (error < 0) {
		async_throw_error("Failed to start filesystem handle: %s", uv_strerror(error));
		return false;
	}

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_filesystem_stop */
static bool libuv_filesystem_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);

	async_filesystem_event_t *fs_event = (async_filesystem_event_t *) (event);

	const int error = uv_fs_event_stop(&fs_event->uv_handle);

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);

	if (error < 0) {
		async_throw_error("Failed to stop filesystem handle: %s", uv_strerror(error));
		return false;
	}
	return true;
}

/* }}} */

/* {{{ libuv_filesystem_dispose */
static bool libuv_filesystem_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REFCOUNT(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return true;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	async_filesystem_event_t *fs_event = (async_filesystem_event_t *) (event);

	if (fs_event->event.path) {
		zend_string_release(fs_event->event.path);
		fs_event->event.path = NULL;
	}

	if (fs_event->event.triggered_filename) {
		zend_string_release(fs_event->event.triggered_filename);
		fs_event->event.triggered_filename = NULL;
	}

	uv_close((uv_handle_t *) &fs_event->uv_handle, libuv_close_handle_cb);
	return true;
}

/* }}} */

/* {{{ libuv_new_filesystem_event */
zend_async_filesystem_event_t *
libuv_new_filesystem_event(zend_string *path, const unsigned int flags, size_t extra_size)
{
	START_REACTOR_OR_RETURN_NULL;

	async_filesystem_event_t *fs_event = pecalloc(
			1, extra_size != 0 ? sizeof(async_filesystem_event_t) + extra_size : sizeof(async_filesystem_event_t), 0);

	const int error = uv_fs_event_init(UVLOOP, &fs_event->uv_handle);

	if (error < 0) {
		async_throw_error("Failed to initialize filesystem handle: %s", uv_strerror(error));
		pefree(fs_event, 0);
		return NULL;
	}

	fs_event->uv_handle.data = fs_event;
	fs_event->event.path = zend_string_copy(path);
	fs_event->event.flags = flags;
	fs_event->event.base.extra_offset = sizeof(async_filesystem_event_t);
	fs_event->event.base.ref_count = 1;

	fs_event->event.base.add_callback = libuv_add_callback;
	fs_event->event.base.del_callback = libuv_remove_callback;
	fs_event->event.base.start = libuv_filesystem_start;
	fs_event->event.base.stop = libuv_filesystem_stop;
	fs_event->event.base.dispose = libuv_filesystem_dispose;

	return &fs_event->event;
}

/* }}} */

///////////////////////////////////////////////////////////////////////////////////
/// DNS API
///////////////////////////////////////////////////////////////////////////////////

/* {{{ on_nameinfo_event */
static void on_nameinfo_event(uv_getnameinfo_t *req, int status, const char *hostname, const char *service)
{
	async_dns_nameinfo_t *name_info = req->data;
	zend_object *exception = NULL;

	name_info->event.hostname = NULL;
	name_info->event.service = NULL;

	// Events of type nameinfo are triggered only once.
	// After that, the event is automatically closed.
	close_event(&name_info->event.base);

	if (UNEXPECTED(status < 0)) {
		exception = async_new_exception(async_ce_dns_exception, "DNS error: %s", uv_strerror(status));

		ZEND_ASYNC_CALLBACKS_NOTIFY(&name_info->event.base, NULL, exception);

		if (exception != NULL) {
			zend_object_release(exception);
		}

		return;
	}

	// We must copy these strings as zend_string into Zend memory space because they do not belong to us.
	if (hostname != NULL) {
		name_info->event.hostname = zend_string_init(hostname, strlen(hostname), 0);
	}

	if (service != NULL) {
		name_info->event.service = zend_string_init(service, strlen(service), 0);
	}

	ZEND_ASYNC_CALLBACKS_NOTIFY(&name_info->event.base, NULL, NULL);

	IF_EXCEPTION_STOP_REACTOR;
}

/* }}} */

/* {{{ libuv_dns_nameinfo_start */
static bool libuv_dns_nameinfo_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_dns_nameinfo_stop */
static bool libuv_dns_nameinfo_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_dns_nameinfo_dispose */
static bool libuv_dns_nameinfo_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REFCOUNT(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return true;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	zend_async_dns_nameinfo_t *name_info = (zend_async_dns_nameinfo_t *) (event);

	if (name_info->hostname != NULL) {
		zend_string_release(name_info->hostname);
		name_info->hostname = NULL;
	}

	if (name_info->service != NULL) {
		zend_string_release(name_info->service);
		name_info->service = NULL;
	}

	pefree(event, 0);
	return true;
}

/* }}} */

/* {{{ libuv_getnameinfo */
static zend_async_dns_nameinfo_t *libuv_getnameinfo(const struct sockaddr *addr, int flags, size_t extra_size)
{
	START_REACTOR_OR_RETURN_NULL;

	async_dns_nameinfo_t *name_info =
			pecalloc(1, extra_size != 0 ? sizeof(async_dns_nameinfo_t) + extra_size : sizeof(async_dns_nameinfo_t), 0);

	const int error = uv_getnameinfo(UVLOOP, &name_info->uv_handle, on_nameinfo_event, addr, flags);

	if (error < 0) {
		async_rethrow_exception(async_new_exception(
				async_ce_dns_exception, "Failed to initialize getnameinfo handle: %s", uv_strerror(error)));
		pefree(name_info, 0);
		return NULL;
	}

	name_info->uv_handle.data = name_info;
	name_info->event.base.extra_offset = sizeof(async_dns_nameinfo_t);
	name_info->event.base.ref_count = 1;

	name_info->event.base.add_callback = libuv_add_callback;
	name_info->event.base.del_callback = libuv_remove_callback;
	name_info->event.base.start = libuv_dns_nameinfo_start;
	name_info->event.base.stop = libuv_dns_nameinfo_stop;
	name_info->event.base.dispose = libuv_dns_nameinfo_dispose;

	return &name_info->event;
}

/* }}} */

/* {{{ on_addrinfo_event */
static void on_addrinfo_event(uv_getaddrinfo_t *req, int status, struct addrinfo *res)
{
	async_dns_addrinfo_t *addr_info = req->data;
	zend_object *exception = NULL;

	// Events of type addrinfo are triggered only once.
	// After that, the event is automatically closed.
	close_event(&addr_info->event.base);

	if (status < 0) {
		exception = async_new_exception(async_ce_dns_exception, "DNS error: %s", uv_strerror(status));
	}

	addr_info->event.result = res;

	ZEND_ASYNC_CALLBACKS_NOTIFY(&addr_info->event.base, NULL, exception);

	if (exception != NULL) {
		OBJ_RELEASE(exception);
	}

	IF_EXCEPTION_STOP_REACTOR;
}

/* }}} */

/* {{{ libuv_dns_getaddrinfo_start */
static bool libuv_dns_getaddrinfo_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_dns_getaddrinfo_stop */
static bool libuv_dns_getaddrinfo_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_dns_getaddrinfo_dispose */
static bool libuv_dns_getaddrinfo_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REFCOUNT(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return true;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	async_dns_addrinfo_t *addr_info = (async_dns_addrinfo_t *) (event);

	// Note: The addrinfo structure is allocated by libuv and should not be freed manually!
	libuv_close_handle_cb((uv_handle_t *) &addr_info->uv_handle);
	return true;
}

/* }}} */

/* {{{ libuv_getaddrinfo */
static zend_async_dns_addrinfo_t *
libuv_getaddrinfo(const char *node, const char *service, const struct addrinfo *hints, size_t extra_size)
{
	START_REACTOR_OR_RETURN_NULL;

	async_dns_addrinfo_t *addr_info =
			pecalloc(1, extra_size != 0 ? sizeof(async_dns_addrinfo_t) + extra_size : sizeof(async_dns_addrinfo_t), 0);

	const int error = uv_getaddrinfo(UVLOOP, &addr_info->uv_handle, on_addrinfo_event, node, service, hints);

	if (error < 0) {
		async_rethrow_exception(async_new_exception(
				async_ce_dns_exception, "Failed to initialize getaddrinfo handle: %s", uv_strerror(error)));

		pefree(addr_info, 0);
		return NULL;
	}

	addr_info->uv_handle.data = addr_info;
	addr_info->event.base.extra_offset = sizeof(async_dns_addrinfo_t);
	addr_info->event.base.ref_count = 1;

	addr_info->event.base.add_callback = libuv_add_callback;
	addr_info->event.base.del_callback = libuv_remove_callback;
	addr_info->event.base.start = libuv_dns_getaddrinfo_start;
	addr_info->event.base.stop = libuv_dns_getaddrinfo_stop;
	addr_info->event.base.dispose = libuv_dns_getaddrinfo_dispose;

	return &addr_info->event;
}

/* }}} */

/* {{{ libuv_freeaddrinfo */
static bool libuv_freeaddrinfo(struct addrinfo *ai)
{
	if (ai != NULL) {
		uv_freeaddrinfo(ai);
	}

	return true;
}

/* }}} */

////////////////////////////////////////////////////////////////////////////////////
/// Exec API
///////////////////////////////////////////////////////////////////////////////////

/* {{{ exec_on_exit */
static void exec_on_exit(uv_process_t *process, const int64_t exit_status, int term_signal)
{
	async_exec_event_t *exec = process->data;
	exec->event.exit_code = exit_status;
	exec->event.term_signal = term_signal;

	process->data = exec->process;
	exec->process = NULL;

	uv_close((uv_handle_t *) process, libuv_close_handle_cb);

	if (exec->event.terminated != true) {
		exec->event.terminated = true;
		ZEND_ASYNC_DECREASE_EVENT_COUNT(&exec->event.base);
		ZEND_ASYNC_CALLBACKS_NOTIFY(&exec->event.base, NULL, NULL);
	}
}

//* }}} */

static void exec_alloc_cb(uv_handle_t *handle, size_t suggested_size, uv_buf_t *buf)
{
	async_exec_event_t *event = handle->data;
	zend_async_exec_event_t *exec = &event->event;

	if (exec->output_len == 0) {
		exec->output_len = suggested_size;
		exec->output_buffer = emalloc(suggested_size);
	} else if (exec->output_len < suggested_size) {
		exec->output_len = suggested_size;
		exec->output_buffer = erealloc(exec->output_buffer, suggested_size);
	}

	buf->base = exec->output_buffer;
	buf->len = exec->output_len;
}

static void exec_read_cb(uv_stream_t *stream, ssize_t nread, const uv_buf_t *buf)
{
	async_exec_event_t *event = stream->data;
	zend_async_exec_event_t *exec = &event->event;

	if (nread > 0) {
		switch (exec->exec_mode) {
			case ZEND_ASYNC_EXEC_MODE_EXEC: // exec - save only last line
				zval_ptr_dtor(exec->return_value);
				ZVAL_STR(exec->return_value, zend_string_init(buf->base, nread, 0));
				break;

			case ZEND_ASYNC_EXEC_MODE_SYSTEM: // system - output all lines and save last
				PHPWRITE(buf->base, nread);
				zval_ptr_dtor(exec->return_value);
				ZVAL_STR(exec->return_value, zend_string_init(buf->base, nread, 0));
				break;

			case ZEND_ASYNC_EXEC_MODE_EXEC_ARRAY: // exec - save all lines to array
				if (Z_TYPE_P(exec->result_buffer) == IS_ARRAY) {
					add_next_index_stringl(exec->result_buffer, buf->base, nread);
				}
				break;

			case ZEND_ASYNC_EXEC_MODE_PASSTHRU: // passthru - output binary
				PHPWRITE(buf->base, nread);
				break;

			case ZEND_ASYNC_EXEC_MODE_SHELL_EXEC: // shell - output all lines

				if (Z_TYPE_P(exec->result_buffer) != IS_STRING) {
					ZVAL_NEW_STR(exec->result_buffer, zend_string_init(buf->base, nread, 0));
				} else {
					zend_string *string = Z_STR_P(exec->result_buffer);
					string = zend_string_extend(string, ZSTR_LEN(string) + nread, 0);
					memcpy(ZSTR_VAL(string) + ZSTR_LEN(string) - nread, buf->base, nread);
					ZVAL_STR(exec->result_buffer, string);
				}

				break;

			default:
				php_error_docref(NULL, E_WARNING, "Unknown exec type: %d", exec->exec_mode);
		}
	} else if (nread < 0) {
		if (nread != UV_EOF) {
			php_error_docref(NULL, E_WARNING, "Process pipe read error: %s", uv_strerror((int) nread));
		}

		event->stdout_pipe->data = NULL;
		event->stdout_pipe = NULL;

		if (exec->output_len > 0) {
			efree(exec->output_buffer);
			exec->output_len = 0;
			exec->output_buffer = NULL;
		}

		uv_read_stop(stream);
		// For libuv_close_handle_cb to work correctly.
		stream->data = stream;
		uv_close((uv_handle_t *) stream, libuv_close_handle_cb);

		if (exec->terminated != true) {
			exec->terminated = true;
			ZEND_ASYNC_DECREASE_EVENT_COUNT(&event->event.base);
			ZEND_ASYNC_CALLBACKS_NOTIFY(&event->event.base, NULL, NULL);
		}
	}
}

static void exec_std_err_alloc_cb(uv_handle_t *handle, size_t suggested_size, uv_buf_t *buf)
{
	buf->base = emalloc(suggested_size);
	buf->len = suggested_size;
}

static void exec_std_err_read_cb(uv_stream_t *stream, ssize_t nread, const uv_buf_t *buf)
{
	async_exec_event_t *event = stream->data;
	zend_async_exec_event_t *exec = &event->event;

	if (nread > 0) {

		if (exec->std_error != NULL) {
			if (Z_TYPE_P(exec->std_error) != IS_STRING) {
				ZVAL_NEW_STR(exec->std_error, zend_string_init(buf->base, nread, 0));
			} else {
				zend_string *string = Z_STR_P(exec->std_error);
				string = zend_string_extend(string, ZSTR_LEN(string) + nread, 0);
				memcpy(ZSTR_VAL(string) + ZSTR_LEN(string) - nread, buf->base, nread);
				ZVAL_STR(exec->std_error, string);
			}
		}

	} else if (nread < 0) {

		event->stderr_pipe->data = NULL;
		event->stderr_pipe = NULL;

		uv_read_stop(stream);
		stream->data = stream;
		uv_close((uv_handle_t *) stream, libuv_close_handle_cb);
	}

	efree(buf->base);
}

/* }}} */

/* {{{ libuv_exec_start */
static bool libuv_exec_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	async_exec_event_t *exec = (async_exec_event_t *) (event);

	if (exec->process == NULL) {
		return true;
	}

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_exec_stop */
static bool libuv_exec_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);

	async_exec_event_t *exec = (async_exec_event_t *) (event);

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);

	if (exec->process != NULL) {
		uv_process_kill(exec->process, ZEND_ASYNC_SIGTERM);
	}
	return true;
}

/* }}} */

/* {{{ libuv_exec_dispose */
static bool libuv_exec_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REFCOUNT(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return true;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	async_exec_event_t *exec = (async_exec_event_t *) (event);

	if (exec->event.output_buffer != NULL) {
		efree(exec->event.output_buffer);
		exec->event.output_buffer = NULL;
		exec->event.output_len = 0;
	}

	if (exec->process != NULL && !uv_is_closing((uv_handle_t *) exec->process)) {
		uv_process_kill(exec->process, ZEND_ASYNC_SIGTERM);
		uv_handle_t *handle = (uv_handle_t *) exec->process;
		exec->process = NULL;
		// For libuv_close_handle_cb to work correctly.
		handle->data = handle;
		uv_close(handle, libuv_close_handle_cb);
	}

	if (exec->stdout_pipe != NULL && !uv_is_closing((uv_handle_t *) exec->stdout_pipe)) {
		uv_read_stop((uv_stream_t *) exec->stdout_pipe);
		uv_handle_t *handle = (uv_handle_t *) exec->stdout_pipe;
		exec->stdout_pipe->data = NULL;
		handle->data = handle;
		uv_close(handle, libuv_close_handle_cb);
	}

	if (exec->stderr_pipe != NULL && !uv_is_closing((uv_handle_t *) exec->stderr_pipe)) {
		uv_read_stop((uv_stream_t *) exec->stderr_pipe);
		uv_handle_t *handle = (uv_handle_t *) exec->stderr_pipe;
		exec->stderr_pipe->data = NULL;
		handle->data = handle;
		uv_close(handle, libuv_close_handle_cb);
	}

#ifdef PHP_WIN32
	if (exec->quoted_cmd != NULL) {
		efree(exec->quoted_cmd);
		exec->quoted_cmd = NULL;
	}
#endif

	// Free the event itself
	pefree(event, 0);
	return true;
}

/* }}} */

/* {{{ libuv_new_exec_event */
static zend_async_exec_event_t *libuv_new_exec_event(zend_async_exec_mode exec_mode,
													 const char *cmd,
													 zval *return_buffer,
													 zval *return_value,
													 zval *std_error,
													 const char *cwd,
													 const char *env,
													 size_t size)
{
	START_REACTOR_OR_RETURN_NULL;

	async_exec_event_t *exec = pecalloc(1, size != 0 ? size : sizeof(async_exec_event_t), 0);
	zend_async_exec_event_t *base = &exec->event;
	uv_process_options_t *options = &exec->options;

	if (exec == NULL || EG(exception)) {
		return NULL;
	}

	base->exec_mode = exec_mode;
	base->cmd = (char *) cmd;
	base->return_value = return_value;
	base->result_buffer = return_buffer;
	base->std_error = std_error;

	exec->process = pecalloc(sizeof(uv_process_t), 1, 0);
	exec->stdout_pipe = pecalloc(sizeof(uv_pipe_t), 1, 0);
	exec->stderr_pipe = pecalloc(sizeof(uv_pipe_t), 1, 0);

	exec->process->data = exec;
	exec->stdout_pipe->data = exec;
	exec->stderr_pipe->data = exec;

	uv_pipe_init(UVLOOP, exec->stdout_pipe, 0);
	uv_pipe_init(UVLOOP, exec->stderr_pipe, 0);

	options->exit_cb = exec_on_exit;
#ifdef PHP_WIN32
	options->flags = UV_PROCESS_WINDOWS_VERBATIM_ARGUMENTS;
	options->file = "cmd.exe";
	size_t cmd_buffer_size = strlen(cmd) + 2;
	exec->quoted_cmd = emalloc(cmd_buffer_size);
	snprintf(exec->quoted_cmd, cmd_buffer_size, "\"%s\"", cmd);
	options->args = (char *[]){ "cmd.exe", "/s", "/c", exec->quoted_cmd, NULL };
#else
	options->file = "/bin/sh";
	options->args = (char *[]){ "sh", "-c", (char *) cmd, NULL };
#endif

	options->stdio = (uv_stdio_container_t[]){
		{ .flags = UV_IGNORE, .data = { .stream = NULL } },
		{ .data.stream = (uv_stream_t *) exec->stdout_pipe, .flags = UV_CREATE_PIPE | UV_WRITABLE_PIPE },
		{ .data.stream = (uv_stream_t *) exec->stderr_pipe, .flags = UV_CREATE_PIPE | UV_WRITABLE_PIPE }
	};

	options->stdio_count = 3;

	if (cwd != NULL && cwd[0] != '\0') {
		options->cwd = cwd;
	}

	if (env != NULL) {
		options->env = (char **) env;
	}

	const int result = uv_spawn(UVLOOP, exec->process, options);

	if (result) {
		php_error_docref(NULL, E_WARNING, "Failed to spawn process: %s", uv_strerror(result));
		uv_close((uv_handle_t *) exec->stdout_pipe, libuv_close_handle_cb);
		uv_close((uv_handle_t *) exec->process, libuv_close_handle_cb);
		exec->process = NULL;
		exec->stdout_pipe = NULL;
		return NULL;
	}

	uv_read_start((uv_stream_t *) exec->stdout_pipe, exec_alloc_cb, exec_read_cb);
	uv_read_start((uv_stream_t *) exec->stderr_pipe, exec_std_err_alloc_cb, exec_std_err_read_cb);

	ZEND_ASYNC_INCREASE_EVENT_COUNT(&exec->event.base);

	exec->event.base.ref_count = 1;

	exec->event.base.add_callback = libuv_add_callback;
	exec->event.base.del_callback = libuv_remove_callback;
	exec->event.base.start = libuv_exec_start;
	exec->event.base.stop = libuv_exec_stop;
	exec->event.base.dispose = libuv_exec_dispose;

	return &exec->event;
}

/* {{{ libuv_exec */
static int libuv_exec(zend_async_exec_mode exec_mode,
					  const char *cmd,
					  zval *return_buffer,
					  zval *return_value,
					  zval *std_error,
					  const char *cwd,
					  const char *env,
					  const zend_ulong timeout)
{
	zval tmp_return_value, tmp_return_buffer;

	ZVAL_UNDEF(&tmp_return_value);
	ZVAL_UNDEF(&tmp_return_buffer);

	if (return_value != NULL) {
		ZVAL_BOOL(return_value, false);
	}

	zend_coroutine_t *coroutine = ZEND_ASYNC_CURRENT_COROUTINE;

	if (UNEXPECTED(coroutine == NULL)) {
		zend_throw_error(NULL, "Cannot call async_exec() outside of an async context");
		return -1;
	}

	zend_async_exec_event_t *exec_event =
			ZEND_ASYNC_NEW_EXEC_EVENT(exec_mode,
									  cmd,
									  return_buffer != NULL ? return_buffer : &tmp_return_buffer,
									  return_value != NULL ? return_value : &tmp_return_value,
									  std_error,
									  cwd,
									  env);

	if (UNEXPECTED(EG(exception))) {
		return -1;
	}

	zend_async_waker_new(coroutine);
	if (UNEXPECTED(EG(exception))) {
		return -1;
	}

	zend_async_resume_when(coroutine, &exec_event->base, false, zend_async_waker_callback_resolve, NULL);
	if (UNEXPECTED(EG(exception))) {
		return -1;
	}

	ZEND_ASYNC_SUSPEND();
	zend_async_waker_clean(coroutine);

	if (UNEXPECTED(EG(exception))) {
		return -1;
	}

	zval_ptr_dtor(&tmp_return_value);
	zval_ptr_dtor(&tmp_return_buffer);

	int exit_code = (int) exec_event->exit_code;
	exec_event->base.dispose(&exec_event->base);

	return exit_code;
}

/* }}} */

/////////////////////////////////////////////////////////////////////////////////
/// Trigger Event API
/////////////////////////////////////////////////////////////////////////////////

/* {{{ on_trigger_event */
static void on_trigger_event(uv_async_t *handle)
{
	async_trigger_event_t *trigger = handle->data;

	ZEND_ASYNC_CALLBACKS_NOTIFY(&trigger->event.base, NULL, NULL);

	IF_EXCEPTION_STOP_REACTOR;
}

/* }}} */

/* {{{ libuv_trigger_event_trigger */
static void libuv_trigger_event_trigger(zend_async_trigger_event_t *event)
{
	async_trigger_event_t *trigger = (async_trigger_event_t *) event;

	if (!ZEND_ASYNC_EVENT_IS_CLOSED(&trigger->event.base)) {
		uv_async_send(&trigger->uv_handle);
	}
}

/* }}} */

/* {{{ libuv_trigger_event_start */
static bool libuv_trigger_event_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_trigger_event_stop */
static bool libuv_trigger_event_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_trigger_event_dispose */
static bool libuv_trigger_event_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REFCOUNT(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return true;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	async_trigger_event_t *trigger = (async_trigger_event_t *) (event);

	uv_close((uv_handle_t *) &trigger->uv_handle, libuv_close_handle_cb);
	return true;
}

/* }}} */

/* {{{ libuv_new_trigger_event */
zend_async_trigger_event_t *libuv_new_trigger_event(size_t extra_size)
{
	START_REACTOR_OR_RETURN_NULL;

	async_trigger_event_t *trigger = pecalloc(
			1, extra_size != 0 ? sizeof(async_trigger_event_t) + extra_size : sizeof(async_trigger_event_t), 0);

	int error = uv_async_init(UVLOOP, &trigger->uv_handle, on_trigger_event);

	if (error < 0) {
		async_throw_error("Failed to initialize trigger handle: %s", uv_strerror(error));
		pefree(trigger, 0);
		return NULL;
	}

	// Link the handle to the trigger event
	trigger->uv_handle.data = trigger;
	trigger->event.base.extra_offset = sizeof(async_trigger_event_t);
	trigger->event.base.ref_count = 1;

	// Initialize the event methods
	trigger->event.base.add_callback = libuv_add_callback;
	trigger->event.base.del_callback = libuv_remove_callback;
	trigger->event.base.start = libuv_trigger_event_start;
	trigger->event.base.stop = libuv_trigger_event_stop;
	trigger->event.base.dispose = libuv_trigger_event_dispose;

	// Set the trigger method
	trigger->event.trigger = libuv_trigger_event_trigger;

	return &trigger->event;
}

/* }}} */

/////////////////////////////////////////////////////////////////////////////////
/// Socket Listening API
/////////////////////////////////////////////////////////////////////////////////

typedef struct
{
	zend_async_listen_event_t event;
	uv_tcp_t uv_handle;
} async_listen_event_t;

/* {{{ on_connection_event */
static void on_connection_event(uv_stream_t *server, int status)
{
	async_listen_event_t *listen_event = server->data;
#ifdef PHP_WIN32
	zend_socket_t client_socket = INVALID_SOCKET;
#else
	zend_socket_t client_socket = -1;
#endif
	zend_object *exception = NULL;

	if (status < 0) {
		exception = async_new_exception(
				async_ce_input_output_exception, "Connection accept error: %s", uv_strerror(status));
	} else {
		uv_tcp_t client;
		int result = uv_tcp_init(UVLOOP, &client);

		if (result == 0) {
			result = uv_accept(server, (uv_stream_t *) &client);
			if (result == 0) {
				uv_os_fd_t fd;
				result = uv_fileno((uv_handle_t *) &client, &fd);
				if (result == 0) {
					client_socket = (zend_socket_t) fd;
				}
			}
		}

		if (result < 0) {
			exception = async_new_exception(
					async_ce_input_output_exception, "Failed to accept connection: %s", uv_strerror(result));
			uv_close((uv_handle_t *) &client, NULL);
		}
	}

	ZEND_ASYNC_CALLBACKS_NOTIFY(&listen_event->event.base, &client_socket, exception);

	if (exception != NULL) {
		zend_object_release(exception);
	}

	IF_EXCEPTION_STOP_REACTOR;
}

/* }}} */

/* {{{ libuv_listen_start */
static bool libuv_listen_start(zend_async_event_t *event)
{
	EVENT_START_PROLOGUE(event);

	async_listen_event_t *listen_event = (async_listen_event_t *) (event);

	const int error =
			uv_listen((uv_stream_t *) &listen_event->uv_handle, listen_event->event.backlog, on_connection_event);

	if (error < 0) {
		async_throw_error("Failed to start listening: %s", uv_strerror(error));
		return false;
	}

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_listen_stop */
static bool libuv_listen_stop(zend_async_event_t *event)
{
	EVENT_STOP_PROLOGUE(event);

	// uv_listen doesn't have a stop function, we close the handle
	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT(event);
	return true;
}

/* }}} */

/* {{{ libuv_listen_dispose */
static bool libuv_listen_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REFCOUNT(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return true;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	async_listen_event_t *listen_event = (async_listen_event_t *) (event);

	if (listen_event->event.host) {
		efree((void *) listen_event->event.host);
		listen_event->event.host = NULL;
	}

	uv_close((uv_handle_t *) &listen_event->uv_handle, libuv_close_handle_cb);
	return true;
}

/* }}} */

/* {{{ libuv_listen_get_local_address */
static int
libuv_listen_get_local_address(zend_async_listen_event_t *listen_event, char *host, size_t host_len, int *port)
{
	struct sockaddr_storage addr;
	int addr_len = sizeof(addr);

	int result = uv_tcp_getsockname(
			&((async_listen_event_t *) listen_event)->uv_handle, (struct sockaddr *) &addr, &addr_len);

	if (result < 0) {
		return result;
	}

	if (addr.ss_family == AF_INET) {
		struct sockaddr_in *addr_in = (struct sockaddr_in *) &addr;
		*port = ntohs(addr_in->sin_port);
		if (host && host_len > 0) {
			uv_ip4_name(addr_in, host, host_len);
		}
	} else if (addr.ss_family == AF_INET6) {
		struct sockaddr_in6 *addr_in6 = (struct sockaddr_in6 *) &addr;
		*port = ntohs(addr_in6->sin6_port);
		if (host && host_len > 0) {
			uv_ip6_name(addr_in6, host, host_len);
		}
	} else {
		return -1;
	}

	return 0;
}

/* }}} */

/* {{{ libuv_socket_listen */
zend_async_listen_event_t *libuv_socket_listen(const char *host, int port, int backlog, size_t extra_size)
{
	START_REACTOR_OR_RETURN_NULL;

	async_listen_event_t *listen_event =
			pecalloc(1, extra_size != 0 ? sizeof(async_listen_event_t) + extra_size : sizeof(async_listen_event_t), 0);

	int error = uv_tcp_init(UVLOOP, &listen_event->uv_handle);
	if (error < 0) {
		async_throw_error("Failed to initialize TCP handle: %s", uv_strerror(error));
		pefree(listen_event, 0);
		return NULL;
	}

	// Set socket options
	uv_tcp_nodelay(&listen_event->uv_handle, 1);
	uv_tcp_simultaneous_accepts(&listen_event->uv_handle, 1);

	// Bind to address
	struct sockaddr_storage addr;
	if (strchr(host, ':') != NULL) {
		// IPv6
		error = uv_ip6_addr(host, port, (struct sockaddr_in6 *) &addr);
	} else {
		// IPv4
		error = uv_ip4_addr(host, port, (struct sockaddr_in *) &addr);
	}

	if (error < 0) {
		async_throw_error("Failed to parse address %s:%d: %s", host, port, uv_strerror(error));
		uv_close((uv_handle_t *) &listen_event->uv_handle, libuv_close_handle_cb);
		pefree(listen_event, 0);
		return NULL;
	}

	error = uv_tcp_bind(&listen_event->uv_handle, (struct sockaddr *) &addr, 0);
	if (error < 0) {
		async_throw_error("Failed to bind to %s:%d: %s", host, port, uv_strerror(error));
		uv_close((uv_handle_t *) &listen_event->uv_handle, libuv_close_handle_cb);
		pefree(listen_event, 0);
		return NULL;
	}

	// Get actual socket fd
	uv_os_fd_t fd;
	error = uv_fileno((uv_handle_t *) &listen_event->uv_handle, &fd);
	if (error < 0) {
		async_throw_error("Failed to get socket descriptor: %s", uv_strerror(error));
		uv_close((uv_handle_t *) &listen_event->uv_handle, libuv_close_handle_cb);
		pefree(listen_event, 0);
		return NULL;
	}

	// Link the handle to the loop
	listen_event->uv_handle.data = listen_event;
	listen_event->event.host = estrdup(host);
	listen_event->event.port = port;
	listen_event->event.backlog = backlog;
	listen_event->event.socket_fd = (zend_socket_t) fd;
	listen_event->event.base.extra_offset = sizeof(async_listen_event_t);
	listen_event->event.base.ref_count = 1;

	// Initialize the event methods
	listen_event->event.base.add_callback = libuv_add_callback;
	listen_event->event.base.del_callback = libuv_remove_callback;
	listen_event->event.base.start = libuv_listen_start;
	listen_event->event.base.stop = libuv_listen_stop;
	listen_event->event.base.dispose = libuv_listen_dispose;
	listen_event->event.get_local_address = libuv_listen_get_local_address;

	return &listen_event->event;
}

/* }}} */

void async_libuv_reactor_register(void)
{
	zend_async_reactor_register(LIBUV_REACTOR_NAME,
								false,
								libuv_reactor_startup,
								libuv_reactor_shutdown,
								libuv_reactor_execute,
								libuv_reactor_loop_alive,
								libuv_new_socket_event,
								libuv_new_poll_event,
								libuv_new_poll_proxy_event,
								libuv_new_timer_event,
								libuv_new_signal_event,
								libuv_new_process_event,
								libuv_new_thread_event,
								libuv_new_filesystem_event,
								libuv_getnameinfo,
								libuv_getaddrinfo,
								libuv_freeaddrinfo,
								libuv_new_exec_event,
								libuv_exec,
								libuv_new_trigger_event);

	zend_async_socket_listening_register(LIBUV_REACTOR_NAME, false, libuv_socket_listen);
}
