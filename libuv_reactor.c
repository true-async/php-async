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

typedef struct
{
	uv_loop_t loop;
#ifdef PHP_WIN32
	uv_thread_t * watcherThread;
	HANDLE ioCompletionPort;
	unsigned int countWaitingDescriptors;
	bool isRunning;
	uv_async_t * uvloop_wakeup;
	/* Circular buffer of libuv_process_t ptr */
	circular_buffer_t * pid_queue;
#endif
} libuv_reactor_t;

static void libuv_reactor_stop_with_exception(void);

#define UVLOOP ((uv_loop_t *) ASYNC_G(reactor))
#define LIBUV_REACTOR ((libuv_reactor_t *) ASYNC_G(reactor))
#define WATCHER ((libuv_reactor_t *) ASYNC_G(reactor))->watcherThread
#define IF_EXCEPTION_STOP_REACTOR if (UNEXPECTED(EG(exception) != NULL)) { libuv_reactor_stop_with_exception(); }

/* {{{ libuv_reactor_startup */
void libuv_reactor_startup(void)
{
	if (ASYNC_G(reactor) != NULL) {
		return;
	}

	ASYNC_G(reactor) = pecalloc(1, sizeof(libuv_reactor_t), 1);
	const int result = uv_loop_init(ASYNC_G(reactor));

	if (result != 0) {
		async_throw_error("Failed to initialize loop: %s", uv_strerror(result));
		return;
	}

	uv_loop_set_data(ASYNC_G(reactor), ASYNC_G(reactor));
}
/* }}} */

/* {{{ libuv_reactor_stop_with_exception */
static void libuv_reactor_stop_with_exception(void)
{
	// TODO: implement libuv_reactor_stop_with_exception
}
/* }}} */

/* {{{ libuv_reactor_shutdown */
void libuv_reactor_shutdown(void)
{
	if (EXPECTED(ASYNC_G(reactor) != NULL)) {

		if (uv_loop_alive(UVLOOP) != 0) {
			// need to finish handlers
			uv_run(UVLOOP, UV_RUN_ONCE);
		}

		uv_loop_close(ASYNC_G(reactor));
		pefree(ASYNC_G(reactor), 1);
		ASYNC_G(reactor) = NULL;
	}
}
/* }}} */

/* {{{ libuv_reactor_execute */
bool libuv_reactor_execute(bool no_wait)
{
	const bool has_handles = uv_run(UVLOOP, no_wait ? UV_RUN_NOWAIT : UV_RUN_ONCE);

	if (UNEXPECTED(has_handles == false && ZEND_ASYNC_ACTIVE_EVENT_COUNT > 0)) {
		async_warning("event_handle_count %d is greater than 0 but no handles are available", ZEND_ASYNC_ACTIVE_EVENT_COUNT);
		return false;
	}

	return ZEND_ASYNC_ACTIVE_EVENT_COUNT > 0 && has_handles;
}
/* }}} */

