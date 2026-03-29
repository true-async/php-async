# Changelog

All notable changes to the Async extension for PHP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.6.6] -

### Fixed
- **`Scope::awaitCompletion()` not marking cancellation Future as used**: The cancellation token passed to `awaitCompletion()` was never marked with `RESULT_USED` / `EXC_CAUGHT`, causing a spurious "Future was never used" warning when the Future was destroyed. Additionally, early return paths (scope already finished, closed, or cancelled) skipped the marking entirely. Fixed by setting flags immediately after parameter parsing, before any early returns.
- **`Scope::awaitAfterCancellation()` not marking cancellation Future as used**: Same issue as `awaitCompletion()` — the optional cancellation Future was only marked when the method reached `resume_when`, but early returns bypassed it. Fixed identically.

## [0.6.5] - 2026-03-29

### Changed
- **ZEND_ASYNC_SUSPEND** No longer throws an error when called with an empty array of events.
- **Waker inline storage optimization**: Embedded 2 trigger slots and 2 callback slots directly into the Waker struct, eliminating heap allocations for the most common case (1-2 events per await). Uses `capacity == 0` to mark inline triggers and `base.callback == NULL` to mark free inline callback slots. When more than 1 callback per event is needed, the inline trigger automatically promotes to a heap-allocated one. Benchmarks show ~3× speedup across all hot paths (`await`: 2.13 → 0.67 μs, `await_all` x2: 3.88 → 1.38 μs, Channel: 1.48 → 0.50 μs) with zero memory overhead.
- **Adaptive fiber pool sizing**: The fiber context pool now grows dynamically based on coroutine queue pressure instead of being limited to a fixed size of 4. When demand exceeds the pool (queue size > pool count), the pool grows via `circular_buffer_push_ptr_with_resize`. When demand is low, excess fibers are destroyed instead of returned to the pool. A minimum of 4 fibers (`ASYNC_FIBER_POOL_SIZE`) is always retained. This eliminates costly fiber create/destroy cycles under bursty workloads, yielding a 10–15% improvement in context switch throughput (10k coroutines × 10 suspends: 490 → 566 switches/ms).

### Fixed
- **SIGSEGV in pool healthcheck callback**: The healthcheck timer callback was registered by casting the pool pointer directly to `zend_async_event_callback_t`, corrupting the pool's event structure fields and leaving the `dispose` function pointer uninitialized. When the pool was closed, `zend_async_callbacks_free` called the garbage dispose pointer, causing a segfault. Fixed by embedding a proper `zend_async_event_callback_t` inside `async_pool_t` and using `offsetof` to recover the pool pointer in the callback.
- **`proc_close()` crash when child process already reaped**: When a child process was killed by a signal and its zombie was reaped externally (e.g. by a host runtime calling `waitpid(-1)`), `async_wait_process()` fell through to `libuv_process_event_start()` which threw `AsyncException: Failed to monitor process N: No child processes`. Fixed by handling `ECHILD` in both `async_wait_process()` (early return) and `libuv_process_event_start()` (treat as exited with unknown status).
- **Pool acquire with failed factory caused use-after-free**: When `pool_create_resource()` threw an exception, `zend_async_pool_acquire()` fell through to `pool_wait_for_resource()` with a live `EG(exception)`, registering a coroutine callback on the pool event. At shutdown, the coroutine was freed first, leaving a dangling pointer that `pool_dispose` tried to dereference. Fixed by checking `EG(exception)` after factory failure and returning immediately.
- **Missing exception checks in pool error paths**: `pool_destroy_resource()` and `pool_create_resource()` exceptions were not checked in healthcheck loop, `beforeAcquire` failure path, and `try_acquire`. Added `EG(exception)` checks to break/return on error instead of continuing with live exceptions.
- **Pool close now chains destructor exceptions via `previous`**: When multiple resource destructors throw during `pool->close()`, all resources are still destroyed and exceptions are chained using `zend_exception_set_previous()` so no error is silently lost.
- **Pool destructor exceptions now propagate**: Resource destructor exceptions were silently discarded by `zend_clear_exception()`. Removed the suppression so exceptions propagate normally to the caller.

## [0.6.4] - 2026-03-25

### Fixed
- **NULL `driver_data` crash in PDO PgSQL pool mode**: `pgsql_stmt_execute()` called `in_transaction()` on `stmt->dbh`, which in pool mode is the template PDO object with `driver_data == NULL`. This caused a segfault when dereferencing `H->server` via `PQtransactionStatus()`. Fixed by using `stmt->pooled_conn` (the actual pooled connection) when available.

