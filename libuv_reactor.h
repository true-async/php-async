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
#ifndef LIBUV_REACTOR_H
#define LIBUV_REACTOR_H

#define LIBUV_REACTOR_VERSION "0.5.0 (libuv 1.44.2)"
#define LIBUV_REACTOR_NAME "Libuv Reactor 0.5.0 (libuv 1.44.2)"
#include <Zend/zend_async_API.h>

#ifdef PHP_WIN32
#include "libuv/uv.h"
#else
#include <uv.h>
#endif

typedef struct _async_poll_event_t async_poll_event_t;
typedef struct _async_socket_event_t async_socket_event_t;
typedef struct _async_timer_event_t async_timer_event_t;
typedef struct _async_signal_event_t async_signal_event_t;
typedef struct _async_filesystem_event_t async_filesystem_event_t;

typedef struct _async_process_event_t async_process_event_t;
typedef struct _async_thread_event_t async_thread_event_t;

typedef struct _async_dns_nameinfo_t async_dns_nameinfo_t;
typedef struct _async_dns_addrinfo_t async_dns_addrinfo_t;

typedef struct _async_exec_event_t async_exec_event_t;

struct _async_poll_event_t {
	zend_async_poll_event_t event;
	uv_poll_t uv_handle;
};

struct _async_timer_event_t {
	zend_async_timer_event_t event;
	uv_timer_t uv_handle;
};

struct _async_signal_event_t {
	zend_async_signal_event_t event;
	uv_signal_t uv_handle;
};

struct _async_filesystem_event_t {
	zend_async_filesystem_event_t event;
	uv_fs_event_t uv_handle;
};

struct _async_dns_nameinfo_t {
	zend_async_dns_nameinfo_t event;
	uv_getnameinfo_t uv_handle;
};

struct _async_dns_addrinfo_t {
	zend_async_dns_addrinfo_t event;
	uv_getaddrinfo_t uv_handle;
};

struct _async_process_event_t {
	zend_async_process_event_t event;
#ifdef PHP_WIN32
	HANDLE hProcess;
	HANDLE hJob;
#endif
};

struct _async_thread_event_t {
	zend_async_thread_event_t event;
	uv_thread_t uv_handle;
};

struct _async_exec_event_t {
	zend_async_exec_event_t event;
	uv_process_t * process;
	uv_pipe_t * stdout_pipe;
	uv_pipe_t * stderr_pipe;
	uv_process_options_t options;
#ifdef PHP_WIN32
	char * quoted_cmd;
#endif
};

void async_libuv_reactor_register(void);

#endif //LIBUV_REACTOR_H