/* {{{ libuv_reactor_loop_alive */
bool libuv_reactor_loop_alive(void)
{
	if (UVLOOP == NULL) {
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

/* {{{ libuv_add_callback */
static void libuv_add_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	zend_async_callbacks_push(event, callback);
}
/* }}} */

/* {{{ libuv_remove_callback */
static void libuv_remove_callback(zend_async_event_t *event, zend_async_event_callback_t *callback)
{
	zend_async_callbacks_remove(event, callback);
}
/* }}} */

/////////////////////////////////////////////////////////////////////////////
/// Poll API
//////////////////////////////////////////////////////////////////////////////

/* {{{ on_poll_event */
static void on_poll_event(uv_poll_t* handle, int status, int events)
{
	async_poll_event_t *poll = handle->data;
	zend_object *exception = NULL;

	if (status < 0) {
		exception = async_new_exception(
			async_ce_input_output_exception, "Input output error: %s", uv_strerror(status)
		);
	}

	poll->event.triggered_events = events;

	zend_async_callbacks_notify(&poll->event.base, NULL, exception);

	if (exception != NULL) {
		zend_object_release(exception);
	}

	IF_EXCEPTION_STOP_REACTOR;
}
/* }}} */

/* {{{ libuv_poll_start */
static void libuv_poll_start(zend_async_event_t *event)
{
	if (event->loop_ref_count > 0) {
		event->loop_ref_count++;
		return;
	}

    async_poll_event_t *poll = (async_poll_event_t *)(event);

    const int error = uv_poll_start(&poll->uv_handle, poll->event.events, on_poll_event);

    if (error < 0) {
        async_throw_error("Failed to start poll handle: %s", uv_strerror(error));
    	return;
    }

	event->loop_ref_count++;
    ZEND_ASYNC_INCREASE_EVENT_COUNT;
}
/* }}} */

/* {{{ libuv_poll_stop */
static void libuv_poll_stop(zend_async_event_t *event)
{
	if (event->loop_ref_count > 1) {
		event->loop_ref_count--;
		return;
	}

	async_poll_event_t *poll = (async_poll_event_t *)(event);

	const int error = uv_poll_stop(&poll->uv_handle);

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT;

	if (error < 0) {
		async_throw_error("Failed to stop poll handle: %s", uv_strerror(error));
		return;
	}
}
/* }}} */

/* {{{ libuv_poll_dispose */
static void libuv_poll_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REF(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	async_poll_event_t *poll = (async_poll_event_t *)(event);

	uv_close((uv_handle_t *)&poll->uv_handle, libuv_close_handle_cb);

	pefree(event, 0);
}
/* }}} */

/* {{{ libuv_new_poll_event */
zend_async_poll_event_t* libuv_new_poll_event(
	zend_file_descriptor_t fh, zend_socket_t socket, async_poll_event events, size_t size
)
{
	async_poll_event_t *poll = pecalloc(1, size != 0 ? size : sizeof(async_poll_event_t), 0);

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
zend_async_poll_event_t* libuv_new_socket_event(zend_socket_t socket, async_poll_event events, size_t size)
{
	return libuv_new_poll_event(ZEND_FD_NULL, socket, events, size);
}
/* }}} */

/////////////////////////////////////////////////////////////////////////////////
/// Timer API
/////////////////////////////////////////////////////////////////////////////////

/* {{{ on_timer_event */
static void on_timer_event(uv_timer_t *handle)
{
	async_timer_event_t *poll = handle->data;

	zend_async_callbacks_notify(&poll->event.base, NULL, NULL);

	IF_EXCEPTION_STOP_REACTOR;
}
/* }}} */

/* {{{ libuv_timer_start */
static void libuv_timer_start(zend_async_event_t *event)
{
	if (event->loop_ref_count > 0) {
		event->loop_ref_count++;
		return;
	}

	async_timer_event_t *timer = (async_timer_event_t *)(event);

	const int error = uv_timer_start(
		&timer->uv_handle,
		on_timer_event,
		timer->event.timeout,
		timer->event.is_periodic ? timer->event.timeout : 0
	);

	if (error < 0) {
		async_throw_error("Failed to start timer handle: %s", uv_strerror(error));
		return;
	}

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT;
}
/* }}} */

/* {{{ libuv_timer_stop */
static void libuv_timer_stop(zend_async_event_t *event)
{
	if (event->loop_ref_count > 1) {
		event->loop_ref_count--;
		return;
	}

	async_timer_event_t *timer = (async_timer_event_t *)(event);

	const int error = uv_timer_stop(&timer->uv_handle);

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT;

	if (error < 0) {
		async_throw_error("Failed to stop timer handle: %s", uv_strerror(error));
		return;
	}
}
/* }}} */

/* {{{ libuv_timer_dispose */
static void libuv_timer_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REF(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	async_timer_event_t *timer = (async_timer_event_t *)(event);

	uv_close((uv_handle_t *)&timer->uv_handle, libuv_close_handle_cb);

	pefree(event, 0);
}
/* }}} */

/* {{{ libuv_new_timer_event */
zend_async_timer_event_t* libuv_new_timer_event(const zend_ulong timeout, const bool is_periodic, size_t size)
{
	async_timer_event_t *event = pecalloc(1, size != 0 ? size : sizeof(async_timer_event_t), 0);

	const int error = uv_timer_init(UVLOOP, &event->uv_handle);

	if (error < 0) {
		async_throw_error("Failed to initialize timer handle: %s", uv_strerror(error));
		pefree(event, 0);
		return NULL;
	}

	event->uv_handle.data = event;
	event->event.timeout = timeout;
	event->event.is_periodic = is_periodic;

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

/* {{{ on_signal_event */
static void on_signal_event(uv_signal_t *handle, int signum)
{
    async_signal_event_t *signal = handle->data;

    zend_async_callbacks_notify(&signal->event.base, &signum, NULL);

    IF_EXCEPTION_STOP_REACTOR;
}
/* }}} */

/* {{{ libuv_signal_start */
static void libuv_signal_start(zend_async_event_t *event)
{
    if (event->loop_ref_count > 0) {
        event->loop_ref_count++;
        return;
    }

    async_signal_event_t *signal = (async_signal_event_t *)(event);

    const int error = uv_signal_start(
        &signal->uv_handle,
        on_signal_event,
        signal->event.signal
    );

    if (error < 0) {
        async_throw_error("Failed to start signal handle: %s", uv_strerror(error));
        return;
    }

    event->loop_ref_count++;
    ZEND_ASYNC_INCREASE_EVENT_COUNT;
}
/* }}} */

/* {{{ libuv_signal_stop */
static void libuv_signal_stop(zend_async_event_t *event)
{
    if (event->loop_ref_count > 1) {
        event->loop_ref_count--;
        return;
    }

    async_signal_event_t *signal = (async_signal_event_t *)(event);

    const int error = uv_signal_stop(&signal->uv_handle);

    event->loop_ref_count = 0;
    ZEND_ASYNC_DECREASE_EVENT_COUNT;

    if (error < 0) {
        async_throw_error("Failed to stop signal handle: %s", uv_strerror(error));
        return;
    }
}
/* }}} */

/* {{{ libuv_signal_dispose */
static void libuv_signal_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REF(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return;
	}

    if (event->loop_ref_count > 0) {
        event->loop_ref_count = 1;
    	event->stop(event);
    }

    zend_async_callbacks_free(event);

    async_signal_event_t *signal = (async_signal_event_t *)(event);

    uv_close((uv_handle_t *)&signal->uv_handle, libuv_close_handle_cb);

    pefree(event, 0);
}
/* }}} */

/* {{{ libuv_new_signal_event */
zend_async_signal_event_t* libuv_new_signal_event(int signum, size_t size)
{
    async_signal_event_t *signal = pecalloc(1, size != 0 ? size : sizeof(async_signal_event_t), 0);

    const int error = uv_signal_init(UVLOOP, &signal->uv_handle);

    if (error < 0) {
        async_throw_error("Failed to initialize signal handle: %s", uv_strerror(error));
        pefree(signal, 0);
        return NULL;
    }

    signal->uv_handle.data = signal;
    signal->event.signal = signum;

    signal->event.base.add_callback = libuv_add_callback;
    signal->event.base.del_callback = libuv_remove_callback;
    signal->event.base.start = libuv_signal_start;
    signal->event.base.stop = libuv_signal_stop;
    signal->event.base.dispose = libuv_signal_dispose;

    return &signal->event;
}
/* }}} */

/////////////////////////////////////////////////////////////////////////////////
/// Process API
/////////////////////////////////////////////////////////////////////////////////

#ifdef PHP_WIN32
static void process_watcher_thread(void * args)
{
	libuv_reactor_t *reactor = (libuv_reactor_t *) args;

	ULONG_PTR completionKey;

	while (reactor->isRunning && reactor->ioCompletionPort != NULL) {

		DWORD lpNumberOfBytesTransferred;
		//OVERLAPPED overlapped = {0};
		LPOVERLAPPED lpOverlapped = NULL;

		if (false == GetQueuedCompletionStatus(
			reactor->ioCompletionPort, &lpNumberOfBytesTransferred, &completionKey, &lpOverlapped, INFINITE)
			)
		{
			break;
		}

		if (completionKey == 0) {
			continue;
		}

		if (reactor->isRunning == false) {
            break;
        }

		async_process_event_t * process_event = (async_process_event_t *) completionKey;

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
	libuv_reactor_t * reactor = LIBUV_REACTOR;

	if (reactor->pid_queue == NULL || circular_buffer_is_empty(reactor->pid_queue)) {
		return;
	}

	async_process_event_t * process_event;

	while (reactor->pid_queue && circular_buffer_is_not_empty(reactor->pid_queue)) {
		circular_buffer_pop(reactor->pid_queue, &process_event);

		DWORD exit_code;
		GetExitCodeProcess(process_event->hProcess, &exit_code);

		process_event->event.exit_code = exit_code;

		if (reactor->countWaitingDescriptors > 0) {
			reactor->countWaitingDescriptors--;
			ZEND_ASYNC_DECREASE_EVENT_COUNT;

			if (reactor->countWaitingDescriptors == 0) {
				libuv_stop_process_watcher();
			}
        }

		zend_async_callbacks_notify(&process_event->event.base, &exit_code, NULL);
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

	libuv_reactor_t * reactor = LIBUV_REACTOR;

	// Create IoCompletionPort
	reactor->ioCompletionPort = CreateIoCompletionPort(
		INVALID_HANDLE_VALUE, NULL, 0, 1
	);

	if (reactor->ioCompletionPort == NULL) {
		char * error_msg = php_win32_error_to_msg((HRESULT) GetLastError());
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

	libuv_reactor_t * reactor = LIBUV_REACTOR;

	reactor->isRunning = false;

	uv_close((uv_handle_t *) reactor->uvloop_wakeup, libuv_wakeup_close_cb);
	reactor->uvloop_wakeup = NULL;

	// send wake up event to stop the thread
	PostQueuedCompletionStatus(reactor->ioCompletionPort, 0, (ULONG_PTR)0, NULL);
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
static void libuv_process_event_start(zend_async_event_t *event)
{
	if (event->loop_ref_count > 0) {
		event->loop_ref_count++;
		return;
	}

	async_process_event_t *process = (async_process_event_t *)(event);

	if (process->hJob != NULL) {
		return;
	}

	DWORD exitCode;
	if (GetExitCodeProcess(process->hProcess, &exitCode) && exitCode != STILL_ACTIVE) {
		async_throw_error("Process has already terminated: %d", exitCode);
		return;
	}

	process->hJob = CreateJobObject(NULL, NULL);

	if (AssignProcessToJobObject(process->hJob, process->hProcess) == 0) {
		char * error_msg = php_win32_error_to_msg((HRESULT) GetLastError());
		async_throw_error("Failed to assign process to job object: %s", error_msg);
		php_win32_error_msg_free(error_msg);
		return;
	}

	if (WATCHER == NULL) {
		libuv_start_process_watcher();
	}

	JOBOBJECT_ASSOCIATE_COMPLETION_PORT info = {0};
	info.CompletionKey = (PVOID)process;
	info.CompletionPort = LIBUV_REACTOR->ioCompletionPort;

	if (!SetInformationJobObject(
		process->hJob,
		JobObjectAssociateCompletionPortInformation,
		&info, sizeof(info)
		)
		)
	{
		CloseHandle(process->hJob);
		char * error_msg = php_win32_error_to_msg((HRESULT) GetLastError());
		async_throw_error("Failed to associate IO completion port with Job for process: %s", error_msg);
		php_win32_error_msg_free(error_msg);
	}

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT;
	LIBUV_REACTOR->countWaitingDescriptors++;
}
/* }}} */

/* {{{ libuv_process_event_stop */
static void libuv_process_event_stop(zend_async_event_t *event)
{
	async_process_event_t *process = (async_process_event_t *) event;

	if (process->hJob != NULL) {
		CloseHandle(process->hJob);
		process->hJob = NULL;
	}
}
/* }}} */

#else
// Unix process handle
static void libuv_process_event_start(zend_async_event_t *handle)
{
	//libuv_process_t *process = (libuv_process_t *) handle;

}

static void libuv_process_event_stop(zend_async_event_t *handle)
{
	//libuv_process_t *process = (libuv_process_t *) handle;

}
#endif

/* {{{ libuv_process_event_dispose */
static void libuv_process_event_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REF(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

#ifdef PHP_WIN32

	async_process_event_t *process = (async_process_event_t *)(event);

	if (process->hProcess != NULL) {
		CloseHandle(process->hProcess);
		process->hProcess = NULL;
	}

	if (process->hJob != NULL) {
		CloseHandle(process->hJob);
		process->hJob = NULL;
	}
#endif

	pefree(event, 0);
}
/* }}} */

/* {{{ libuv_new_process_event */
zend_async_process_event_t * libuv_new_process_event(zend_process_t process_handle, size_t size)
{
	async_process_event_t *process_event = pecalloc(1, size != 0 ? size : sizeof(async_process_event_t), 0);
	process_event->event.process = process_handle;

	process_event->event.base.add_callback = libuv_add_callback;
	process_event->event.base.del_callback = libuv_remove_callback;
	process_event->event.base.start = libuv_process_event_start;
	process_event->event.base.stop = libuv_process_event_stop;
	process_event->event.base.dispose = libuv_process_event_dispose;

	return &process_event->event;
}
/* }}} */

/////////////////////////////////////////////////////////////////////////////////
/// Thread API
/////////////////////////////////////////////////////////////////////////////////

/* {{{ libuv_new_thread_event */
zend_async_thread_event_t * libuv_new_thread_event(zend_async_thread_entry_t entry, void *arg, size_t size)
{
    //TODO: libuv_new_thread_event
	// We need to design a mechanism for creating a Thread and running a function
	// in another thread in such a way that it can be awaited like an event.
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
            async_ce_input_output_exception, "Filesystem monitoring error: %s", uv_strerror(status)
        );
        zend_async_callbacks_notify(&fs_event->event.base, NULL, exception);
        zend_object_release(exception);
        return;
    }

    fs_event->event.triggered_events = events;
    fs_event->event.triggered_filename = filename ? zend_string_init(filename, strlen(filename), 0) : NULL;

    zend_async_callbacks_notify(&fs_event->event.base, NULL, NULL);

    IF_EXCEPTION_STOP_REACTOR;
}
/* }}} */

/* {{{ libuv_filesystem_start */
static void libuv_filesystem_start(zend_async_event_t *event)
{
    if (event->loop_ref_count > 0) {
        event->loop_ref_count++;
        return;
    }

    async_filesystem_event_t *fs_event = (async_filesystem_event_t *)(event);

    const int error = uv_fs_event_start(
        &fs_event->uv_handle,
        on_filesystem_event,
        ZSTR_VAL(fs_event->event.path),
        fs_event->event.flags
    );

    if (error < 0) {
        async_throw_error("Failed to start filesystem handle: %s", uv_strerror(error));
        return;
    }

    event->loop_ref_count++;
    ZEND_ASYNC_INCREASE_EVENT_COUNT;
}
/* }}} */

/* {{{ libuv_filesystem_stop */
static void libuv_filesystem_stop(zend_async_event_t *event)
{
    if (event->loop_ref_count > 1) {
        event->loop_ref_count--;
        return;
    }

    async_filesystem_event_t *fs_event = (async_filesystem_event_t *)(event);

    const int error = uv_fs_event_stop(&fs_event->uv_handle);

    event->loop_ref_count = 0;
    ZEND_ASYNC_DECREASE_EVENT_COUNT;

    if (error < 0) {
        async_throw_error("Failed to stop filesystem handle: %s", uv_strerror(error));
        return;
    }
}
/* }}} */

/* {{{ libuv_filesystem_dispose */
static void libuv_filesystem_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REF(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return;
	}

    if (event->loop_ref_count > 0) {
        event->loop_ref_count = 1;
    	event->stop(event);
    }

    zend_async_callbacks_free(event);

    async_filesystem_event_t *fs_event = (async_filesystem_event_t *)(event);

    if (fs_event->event.path) {
        zend_string_release(fs_event->event.path);
    	fs_event->event.path = NULL;
    }

	if (fs_event->event.triggered_filename) {
		zend_string_release(fs_event->event.triggered_filename);
		fs_event->event.triggered_filename = NULL;
	}

    uv_close((uv_handle_t *)&fs_event->uv_handle, libuv_close_handle_cb);

    pefree(event, 0);
}
/* }}} */

/* {{{ libuv_new_filesystem_event */
zend_async_filesystem_event_t* libuv_new_filesystem_event(zend_string * path, const unsigned int flags, size_t size)
{
    async_filesystem_event_t *fs_event = pecalloc(1, size != 0 ? size : sizeof(async_filesystem_event_t), 0);

    const int error = uv_fs_event_init(UVLOOP, &fs_event->uv_handle);

    if (error < 0) {
        async_throw_error("Failed to initialize filesystem handle: %s", uv_strerror(error));
        pefree(fs_event, 0);
        return NULL;
    }

    fs_event->uv_handle.data = fs_event;
    fs_event->event.path = zend_string_copy(path);
    fs_event->event.flags = flags;

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

    if (UNEXPECTED(status < 0)) {
        exception = async_new_exception(
            async_ce_dns_exception, "DNS error: %s", uv_strerror(status)
        );

    	zend_async_callbacks_notify(&name_info->event.base, NULL, exception);

    	if (exception != NULL) {
            zend_object_release(exception);
        }

    	return;
    }

	name_info->event.hostname = hostname;
	name_info->event.service = service;

    zend_async_callbacks_notify(&name_info->event.base, NULL, NULL);

    IF_EXCEPTION_STOP_REACTOR;
}
/* }}} */

/* {{{ libuv_dns_nameinfo_start */
static void libuv_dns_nameinfo_start(zend_async_event_t *event)
{
	if (event->loop_ref_count > 0) {
		event->loop_ref_count++;
		return;
	}

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT;
}
/* }}} */

/* {{{ libuv_dns_nameinfo_stop */
static void libuv_dns_nameinfo_stop(zend_async_event_t *event)
{
	if (event->loop_ref_count > 1) {
		event->loop_ref_count--;
		return;
	}

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT;
}
/* }}} */

/* {{{ libuv_dns_nameinfo_dispose */
static void libuv_dns_nameinfo_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REF(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	async_dns_nameinfo_t *name_info = (async_dns_nameinfo_t *)(event);

	uv_close((uv_handle_t *)&name_info->uv_handle, libuv_close_handle_cb);

	pefree(event, 0);
}
/* }}} */

/* {{{ libuv_getnameinfo */
static zend_async_dns_nameinfo_t * libuv_getnameinfo(const struct sockaddr *addr, int flags, size_t size)
{
	async_dns_nameinfo_t *name_info = pecalloc(1, size != 0 ? size : sizeof(async_dns_nameinfo_t), 0);

	const int error = uv_getnameinfo(
		UVLOOP, &name_info->uv_handle, on_nameinfo_event, (const struct sockaddr*) &addr, flags
	);

	if (error < 0) {
		async_throw_error("Failed to initialize getnameinfo handle: %s", uv_strerror(error));
		pefree(name_info, 0);
		return NULL;
	}

	name_info->uv_handle.data = name_info;

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

	if (status < 0) {
		exception = async_new_exception(
			async_ce_dns_exception, "DNS error: %s", uv_strerror(status)
		);
	}

	zend_async_callbacks_notify(&addr_info->event.base, res, exception);

	if (exception != NULL) {
		zend_object_release(exception);
	}

	IF_EXCEPTION_STOP_REACTOR;
}
/* }}} */

/* {{{ libuv_dns_getaddrinfo_start */
static void libuv_dns_getaddrinfo_start(zend_async_event_t *event)
{
	if (event->loop_ref_count > 0) {
		event->loop_ref_count++;
		return;
	}

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT;
}
/* }}} */

/* {{{ libuv_dns_getaddrinfo_stop */
static void libuv_dns_getaddrinfo_stop(zend_async_event_t *event)
{
	if (event->loop_ref_count > 1) {
		event->loop_ref_count--;
		return;
	}

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT;
}
/* }}} */

/* {{{ libuv_dns_getaddrinfo_dispose */
static void libuv_dns_getaddrinfo_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REF(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return;
	}

	if (event->loop_ref_count > 0) {
		event->loop_ref_count = 1;
		event->stop(event);
	}

	zend_async_callbacks_free(event);

	async_dns_addrinfo_t *addr_info = (async_dns_addrinfo_t *)(event);

	uv_close((uv_handle_t *)&addr_info->uv_handle, libuv_close_handle_cb);

	pefree(event, 0);
}
/* }}} */

/* {{{ libuv_getaddrinfo */
static zend_async_dns_addrinfo_t* libuv_getaddrinfo(const char *node, const char *service, const struct addrinfo *hints, size_t size)
{
	async_dns_addrinfo_t *addr_info = pecalloc(1, size != 0 ? size : sizeof(async_dns_nameinfo_t), 0);

	const int error = uv_getaddrinfo(
		UVLOOP, &addr_info->uv_handle, on_addrinfo_event, node, service, hints
	);

	if (error < 0) {
		async_throw_error("Failed to initialize getaddrinfo handle: %s", uv_strerror(error));
		pefree(addr_info, 0);
		return NULL;
	}

	addr_info->uv_handle.data = addr_info;

	addr_info->event.base.add_callback = libuv_add_callback;
	addr_info->event.base.del_callback = libuv_remove_callback;
	addr_info->event.base.start = libuv_dns_getaddrinfo_start;
	addr_info->event.base.stop = libuv_dns_getaddrinfo_stop;
	addr_info->event.base.dispose = libuv_dns_getaddrinfo_dispose;

	return &addr_info->event;
}
/* }}} */

////////////////////////////////////////////////////////////////////////////////////
/// Exec API
///////////////////////////////////////////////////////////////////////////////////

/* {{{ exec_on_exit */
static void exec_on_exit(uv_process_t* process, const int64_t exit_status, int term_signal)
{
	async_exec_event_t *exec = process->data;
	ZVAL_LONG(exec->event.return_value, exit_status);

	exec->process->data = NULL;
	exec->process = NULL;

	uv_close((uv_handle_t*)process, libuv_close_handle_cb);

	if (exec->event.terminated != true) {
		exec->event.terminated = true;
		ZEND_ASYNC_DECREASE_EVENT_COUNT;

		zend_async_callbacks_notify(&exec->event.base, NULL, NULL);
	}
}
//* }}} */

static void exec_alloc_cb(uv_handle_t* handle, size_t suggested_size, uv_buf_t* buf)
{
	async_exec_event_t * event = handle->data;
	zend_async_exec_event_t * exec = &event->event;

	if (exec->output_len == 0)
	{
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
	async_exec_event_t * event = stream->data;
	zend_async_exec_event_t * exec = &event->event;

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
					zend_string * string = Z_STR_P(exec->result_buffer);
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
		uv_close((uv_handle_t *)stream, libuv_close_handle_cb);

		if (exec->terminated != true) {
			exec->terminated = true;
			ZEND_ASYNC_DECREASE_EVENT_COUNT;
			zend_async_callbacks_notify(&event->event.base, NULL, NULL);
		}
	}
}

static void exec_std_err_alloc_cb(uv_handle_t* handle, size_t suggested_size, uv_buf_t* buf)
{
	buf->base = emalloc(suggested_size);
	buf->len = suggested_size;
}

static void exec_std_err_read_cb(uv_stream_t *stream, ssize_t nread, const uv_buf_t *buf)
{
	async_exec_event_t * event = stream->data;
	zend_async_exec_event_t * exec = &event->event;

	if (nread > 0) {

		if (exec->std_error != NULL) {
			if (Z_TYPE_P(exec->std_error) != IS_STRING) {
				ZVAL_NEW_STR(exec->std_error, zend_string_init(buf->base, nread, 0));
			} else {
				zend_string * string = Z_STR_P(exec->std_error);
				string = zend_string_extend(string, ZSTR_LEN(string) + nread, 0);
				memcpy(ZSTR_VAL(string) + ZSTR_LEN(string) - nread, buf->base, nread);
				ZVAL_STR(exec->std_error, string);
			}
		}

	} else if (nread < 0) {

		event->stderr_pipe->data = NULL;
		event->stderr_pipe = NULL;

		uv_read_stop(stream);
		uv_close((uv_handle_t *)stream, libuv_close_handle_cb);
	}

	efree(buf->base);
}
/* }}} */

/* {{{ libuv_exec_start */
static void libuv_exec_start(zend_async_event_t *event)
{
	if (event->loop_ref_count > 0) {
		event->loop_ref_count++;
		return;
	}

	async_exec_event_t *exec = (async_exec_event_t *)(event);

	if (exec->process == NULL) {
		return;
	}

	event->loop_ref_count++;
	ZEND_ASYNC_INCREASE_EVENT_COUNT;
}
/* }}} */

/* {{{ libuv_exec_stop */
static void libuv_exec_stop(zend_async_event_t *event)
{
	if (event->loop_ref_count > 1) {
		event->loop_ref_count--;
		return;
	}

	async_exec_event_t *exec = (async_exec_event_t *)(event);

	if (exec->process == NULL) {
		return;
	}

	event->loop_ref_count = 0;
	ZEND_ASYNC_DECREASE_EVENT_COUNT;

	if (exec->process != NULL) {
		uv_process_kill(exec->process, ZEND_ASYNC_SIGTERM);
	}
}
/* }}} */

/* {{{ libuv_exec_dispose */
static void libuv_exec_dispose(zend_async_event_t *event)
{
	if (ZEND_ASYNC_EVENT_REF(event) > 1) {
		ZEND_ASYNC_EVENT_DEL_REF(event);
		return;
	}

    if (event->loop_ref_count > 0) {
        event->loop_ref_count = 1;
        event->stop(event);
    }

    zend_async_callbacks_free(event);

    async_exec_event_t *exec = (async_exec_event_t *)(event);

    if (exec->event.output_buffer != NULL) {
        efree(exec->event.output_buffer);
        exec->event.output_buffer = NULL;
        exec->event.output_len = 0;
    }

    if (exec->process != NULL && !uv_is_closing((uv_handle_t *)exec->process)) {
        uv_process_kill(exec->process, ZEND_ASYNC_SIGTERM);
        uv_close((uv_handle_t *)exec->process, libuv_close_handle_cb);
        exec->process = NULL;
    }

    if (exec->stdout_pipe != NULL && !uv_is_closing((uv_handle_t *)exec->stdout_pipe)) {
        uv_read_stop((uv_stream_t *)exec->stdout_pipe);
        uv_close((uv_handle_t *)exec->stdout_pipe, libuv_close_handle_cb);
        exec->stdout_pipe = NULL;
    }

    if (exec->stderr_pipe != NULL && !uv_is_closing((uv_handle_t *)exec->stderr_pipe)) {
        uv_read_stop((uv_stream_t *)exec->stderr_pipe);
        uv_close((uv_handle_t *)exec->stderr_pipe, libuv_close_handle_cb);
        exec->stderr_pipe = NULL;
    }

#ifdef PHP_WIN32
    if (exec->quoted_cmd != NULL) {
        efree(exec->quoted_cmd);
        exec->quoted_cmd = NULL;
    }
#endif

    pefree(event, 0);
}
/* }}} */

/* {{{ libuv_new_exec_event */
static zend_async_exec_event_t * libuv_new_exec_event(
	zend_async_exec_mode exec_mode,
	const char *cmd,
	zval *return_buffer,
	zval *return_value,
	zval *std_error,
	const char *cwd,
	const char *env,
	size_t size
)
{
	async_exec_event_t * exec = pecalloc(1, size != 0 ? size : sizeof(async_exec_event_t), 0);
	zend_async_exec_event_t * base = &exec->event;
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
	options->args = (char*[]) { "cmd.exe", "/s", "/c", exec->quoted_cmd, NULL };
#else
	options->file = "/bin/sh";
	options->args = (char*[]) { "sh", "-c", (char *)cmd, NULL };
#endif

	options->stdio = (uv_stdio_container_t[]) {
	        { UV_IGNORE },
			{
				.data.stream = (uv_stream_t*) exec->stdout_pipe,
				.flags = UV_CREATE_PIPE | UV_WRITABLE_PIPE
			},
			{
				.data.stream = (uv_stream_t*) exec->stderr_pipe,
				.flags = UV_CREATE_PIPE | UV_WRITABLE_PIPE
			}
	};

	options->stdio_count = 3;

	if(cwd != NULL && cwd[0] != '\0') {
		options->cwd = cwd;
	}

	if(env != NULL) {
		options->env = (char **)env;
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

	uv_read_start((uv_stream_t*) exec->stdout_pipe, exec_alloc_cb, exec_read_cb);
	uv_read_start((uv_stream_t*) exec->stderr_pipe, exec_std_err_alloc_cb, exec_std_err_read_cb);

	ZEND_ASYNC_INCREASE_EVENT_COUNT;

	exec->event.base.add_callback = libuv_add_callback;
	exec->event.base.del_callback = libuv_remove_callback;
	exec->event.base.start = libuv_exec_start;
	exec->event.base.stop = libuv_exec_stop;
	exec->event.base.dispose = libuv_exec_dispose;

	return &exec->event;
}

/* {{{ libuv_exec */
static int libuv_exec(
	zend_async_exec_mode exec_mode,
	const char *cmd,
	zval *return_buffer,
	zval *return_value,
	zval *std_error,
	const char *cwd,
	const char *env,
	const zend_ulong timeout
)
{
	zval tmp_return_value, tmp_return_buffer;

	ZVAL_UNDEF(&tmp_return_value);
	ZVAL_UNDEF(&tmp_return_buffer);

	zend_async_exec_event_t * exec_event = ZEND_ASYNC_NEW_EXEC_EVENT(
		exec_mode,
		cmd,
		return_buffer != NULL ? return_buffer : &tmp_return_buffer,
		return_value != NULL ? return_value : &tmp_return_value,
		std_error,
		cwd,
		env
	);

	zval_ptr_dtor(&tmp_return_value);
	zval_ptr_dtor(&tmp_return_buffer);

	return 0;
}
/* }}} */

void async_libuv_reactor_register(void)
{
	zend_string * module_name = zend_string_init(LIBUV_REACTOR_NAME, sizeof(LIBUV_REACTOR_NAME) - 1, 0);

	zend_async_reactor_register(
		module_name,
		false,
		libuv_reactor_startup,
		libuv_reactor_shutdown,
		libuv_reactor_execute,
		libuv_reactor_loop_alive,
		libuv_new_socket_event,
		libuv_new_poll_event,
		libuv_new_timer_event,
		libuv_new_signal_event,
		libuv_new_process_event,
		libuv_new_thread_event,
		libuv_new_filesystem_event,
		libuv_getnameinfo,
		libuv_getaddrinfo,
		libuv_new_exec_event,
		libuv_exec
	);

	zend_string_release(module_name);
}