## [0.6.3] - 2026-03-25

### Fixed
- **`Scope::awaitCompletion()` ignoring completion**: `async_scope_notify_coroutine_finished()` was missing the call to `scope_check_completion_and_notify()`, so `awaitCompletion()` never woke up when all coroutines finished and always waited until the timeout expired.
- **`Scope::awaitAfterCancellation()` cleanup**: Replaced `zend_async_waker_clean()` with `ZEND_ASYNC_WAKER_DESTROY()` on error paths, and switched to checking the return value of `zend_async_resume_when()` instead of `EG(exception)`.
- **Negative stream timeout causing poll event leak**: When a stream context timeout was negative (e.g. `PHP_INT_MIN`), the signed `tv_sec` overflowed to a huge positive value when cast to `zend_ulong` milliseconds. This created an async waker with a timer event that held an extra reference to the poll event (refcount 3 instead of 2), causing it to leak. Fixed by checking `tv_sec < 0` before the conversion and falling back to synchronous `php_pollfd_for()`.

## [0.6.2] - 2026-03-24

### Added
- **Non-blocking `flock()`**: `flock()` no longer blocks the event loop. The lock operation is offloaded to the libuv thread pool via `zend_async_task_t`, allowing other coroutines to continue executing while waiting for a file lock.
- **`zend_async_task_new()` API**: New factory function for creating thread pool tasks, registered through the reactor like timer and IO events. Replaces manual `pecalloc` + field initialization.

### Fixed
- **`await_*()` deadlock with already-completed awaitables**: When a coroutine or Future passed to `await_all()`, `await_any_or_fail()`, or other `await_*()` functions had already completed, it was skipped entirely (`ZEND_ASYNC_EVENT_IS_CLOSED` → `continue`), but `resolved_count` was never incremented. Since `total` still counted the skipped awaitable, `resolved_count` could never reach `total`, causing a deadlock. Fixed by using `ZEND_ASYNC_EVENT_REPLAY` to synchronously replay the stored result/exception through the normal callback path, correctly updating all counters. Additionally, when replay satisfies the waiting condition early (e.g. `await_any_or_fail` needs only one result), the loop now breaks immediately instead of subscribing to remaining awaitables and suspending unnecessarily.

## [0.6.1] - 2026-03-15

### Fixed
- **`feof()` on sockets unreliable on Windows**: `WSAPoll(timeout=0)` fails to detect FIN packets on Windows, causing `feof()` to return false on closed sockets. Fixed by skipping poll for liveness checks (`value==0`) and going directly to `recv(MSG_PEEK)`. On Windows, `MSG_DONTWAIT` is unavailable, so non-blocking mode is temporarily toggled via `ioctlsocket`. Errno is saved immediately after `recv` because `ioctlsocket` clears `WSAGetLastError()`. Shared logic extracted into `php_socket_check_liveness()` in `network_async.c` to eliminate duplication between `xp_socket.c` and `xp_ssl.c`.
- **Pipe close error on Windows**: `php_select()` incorrectly skipped signaled pipe handles when `num_read_pipes >= n_handles`, causing pipe-close events to be missed and `proc_open` reads to hang. Fixed by removing the `num_read_pipes < n_handles` guard so `PeekNamedPipe` is always called for signaled handles.

## [0.6.0] - 2026-03-14

### Fixed
- **Async file IO position tracking**: Replaced bare `lseek`/`_lseeki64` with `zend_lseek` across reactor. Rewrote `libuv_io_seek` to accept `whence` and return position, eliminating double lseek in `php_stdiop_seek`. Fixed append-mode offset init and fseek behavior. On Windows, append writes now query real EOF via `lseek(SEEK_END)` before dispatch to avoid stale cached offsets.
- **Windows concurrent append (XFAIL)**: On Windows, `WriteFile` via libuv ignores CRT `_O_APPEND` because `FILE_WRITE_DATA` coexists with `FILE_APPEND_DATA` on the HANDLE. Removing `FILE_WRITE_DATA` would fix atomic append but breaks `ftruncate`/`SetEndOfFile`. Concurrent append from multiple coroutines remains a known limitation (test 069 marked XFAIL).
- **Reactor deadlock on pending file I/O requests**: `uv_fs_read`, `uv_fs_write`, `uv_fs_fsync`, and `uv_fs_fstat` are libuv requests (not handles) that keep `uv_loop_alive()` true but were invisible to `ZEND_ASYNC_ACTIVE_EVENT_COUNT`. The reactor loop exited prematurely (`has_handles && active_event_count > 0` → false) while file I/O callbacks were still pending, causing deadlocks in async file writes (e.g. `CURLOPT_FILE` with async I/O). Fixed by adding `ZEND_ASYNC_INCREASE_EVENT_COUNT` after successful `uv_fs_*` submission and `ZEND_ASYNC_DECREASE_EVENT_COUNT` in their completion callbacks (`io_file_read_cb`, `io_file_write_cb`, `io_file_flush_cb`, `io_file_stat_cb`).
- **Generator segfault in fiber-coroutine mode**: Generators running inside fiber coroutines were not marked with `ZEND_GENERATOR_IN_FIBER` because `EG(active_fiber)` is not set in coroutine mode. This caused shutdown destructors to close generators while the coroutine was still suspended, leading to a NULL `execute_data` dereference in `zend_generator_resume`. Fixed by also checking `ZEND_ASYNC_CURRENT_COROUTINE` with `ZEND_COROUTINE_IS_FIBER` when setting the `IN_FIBER` flag on generators.

### Added
- **`Async\OperationCanceledException`**: New exception class extending `AsyncCancellation`, thrown when an awaited operation is interrupted by a cancellation token. The original exception from the token is always available via `$previous`. This allows distinguishing token-triggered cancellations from exceptions thrown by the awaitable itself. Affects all cancellable APIs: `await()`, `await_*()` family, `Future::await()`, `Channel::send()`/`recv()`, `Scope::awaitCompletion()`/`awaitAfterCancellation()`, and `signal()`.
- **TaskGroup** (`Async\TaskGroup`): Task pool with queue, concurrency control, and structured completion via `all()`, `race()`, `any()`, `awaitCompletion()`, `cancel()`, `seal()`, `finally()`, and `foreach` iteration
- **TaskSet** (`Async\TaskSet`): Mutable task collection with automatic cleanup semantics. Completed entries are removed after results are consumed. Provides `joinNext()`, `joinAny()`, `joinAll()` methods (replacing `race()`/`any()`/`all()` with join semantics), plus `foreach` iteration with per-entry cleanup.
- **Deadlock diagnostics** (`async.debug_deadlock` INI option): When enabled (default: on), prints detailed diagnostic info on deadlock detection — coroutine list with spawn/suspend locations and the events each coroutine is waiting for. All event types now implement `info` method for human-readable descriptions.
- **TCP/UDP Socket I/O**: Efficient non-blocking TCP/UDP socket functions without poll overhead via libuv handles. Includes `sendto`/`recvfrom` for UDP, socket options API (`broadcast`, `multicast`, TCP `nodelay`/`keepalive`), and unified close callback for all I/O handle types.
- **Async File and Pipe I/O**: Non-blocking I/O for plain files and pipes via `php_stdiop_read`/`php_stdiop_write` async path. Supported functions: `fread`, `fwrite`, `fseek`, `ftell`, `rewind`, `fgets`, `fgetc`, `fgetcsv`, `fputcsv`, `ftruncate`, `fflush`, `fscanf`, `file_get_contents`, `file_put_contents`, `file()`, `copy`, `tmpfile`, `readfile`, `fpassthru`, `stream_get_contents`, `stream_copy_to_stream`
- **Pipe/Stream Read Timeout**: `stream_set_timeout()` now works for pipe streams (`proc_open` pipes, TTY). In async mode, timeout is enforced via waker timer competing with IO event — whoever fires first wins. `stream_get_meta_data()['timed_out']` correctly reports timeout state. The pipe handle remains usable after timeout. Also fixed `libuv_io_event_stop` to properly cancel pending reads via `uv_read_stop` without destroying the handle.
- **Async IO Seek API**: `ZEND_ASYNC_IO_SEEK` for syncing libuv file offset after `fseek`/`rewind`
- **Async IO Append Flag**: `ZEND_ASYNC_IO_APPEND` flag for correct append-mode file offset initialization
- **Future Support**: Full Future/FutureState implementation with `map()`, `catch()`, `finally()` chains and proper flag propagation
- **Channel**: CSP-style message passing between coroutines with buffered/unbuffered modes, timeout support, and iterator interface
- **Pool**: Resource pool implementation with CircuitBreaker pattern support
  - `Async\Pool` class for managing reusable resources (connections, handles, etc.)
  - Configurable min/max pool size with automatic pre-warming
  - `acquire()` / `tryAcquire()` / `release()` methods for resource management
  - Blocking acquire with timeout support in coroutine context
  - Callbacks: `factory`, `destructor`, `healthcheck`, `beforeAcquire`, `beforeRelease`
  - `CircuitBreakerInterface` implementation with state management (ACTIVE/INACTIVE/RECOVERING)
  - `CircuitBreakerStrategyInterface` for custom recovery strategies
  - `ServiceUnavailableException` when circuit breaker is INACTIVE
  - C API: `ZEND_ASYNC_NEW_POOL()`, `ZEND_ASYNC_POOL_ACQUIRE()`, etc. macros for internal use
- **TrueAsync ABI**: Extended `zend_async_API.h` with Pool support
  - Added `zend_async_pool_t` structure with CircuitBreaker state
  - Added `zend_async_circuit_state_t` enum and strategy types
  - Added Pool API function pointers and registration mechanism
  - Added `ZEND_ASYNC_CLASS_POOL` and `ZEND_ASYNC_EXCEPTION_SERVICE_UNAVAILABLE` to class enum
- **PDO Connection Pooling**: Transparent connection pooling for PDO with per-coroutine dispatch and automatic lifecycle management
- **PDO PgSQL**: Non-blocking query execution for PostgreSQL PDO driver
- **PostgreSQL**: Concurrent `pg_*` query execution with separate connections per async context
- **`Async\iterate()` function**: Iterates over an iterable, calling the callback for each element with optional concurrency limit. Supports `cancelPending` parameter (default: `true`) that controls whether coroutines spawned inside the callback are cancelled or awaited after iteration completes.
- **`Async\FileSystemWatcher` class**: Persistent filesystem watcher with `foreach` iteration support, suspend/resume on new events, two storage modes (coalesce with HashTable deduplication, raw with circular buffer), `close()`/`isClosed()` lifecycle, and `Awaitable` interface via `ZEND_ASYNC_EVENT_REF_FIELDS` pattern. Replaces the one-shot `Async\watch_filesystem()` function.
- **`Async\signal()` function**: One-shot signal handler that returns a `Future` resolved when the specified signal is received. Supports optional `Cancellation` for early cancellation.
- **Acting coroutine for error context** (`zend_async_globals_t.acting_coroutine`): New field in async globals that allows scheduler-context code to attribute errors to a suspended coroutine. When set, `zend_get_executed_filename_ex()`, `zend_get_executed_lineno()`, and `get_active_function_name()` in `Zend/zend_execute_API.c` fall back to the coroutine's suspended `execute_data` for file, line, and function name. Zero-cost: the execute_data is only read when an error actually occurs. Macros: `ZEND_ASYNC_ACTING_COROUTINE`, `ZEND_ASYNC_ACT_AS_START(coroutine)`, `ZEND_ASYNC_ACT_AS_END()`.

### Changed
- **Bailout handling**: Added `ZEND_ASYNC_EVENT_F_BAILOUT` flag (bit 11) on `zend_async_event_t`. During bailout (e.g. OOM), PHP-level handlers are no longer called — finally handlers on coroutines and scopes are destroyed without execution, scope exception handlers (`try_to_handle_exception`) are skipped. C-level callbacks (`ZEND_ASYNC_CALLBACKS_NOTIFY`) continue to work normally. Convenience macros: `ZEND_COROUTINE_SET_BAILOUT`/`ZEND_COROUTINE_IS_BAILOUT`, `ZEND_ASYNC_SCOPE_SET_BAILOUT`/`ZEND_ASYNC_SCOPE_IS_BAILOUT`.
- **Removed "Graceful shutdown mode" warning**: The `Warning: Graceful shutdown mode was started` message is no longer emitted during bailout (OOM/stack overflow). The graceful shutdown still happens, but without the warning output.
- **Breaking Change: `onFinally()` renamed to `finally()`** on both `Async\Coroutine` and `Async\Scope` classes,
  aligning with the Promise/A+ convention (`.then()`, `.catch()`, `.finally()`).
  - **Migration**: Replace `->onFinally(function() { ... })` with `->finally(function() { ... })`.
- **Breaking Change: `Async\CancellationError` renamed to `Async\AsyncCancellation`** and now extends `\Cancellation` instead of `\Error`.
  `\Cancellation` is a new PHP core root class implementing `\Throwable` (alongside `\Exception` and `\Error`), added per the [True Async RFC](https://wiki.php.net/rfc/true_async).
  This prevents cancellation exceptions from being accidentally caught by `catch(\Exception)` or `catch(\Error)` blocks.
  - **Migration**: Replace `catch(Async\CancellationError $e)` with `catch(Async\AsyncCancellation $e)` or `catch(\Cancellation $e)` for broader matching.
- **Hidden Events**: Added `ZEND_ASYNC_EVENT_F_HIDDEN` flag for events excluded from deadlock detection
- **Scope `can_be_disposed` API**: Exposed `scope_can_be_disposed` as a virtual method on `zend_async_scope_t`, enabling scope completion checks from the Zend API via `ZEND_ASYNC_SCOPE_IS_COMPLETED`, `ZEND_ASYNC_SCOPE_IS_COMPLETELY_DONE`, and `ZEND_ASYNC_SCOPE_CAN_BE_DISPOSED` macros.
- **TaskGroup completion semantics**: `ASYNC_TASK_GROUP_F_COMPLETED` flag is now set only when the group is both sealed and all tasks are settled. `finally()` handlers fire only in this terminal state. Calling `finally()` on an already-completed group invokes the callback synchronously.

### Fixed
- **exec() output not split into lines in async path**: The libuv read callback delivered raw byte chunks to the output array instead of splitting by newlines and stripping trailing whitespace like the POPEN path does. Implemented an on-the-fly line parser with zero-copy optimization and 8 KB reusable buffer (doubling strategy). Uses `memchr()` for SIMD-accelerated newline scanning. Fully matches POPEN path behavior including `isspace()` trailing whitespace stripping.
- **exec() exit code race condition**: Pipe EOF notification (`exec_read_cb`) often arrived before `exec_on_exit`, waking the coroutine with `exit_code` still 0. Fixed by making `exec_on_exit` the sole notification point.
- **exec() not routed through async path**: Changed routing condition from `ZEND_ASYNC_IS_ACTIVE` to `ZEND_ASYNC_ON` + `ZEND_ASYNC_SCHEDULER_INIT()` so exec functions use the async path when the scheduler is available.
- **Deadlock in `proc_close()` when spawning many concurrent processes on Windows**: Windows Job Objects send `JOB_OBJECT_MSG_ACTIVE_PROCESS_ZERO` in addition to `JOB_OBJECT_MSG_EXIT_PROCESS` for every single-process job that exits. The IOCP watcher thread was treating both messages as process-exit events, pushing the same `process_event` to `pid_queue` twice and decrementing `countWaitingDescriptors` an extra time per process. With enough concurrent processes, the counter reached zero prematurely, triggering `libuv_stop_process_watcher()` too early and destroying `pid_queue` — leaving coroutines suspended in `proc_close()` with no event to wake them. Fixed by ignoring `JOB_OBJECT_MSG_ACTIVE_PROCESS_ZERO` in the switch statement since it always accompanies `EXIT_PROCESS` for single-process jobs.
- **Use-after-free in `zend_exception_set_previous` calls**: When `exception == add_previous` (same object), `zend_exception_set_previous` calls `OBJ_RELEASE` which frees the object while other pointers (e.g. `EG(exception)`) still reference it. Added identity checks before all `zend_exception_set_previous` calls where the two arguments could alias the same object. Affected files: `scheduler.c`, `exceptions.c`, `zend_common.c`, `future.c`.
- **Memory leak of `Async\DeadlockError` in scheduler fiber exit path**: In `fiber_entry`, when the scheduler fiber finalized, `exit_exception` from `ZEND_ASYNC_EXIT_EXCEPTION` was not propagated when `EG(exception) == NULL` — the exception was silently lost. Added `async_rethrow_exception(exit_exception)` for this case.
- **stream_select() ignoring PHP-buffered data in async context**: When `fgets()`/`fread()` pulled more data into PHP's internal stream buffer than returned, a subsequent `stream_select()` would not detect the buffered data because the async path (libuv poll) only checks OS-level file descriptors. This caused hangs in `run-tests.php -j` parallel workers on macOS where TCP delivered multiple messages in a single segment. Fixed by checking `stream_array_emulate_read_fd_set()` before entering the async poll path.
- **Waker events not cleaned when coroutine is resumed outside scheduler context**: When a coroutine was resumed directly (not from the scheduler), its waker events were not automatically cleaned up, which could lead to stale event references. Now `ZEND_ASYNC_WAKER_CLEAN_EVENTS` is called on resume outside the scheduler.
- **False deadlock detection after coroutine execution**: The `has_handles` flag from `ZEND_ASYNC_REACTOR_EXECUTE` was evaluated before coroutines ran but checked after, causing false deadlock when coroutines created new I/O handles between those points. Added `ZEND_ASYNC_REACTOR_LOOP_ALIVE()` check to deadlock conditions for accurate state at decision time.
- **TaskSet auto-cleanup race condition**: Completed task entries were removed unconditionally in `task_group_try_complete()`, even when no consumer had requested results. This caused `joinAll()`/`joinNext()`/`joinAny()` to return empty results when called after tasks had already completed. Fixed by deferring cleanup to the point of actual result delivery — per-entry removal in `race()`/`any()`/iterator callbacks, and bulk cleanup in `all()` after results are collected.

## [0.5.0] - 2025-12-24

### Added
- **Fiber Support**: Full integration of PHP Fibers with TrueAsync coroutine system
  - `Fiber::suspend()` and `Fiber::resume()` work in async scheduler context
  - `Fiber::getCoroutine()` method to access fiber's coroutine
  - Fiber status methods (isStarted, isSuspended, isRunning, isTerminated)
  - Support for nested fibers and fiber-coroutine interactions
  - Comprehensive test coverage for all fiber scenarios
- **TrueAsync API**: Added `ZEND_ASYNC_SCHEDULER_LAUNCH()` macro for scheduler initialization
- **TrueAsync API**: Updated to version 0.8.0 with fiber support
- **TrueAsync API**: Added customizable scheduler heartbeat handler mechanism with `zend_async_set_heartbeat_handler()` API

### Fixed
- **Critical GC Bug**: Fixed garbage collection crash during coroutine cancellation when exception occurs in main coroutine while GC is running
- Fixed double free in `zend_fiber_object_destroy()`
- Fixed `stream_select()` for `timeout == NULL` case in async context
- Fixed fiber memory leaks and improved GC logic

### Changed
- **Deadlock Detection**: Replaced warnings with structured exception handling
  - Deadlock detection now throws `Async\DeadlockError` exception instead of multiple warnings
  - **Breaking Change**: Applications relying on deadlock warnings
  will need to be updated to catch `Async\DeadlockError` exceptions
- **Breaking Change: PHP Coding Standards Compliance** - Function names updated to follow official PHP naming conventions:
  - `spawnWith()` → `spawn_with()`
  - `awaitAnyOrFail()` → `await_any_or_fail()`
  - `awaitFirstSuccess()` → `await_first_success()`
  - `awaitAllOrFail()` → `await_all_or_fail()`
  - `awaitAll()` → `await_all()`
  - `awaitAnyOfOrFail()` → `await_any_of_or_fail()`
  - `awaitAnyOf()` → `await_any_of()`
  - `currentContext()` → `current_context()`
  - `coroutineContext()` → `coroutine_context()`
  - `currentCoroutine()` → `current_coroutine()`
  - `rootContext()` → `root_context()`
  - `getCoroutines()` → `get_coroutines()`
  - `gracefulShutdown()` → `graceful_shutdown()`
  - **Rationale**: Compliance with [PHP Coding Standards](https://github.com/php/policies/blob/main/coding-standards-and-naming.rst) - functions must use lowercase with underscores

## [0.4.0] - 2025-09-30

### Added
- **UDP socket stream support for TrueAsync**
- **SSL support for socket stream**
- **Poll Proxy**: New `zend_async_poll_proxy_t` structure for optimized file descriptor management
    - Efficient caching of event handlers to reduce EventLoop creation overhead
    - Poll proxy event aggregation and improved lifecycle management

### Fixed
- **Fixing `ref_count` logic for the `zend_async_event_callback_t` structure**:
    - The add/dispose methods correctly increment the counter
    - Memory leaks fixed
- Fixed await iterator logic for `awaitXXX` functions
- Fixed process waiting logic for UNIX-like systems

### Changed
- **Memory Optimization**: Enhanced memory allocation for async structures
    - Optimized waker trigger structures with improved memory layout
    - Enhanced memory management for poll proxy events
    - Better resource cleanup and lifecycle management
- **Event Loop Performance**: Major scheduler optimizations
    - **Automatic Event Cleanup**: Added automatic waker event cleanup when coroutines resume (see `ZEND_ASYNC_WAKER_CLEAN_EVENTS`)
    - Separate queue implementation for resumed coroutines to improve stability
    - Reduced unnecessary LibUV calls in scheduler tick processing
- **Socket Performance**:
    - Event handler caching for sockets to avoid constant EventLoop recreation
    - Optimized `network_async_accept_incoming` to try `accept()` before waiting
    - Enhanced stream_select functionality with event-driven architecture
    - Improved blocking operation handling with boolean return values
- **TrueAsync API Performance**: Optimized execution paths by replacing expensive `EG(exception)` checks with direct `bool` return values across all async functions
- Upgrade `LibUV` to version `1.45` due to a timer bug that causes the application to hang

## [0.3.0] - 2025-07-16

### Added
- Docker support with multi-stage build (Ubuntu 24.04, libuv 1.49, curl 8.10)
- PDO MySQL and MySQLi async support
- **TrueAsync API Extensions**: Enhanced async API with new object creation and coroutine grouping capabilities
    - Added `ZEND_ASYNC_NEW_GROUP()` API for creating CoroutineGroup objects for managing multiple coroutines
    - Added `ZEND_ASYNC_NEW_FUTURE_OBJ()` and `ZEND_ASYNC_NEW_CHANNEL_OBJ()` APIs for creating Zend objects from async primitives
    - Extended `zend_async_task_t` structure with `run` method for thread pool task execution
    - Enhanced `zend_async_scheduler_register()` function with new API function pointers
- **Multiple Callbacks Per Event Support**: Complete redesign of waker trigger system to support multiple callbacks on a single event
    - Modified `zend_async_waker_trigger_s` structure to use flexible array member with dynamic capacity
    - Added `waker_trigger_create()` and `waker_trigger_add_callback()` helper functions for efficient memory management
    - Implemented single-block memory allocation for better performance (trigger + callback array in one allocation)
    - Default capacity starts at 1 and doubles as needed (1 → 2 → 4 → 8...)
    - Fixed `coroutine_event_callback_dispose()` to remove only specific callbacks instead of entire events
    - **Breaking Change**: Events now persist until all associated callbacks are removed
- **Bailout Tests**: Added 15 tests covering memory exhaustion and stack overflow scenarios in async operations
- **Garbage Collection Support**: Implemented comprehensive GC handlers for async objects
    - Added `async_coroutine_object_gc()` function to track all ZVALs in coroutine structures
    - Added `async_scope_object_gc()` function to track ZVALs in scope structures  
    - Proper GC tracking for context HashTables (values and keys)
    - GC support for finally handlers, exception handlers, and function call parameters
    - GC tracking for waker events, internal context, and nested async structures
    - Prevents memory leaks in complex async applications with circular references
- **Key Order Preservation**: Added `preserveKeyOrder` parameter to async await functions
    - Added `preserve_key_order` parameter to `async_await_futures()` API function
    - Added `preserve_key_order` field to `async_await_context_t` structure
    - Enhanced `awaitAll()`, `awaitAllWithErrors()`, `awaitAnyOf()`, and `awaitAnyOfWithErrors()` functions with `preserveKeyOrder` parameter (defaults to `true`)
    - Allows controlling whether the original key order is maintained in result arrays

### Fixed
- Memory management improvements for long-running async applications
- Proper cleanup of coroutine and scope objects during garbage collection cycles
- **Async Iterator API**:
    - Fixed iterator state management to prevent memory leaks
- Fixed the `spawnWith()` function for interaction with the `ScopeProvider` and `SpawnStrategy` interface
- **Build System Fixes**:
    - Fixed macOS compilation error with missing field initializer in `uv_stdio_container_t` structure (`libuv_reactor.c:1956`)
    - Fixed Windows build script PowerShell syntax error (missing `shell: cmd` directive)
    - Fixed race condition issues in 10 async test files for deterministic test execution on all platforms

### Changed
- **Breaking Change: Function Renaming** - Major API reorganization for better consistency:
    - `awaitAllFailFirst()` → `awaitAllOrFail()`
    - `awaitAllWithErrors()` → `awaitAll()` 
    - `awaitAnyOfFailFirst()` → `awaitAnyOfOrFail()`
    - `awaitAnyOfWithErrors()` → `awaitAnyOf()`
- **Breaking Change: `awaitAll()` Return Format** - New `awaitAll()` (formerly `awaitAllWithErrors()`) now returns `[results, exceptions]` tuple:
    - First element `[0]` contains array of successful results
    - Second element `[1]` contains array of exceptions from failed coroutines
    - **Migration**: Update from `$results = awaitAll($coroutines)` to `[$results, $exceptions] = awaitAll($coroutines)`
- **LibUV requirement increased to ≥ 1.44.0** - Requires libuv version 1.44.0 or later to ensure proper UV_RUN_ONCE behavior and prevent busy loop issues that could cause high CPU usage
- **Async Iterator API**:
    - Proper handling of `REWIND`/`NEXT` states in a concurrent environment. 
      The iterator code now stops iteration in 
      coroutines if the iterator is in the process of changing its position.
    - Added functionality for proper handling of exceptions from `Zend iterators` (`\Iterator` and `generators`).
      An exception that occurs in the iterator can now be handled by the iterator's owner.


## [0.2.0] - 2025-07-01

### Added
- **Async-aware destructor handling (PHP Core)**: Implemented `async_shutdown_destructors()` function to properly 
  handle destructors that may suspend execution in async context
- **CompositeException**: New exception class for handling multiple exceptions that occur in finally handlers
    - Automatically collects multiple exceptions from `onFinally` handlers in both Scope and Coroutine
    - Provides `addException()` method to add exceptions to the composite
    - Provides `getExceptions()` method to retrieve all collected exceptions
    - Ensures all finally handlers are executed even when exceptions occur
- Complete implementation of `onFinally()` method for `Async\Scope` class
- Cross-thread trigger event API
- Priority support to async iterator system
- Coroutine priority support to TrueAsync API
- **Iterator API integration**: Added `zend_async_iterator_t` structure to TrueAsync API with `run()` and `run_in_coroutine()` methods
- `disposeAfterTimeout()` method for Scope
- `awaitAfterCancellation()` method for Scope
- Complete Scope API implementation
- `Async\protect()` function
- Signal handlers support (UNIX)
- Coroutine class with full lifecycle management
- `onFinally()` logic for Coroutine class

### Changed
- Enhanced ZEND_ASYNC_NEW_SCOPE API to create Scope without Zend object for internal use
- Refactored catch_or_cancel logic according to RFC scope behavior
- Refactored async_scheduler_coroutine_suspend to support non-zero exception context
- Optimized iterator module
- **Iterator structure refactoring**: Made `async_iterator_t` compatible with `zend_async_iterator_t` API by adding function pointer methods
- Improved exception handling and cancellation logic
- Enhanced Context API behavior for Scope

### Fixed
- Multiple fixes for Scope dispose operations
- Fixed scope_try_to_dispose logic
- Spawn tests fixes
- Build issues for Scope
- Context logic with NULL scope
- Iterator bugs and coroutine issues
- Stream tests and DNS tests
- ZEND_ASYNC_IS_OFF issues
- Race condition in process waiting (libuv)
- Memory cleanup for reactor shutdown

## [0.1.0] - 2025-06-25

### Added
- Future logic for coroutine class - coroutines can now behave like real Future objects
- Support for `ob_start` with coroutines
- Global main coroutine switch handlers API for context isolation
- Socket Listening API
- Support for `proc_open` in async context
- CURL async support and comprehensive tests
- Sleep functions (`usleep`, `sleep`) with async support
- Exec functions with async support
- Async DNS resolution support including IPv6
- Comprehensive DNS test suite (13 test files)
- Nanosecond support for async timer events
- Stream socket tests and functionality
- PHP_POLL2 implementation and tests
- `cancel_on_exit` option for `async_await_futures`
- Context API with HashTable optimization
- Coroutine Internal context support

### Changed
- Refactored sockets extension to use new TrueAsync API
- Refactored timeout object implementation with proper memory separation
- Refactored Internal Context API
- Refactored Zend DNS API
- Moved async extension memory initialization to RINIT
- Changed allocator to erealloc2
- Improved circular buffer behavior during relocation and resizing

### Fixed
- Multiple memory leaks
- DNS API bugs and errors
- Stream tests fixes
- CURL function fixes
- Socket extension test fixes
- Exception propagation bugs
- Poll2 logic fixes
- Double free issues in awaitAll
- Coroutine cancellation and completion logic
- Scheduler graceful shutdown logic

## [0.0.1] - Initial Release

### Added
- Initial TrueAsync extension architecture
- Basic coroutine support
- Event loop integration with libuv
- Core async/await functionality
- Basic suspend/resume operations
- Initial test framework
- Context switching mechanisms
- Basic scheduler implementation
