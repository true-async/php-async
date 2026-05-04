# Changelog

All notable changes to the Async extension for PHP will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.7.0] -

### Added
- **CPU usage probes** — cross-platform process and host CPU monitoring,
  identical fields and semantics on Linux and Windows. Suitable for
  backpressure decisions in long-running coroutines and for emitting
  telemetry from PHP-level metrics loops.
  - `Async\CpuSnapshot::now(): CpuSnapshot` — immutable point-in-time
    snapshot. Final, readonly, private constructor, no dynamic
    properties. Exposes raw monotonic counters: `wallNs`, `processUserNs`,
    `processSystemNs`, `systemIdleNs`, `systemBusyNs`, `cpuCount`. Single
    values are not directly meaningful — callers compute deltas between
    two snapshots themselves.
  - `Async\cpu_usage(): array` — telemetry-friendly wrapper that maintains
    an internal "previous" snapshot per process and returns ready-to-use
    percentages: `process_cores`, `process_percent`, `system_percent`,
    `cpu_count`, `interval_sec`, `loadavg`. The first call seeds the
    internal state and returns zeros; subsequent calls return the delta
    against the previously stored snapshot. State is reset in `RSHUTDOWN`.
  - `Async\loadavg(): ?array` — POSIX 1/5/15-minute system load averages.
    Returns `null` on Windows (no native equivalent; emulating CPU% as
    loadavg has different semantics and would mislead callers).
  Linux uses `clock_gettime(CLOCK_MONOTONIC)`, `getrusage(RUSAGE_SELF)`,
  `/proc/stat` and `getloadavg()`. Windows uses `QueryPerformanceCounter`,
  `GetProcessTimes`, `GetSystemTimes` and
  `GetActiveProcessorCount(ALL_PROCESSOR_GROUPS)`. ZTS-safe via
  `tsrm_mutex`. Inside containers, `system*` fields reflect the host
  rather than the cgroup; for per-process backpressure prefer the
  `process*` fields, which automatically account for affinity and cgroup
  CPU throttling. **No `zend_async_API` changes.**
- **`Async\available_parallelism(): int`** — returns the number of CPUs
  usable by the current process (cgroup quotas, `sched_setaffinity`, etc.),
  i.e. the value libuv recommends for thread-pool / worker sizing. Backed
  by `uv_available_parallelism()` (libuv ≥1.44) with a `uv_cpu_info()`
  fallback on older libuv. Always returns ≥1. Exposed at the API level
  via `zend_async_available_parallelism_fn` / the
  `ZEND_ASYNC_AVAILABLE_PARALLELISM()` macro, registered as a new
  parameter on `zend_async_reactor_register` — third-party reactors must
  thread the new function pointer through. **ABI bump v0.9.1 → v0.10.0.**
- **Timer rearm API** (`zend_async_timer_rearm_fn` /
  `ZEND_ASYNC_TIMER_REARM`). Reschedules an existing timer event without
  the `new_timer_event` + `uv_close` + `dispose` cycle, dropping three
  per-cycle allocations on hot paths that constantly reset a timer
  (e.g. QUIC retransmission timers, idle reapers, exponential backoff
  loops). Opt-in via the new private timer flag
  `ZEND_ASYNC_TIMER_F_MULTISHOT` (bit 13) — set after construction with
  `ZEND_ASYNC_TIMER_SET_MULTISHOT(ev)`. A multishot timer does not
  self-close on a one-shot fire; the owner is responsible for an
  explicit `dispose()` at teardown. Existing one-shot timers are
  unaffected (default path still self-closes). libuv reactor implements
  rearm via a second `uv_timer_start` on the same handle (libuv-native).
  Registered as a new parameter on `zend_async_reactor_register` —
  third-party reactors must thread the new function pointer through
  (NULL is rejected at register time? — current impl tolerates NULL,
  caller must check `zend_async_timer_rearm_fn != NULL` before use).
- **PDO_SQLite connection pool support** (`PDO::ATTR_POOL_ENABLED`). A pooled
  `Pdo\Sqlite` template hands out a private `sqlite3*` per coroutine, with the
  same `PDO::ATTR_POOL_MIN` / `POOL_MAX` / `POOL_HEALTHCHECK_INTERVAL` controls
  as the other PDO drivers. UDFs, aggregates and collations registered on the
  template via `createFunction` / `createAggregate` / `createCollation` are
  applied to every slot. The registry freezes on the first acquire — any
  further registration throws `PDOException` so that all coroutines see the
  same set of UDFs. Single-connection methods that bind to a specific
  `sqlite3*` (`setAuthorizer`, `openBlob`, `loadExtension`) throw on a pool
  template. Unshareable in-memory DSNs (`:memory:`, `file:?mode=memory`
  without `cache=shared`) are rejected at construction. Two new PDO-level
  driver hooks (`pool_before_acquire`, `pool_before_release` on
  `pdo_dbh_methods`) let other drivers plug into the slot hand-off without
  leaking pool internals into `ext/pdo/pdo_pool.c`. Tests:
  `ext/async/tests/pdo_sqlite/001..020`,
  `ext/pdo_sqlite/tests/pool_001..005`. Known limitation: per-coroutine
  personal UDFs (registered after the registry has frozen) are intentionally
  out of scope — the per-release `sqlite3_create_function(NULL, …)` cleanup
  cost is a poor fit for the hot pool path; bootstrap-time registration on
  the template covers the realistic use case.
- **`TaskGroup` / `TaskSet` constructor gains `queueLimit` parameter** (bounded pending queue, backpressure).
  `new TaskGroup(concurrency: N, queueLimit: M)`. When the pending queue reaches `M` entries,
  `spawn()` / `spawnWithKey()` suspend the calling coroutine until a queue slot frees instead of
  allocating an unbounded pending entry. A slot frees whenever a pending task transitions to
  RUNNING (i.e. when a running task finishes and `task_group_drain()` promotes the next pending
  one). Waiters are resumed in FIFO order, one per freed slot. On `seal()` / `cancel()` / dtor all
  waiters are woken at once — they rejoin `do_spawn()`, observe the terminal state, and throw
  "Cannot spawn tasks on a sealed TaskGroup". Defaults: `queueLimit = null` resolves to
  `2 × concurrency` (a modest backpressure window); `queueLimit = 0` explicitly selects the
  legacy unbounded queue; with `concurrency = 0` (unlimited) `queueLimit` is ignored because
  tasks always spawn immediately. Motivation: the previous behaviour allocated a `task_entry_t`
  + `zend_fcall_t` for every over-concurrency `spawn()` call with no upper bound — a worker
  thread running `while (true) { $job = $channel->recv(); $group->spawn($fn); }` would grow
  `group->tasks` at ~500 MB/s when its own `$group->spawn()` outpaced the 100-slot concurrency,
  and starving the main thread prevented the results collector from ever running (see the
  `bench_ta.php` 1×100 IO scenario that hit 7 GB RSS with `completed=0` before OOM). New
  regression tests: `tests/task_group/041-task_group_queue_limit.phpt`,
  `tests/task_group/042-task_group_queue_limit_defaults.phpt`. BC note: the ABI signature of
  `zend_async_new_group_fn` / `ZEND_ASYNC_NEW_GROUP()` now takes `uint32_t queue_limit` between
  `concurrency` and `scope`. C callers of `async_new_group()` must pass the new argument
  (there are none outside of `task_group.c` and the `new_group_stub` fallback in
  `Zend/zend_async_API.c`). PHP-level positional callers of
  `new TaskGroup($concurrency, $scope)` now pick up `null` → default queueLimit; callers
  relying on positional `$scope` must use the named argument `scope:`.
- **`Async\ThreadPool`** (new class): pool of OS threads for executing PHP closures. `submit($callable, ...$args): Future`, `map(array $items, $callable): array`, `close()` (graceful), `cancel()` (rejects backlog with `Async\CancellationException`, running tasks still finish), `isClosed()`, `getWorkerCount()`, `getPendingCount()`, `getRunningCount()`. Implements `Countable`. Constructor `new ThreadPool(int $workers, int $queue_size = 0)`; queue is a thread-safe channel that suspends the submitting coroutine when full (backpressure).
- **`Async\ThreadPoolException`** (new class): thrown from `submit()` / `map()` when the pool is closed.
- **`Async\ThreadChannel`** (new class): thread-safe channel for transferring zvals between threads via deep-copy snapshot. `send()` / `receive()` suspend the calling coroutine instead of blocking the OS thread. Closures, including those with bound variables, transfer correctly through the snapshot machinery.
- **`Async\ThreadChannelException`** (new class).
- **Coverage phase 2** — targeted tests for `future.c`, `async.c`, `task_group.c`, `channel.c`, `thread.c`, `thread_pool.c`, `context.c`, `pool.c`. Aggregate ext/async coverage went from 77.45% to 78.34% lines (+104 lines) and from 88% to 89.1% functions (+10 functions). New tests cover Future status/cancel/getAwaitingInfo methods, FutureState double-resolve errors, finally() exception-chain propagation, non-callable argument rejection on map/catch/finally, TaskGroup synchronous-settled paths for `all()`/`race()`/`any()`, Channel unbuffered-iterator and foreach-by-ref branches, `Async\timeout(0)` ValueError, `Async\delay(0)` fast path, `Async\current_coroutine()` out-of-coroutine error, and `Context::get()` missing-key fallback. See `COVERAGE_PROGRESS.md` for the per-target breakdown.

### Performance
- **Static TSRMLS cache for ext/async sources**: the extension was being built without `-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1`, so every `EG()` / `ASYNC_G()` / `ZEND_ASYNC_G()` macro expansion in scheduler.c, coroutine.c, libuv_reactor.c and the rest of `ext/async/` routed through `pthread_getspecific` (the slow TSRM fallback). The PHP CLI sapi already passes this flag for its own files — `ext/async/` did not. On the bench profile this category was the largest single TLS overhead: `pthread_getspecific` at 4.03% of total CPU and `tsrm_get_ls_cache` at 1.19%, mostly under `async_scheduler_coroutine_suspend`, `fiber_entry`, `async_coroutine_execute` and `async_coroutine_finalize`. Fixed by adding `-DZEND_ENABLE_STATIC_TSRMLS_CACHE=1` as the per-extension `extra-cflags` argument to `PHP_NEW_EXTENSION` in `config.m4`. After the change the same macros compile to a single `__thread` load (`%fs:offset` on x86_64) instead of a libpthread call. The `_tsrm_ls_cache` symbol is already provided by the PHP binary's `TSRMLS_MAIN_CACHE_DEFINE()` so the change is link-clean. Measured on a single-thread minimal HTTP handler (median of 5 wrk -t4 -c64 -d6s runs): throughput rose from ~44k to ~58k req/s (+32%); `pthread_getspecific` dropped to 0.64% and `tsrm_get_ls_cache` to 0.50% in perf top-N; 102/102 phpt regression tests still pass.

### Fixed
- **`bailout_all_coroutines` left popped coroutines flagged `WAKER_QUEUED`**, which then tripped `async_coroutine_finalize`'s "Attempt to finalize a coroutine that is still in the queue" warning during graceful shutdown of multi-threaded servers (worker thread's main coroutine got enqueued by `cancel_queued_coroutines`, popped by `bailout_all_coroutines` without a status transition, then finalized via `async_thread_run`'s `request_shutdown` path). Mirrors the standard dispatch transition (`QUEUED → RESULT`) right after `next_coroutine()`.
- **`active_event_count` underflow on double-stop in `EVENT_STOP_PROLOGUE`**: The prologue had a guard for the *double-start* case (`loop_ref_count > 1` → decrement and return without `DECREASE_EVENT_COUNT`) but no guard for the symmetric *double-stop* case where `loop_ref_count` was already `0`. When something stopped an event through the normal cancel/resolve path (`loop_ref_count` 1→0, `DECREASE_EVENT_COUNT` ran once), and `waker_events_dtor` later called `event->stop()` again during waker cleanup at `Zend/zend_async_API.c:775`, the second call fell through the prologue and ran `DECREASE_EVENT_COUNT` a second time. The macro's underflow protection clamped the global counter at zero, but the lost decrement effectively "stole a count" from another live event — the global `active_event_count` reached zero while wakers still held triggers on actually-running libuv handles. The deadlock detector then dumped `Coroutines waiting: N, active_events: 0` and force-cancelled coroutines whose I/O was still in flight; in the mysqli cancellation path this surfaced as a phantom `mysqli_sql_exception("MySQL server has gone away")` thrown after `{main}` and a corresponding 152-byte exception leak. Fixed by adding an early `return true;` at the top of `EVENT_STOP_PROLOGUE` when `loop_ref_count == 0`, making every `*_stop` operation idempotent for the global counter. The same condition was previously hand-rolled inside `libuv_io_event_stop` only; promoting it into the prologue covers `timer_stop`, `poll_stop`, `poll_proxy_stop`, `signal_stop`, `listen_stop`, `process_stop`, `filesystem_stop` etc. uniformly. Regression test: `tests/mysqli/009-mysqli_cancellation.phpt`.
- **Windows: TCP accept broken in `libuv_io_create()` — every accepted connection failed**: The TCP branch ran the incoming `io_fd` through `_get_osfhandle()` before handing it to `uv_tcp_open()`. For sockets that came straight from `WSASocketW()` / `accept()` (i.e. the value already *is* a native `SOCKET`, not a CRT fd), `_get_osfhandle()` returned `INVALID_HANDLE_VALUE`, `uv_tcp_open` failed with `"Failed to open TCP handle"`, and the exception propagated through `on_connection_event` → `IF_EXCEPTION_STOP_REACTOR`. The reactor stopped, `start_graceful_shutdown()` fired, every live coroutine was cancelled with `"Graceful shutdown"`, and the scheduler's finalisation assert (`scheduler.c:1793` — `"The event loop must be stopped"`) tripped because the listen_event was still armed (user's `stop()` never got to run). Symptom was immediate: any HTTP server built on `ZEND_ASYNC_SOCKET_LISTEN` would accept the TCP three-way handshake, then crash before dispatching a single request. Linux was unaffected because its branch (`const uv_os_sock_t sock = (uv_os_sock_t) io_fd;`) already passed the socket through as-is. Fixed by dropping `_get_osfhandle()` from the Windows TCP path: for `ZEND_ASYNC_IO_TYPE_TCP` / `ZEND_ASYNC_IO_TYPE_UDP` the caller passes the native `zend_socket_t` value, matching both the type that gets stored at `io->base.descriptor.socket = (zend_socket_t) io_fd` a few lines up and the POSIX side. Discovered while bringing up `php-http-server` on Windows for the first time — the canonical `010-server-e2e-simple.phpt` could not return a response because of this.
- **TaskGroup owned-scope UAF on worker-thread shutdown**: `TaskGroup(concurrency: N)` without an explicit scope creates a child `async_new_scope(..., with_zend_object=false)` and bumps its `ref_count` to pin it. But `scope_dispose()` unconditionally consumes one ref when called directly (e.g. from a parent scope's cascade-disposal at `scope.c:1161`), so the TaskGroup's +1 was eaten by the first parent dispose. A second dispose (thread shutdown, nested scope teardown) then dropped the count to 0 and `efree`d the scope, leaving `group->scope` dangling. When `task_group_dtor_object()` ran during `zend_call_destructors` in the worker thread's `php_request_shutdown()`, it dereferenced the freed `scope->event` and SIGSEGV'd. Reproducible with 12 `spawn_thread` workers, a `ThreadChannel`-based job queue, and a closing producer. Fixed by introducing `ZEND_ASYNC_SCOPE_F_OWNER_PINNED`: a scope marked with this flag refuses disposal via `scope_can_be_disposed()` so neither parent-cascade nor `try_to_dispose` can consume its ref. TaskGroup sets the flag in `__construct` / `async_new_group` and clears it in `task_group_dtor_object` before `ZEND_ASYNC_SCOPE_RELEASE`. `curl_async_get_scope()` uses the same pattern for the lazily-created callback scope in `curl_event` — previously it relied on manual `ref_count--` / `try_to_dispose` arithmetic that had the same latent UAF surface. Regression test: `tests/task_group/040-task_group_thread_shutdown_uaf.phpt`.
- **`Async\Timeout::cancel()` double-released the backing object**: Calling `$t->cancel()` disposed the backing timer event, whose `async_timeout_event_dispose()` unconditionally ran `OBJ_RELEASE(object)` assuming the event held a counted reference. In the current architecture the event only stores a raw pointer (`async_timeout_ext_t::std`) without a matching `GC_ADDREF` at creation time, so the release actually decremented the caller's live refcount. The backing object was freed while the userland `$t` variable still pointed to it, and shutdown tripped `IS_OBJ_VALID(object_buckets[handle])` in `zend_objects_store_del()`. Fixed by mirroring `async_timeout_destroy_object()`: `cancel()` now clears `timeout_ext->std` before dispatching the dispose so `async_timeout_event_dispose()` sees a NULL `std` and skips the stray release.
- **`pool_strategy_report_failure()` captured a dangling exception pointer**: When no caller-provided error was available, the helper created a fresh `Exception` via `zend_throw_exception(NULL, "Resource validation failed", 0)` followed immediately by `zend_clear_exception()`. The throw set `EG(exception)` to a refcount-1 object; `clear_exception()` dropped that reference, freeing the exception. The subsequent `ZVAL_OBJ(&error_zval, ex)` captured a dangling pointer that was then handed to the userland `reportFailure()` handler, producing `zend_mm_heap corrupted` and SIGSEGV at shutdown on ZTS DEBUG. Fixed by constructing the exception directly via `object_init_ex(zend_ce_exception)` + `zend_update_property_ex(MESSAGE)`, which never touches `EG(exception)`, and managing the zval lifetime with an `owns_error` flag and an explicit `zval_ptr_dtor()` after the `reportFailure()` call.
- **`Async\Scope::disposeAfterTimeout()` leaked the scope refcount**: The timer callback bumped `callback->scope->scope.event.ref_count` once but nothing in `scope_timeout_callback()` or `scope_timeout_coroutine_entry()` ever released it, so the scope was always held above its natural lifetime — 4 `zend_mm` leaks per invocation in DEBUG. The raw `ref_count++` was replaced with `ZEND_ASYNC_EVENT_ADD_REF` and a custom `scope_timeout_callback_dispose` handler now releases the ref when the callback is freed without firing. On the fire path, `scope_timeout_callback()` transfers ownership to the spawned cancellation coroutine (via `extended_data`); `scope_timeout_coroutine_entry()` calls `ZEND_ASYNC_EVENT_RELEASE` after `SCOPE_CANCEL`. The previously-silent `add_callback` failure path also now releases the ref and frees the unclaimed callback.
- **`Async\CompositeException` wrote to hard-coded `properties_table[7]`**: `async_composite_exception_add_exception()` assumed the `private array $exceptions` typed property lived at slot 7 of the typed-property layout. The real offset for `CompositeException extends \Exception` did not match, so the helper was clobbering an unrelated typed slot: `getExceptions()` on an empty composite hit the "Typed property must not be accessed before initialization" fatal because it was reading the actual (uninitialized) `$exceptions` slot via `zend_read_property`; multiple `addException()` calls produced `var_dump` output with garbage pointer fields and implausible string lengths. Fixed by reading and writing `$exceptions` through `zend_read_property` / `zend_update_property` with the property name, so the engine resolves the correct typed-property slot regardless of inherited layout. `getExceptions()` switched from `silent=0` to `silent=1` (`BP_VAR_IS`) so an empty composite reads back as `[]` rather than triggering the typed-uninit fatal. A second latent bug surfaced while verifying: the PHP method `addException` was passing `transfer=true` to the C helper even though `Z_PARAM_OBJECT_OF_CLASS` only lends a borrowed reference, which caused the stored zval refcount to be one short and made repeated adds alias to the last-inserted object once the slot-7 corruption stopped masking it. Fixed by switching the method call site to `transfer=false` so the helper performs the `GC_ADDREF`.
- **`Async\Timeout::cancel()` assertion at shutdown (`IS_OBJ_VALID`)**: See the first entry above — this is the same bug; leaving it listed because `tests/common/timeout_class_methods.phpt` from coverage phase 2 is the test that exposed it.
- **`TaskGroup::all()`/`race()`/`any()` use-after-free in synchronous-settled path**: The synchronous fast paths created a waiter via `task_group_waiter_future_new()` (which pushes it into `group->waiter_events[]`), resolved it synchronously, wrapped it in a Future wrapper and returned — but never removed it from the `waiter_events[]` vector. The drain path in `task_group_try_complete()` always calls `task_group_waiter_event_remove()` after resolving; the sync path forgot to mirror that. At shutdown, `task_group_free_object()` force-disposed everything still in `waiter_events[]`, which `efree`'d the waiter. When the Future wrapper was then destroyed and released the waiter it had wrapped, it touched freed memory — "Future was never used" warning from a stale `zend_future_t` followed by a segfault whenever user code kept an intermediate `$future = $group->all()` variable across a `try`/`catch`. Fixed by calling `task_group_waiter_event_remove(waiter)` at the end of each synchronous-resolve branch, matching what `task_group_try_complete()` does.
- **`Thread::finally()` on a still-running thread NULL-scope crash**: `thread_object_dtor()` dispatches registered finally handlers via `async_call_finally_handlers()`, which unconditionally dereferences `context->scope` through `ZEND_ASYNC_NEW_SCOPE(context->scope)` and `ZEND_ASYNC_EVENT_ADD_REF(&context->scope->event)`. `thread.c` was passing `context->scope = NULL` because the Thread object had no PHP-side scope of its own, and registering a finally handler on a still-running thread then destroying the thread would segfault at dtor time. Fixed by capturing `ZEND_ASYNC_CURRENT_SCOPE` at spawn time (`async_thread_object_t::parent_scope`) and holding a refcount on the scope event so it outlives the Thread. `thread_object_dtor()` now passes `thread->parent_scope` to the finally dispatcher, so handlers inherit the caller's async context hierarchy (exception handlers, context values) just like `coroutine`/`task_group`/`scope` finally do. Released in `thread_object_free()`. Added `thread_finally_handlers_dtor()` to pair the `GC_ADDREF` that keeps the Thread alive during handler execution with an `OBJ_RELEASE` — previously `context->dtor` was `NULL` and the Thread object leaked 72 bytes every time dtor-time finally ran. A `ZEND_ASYNC_IS_OFF` safety net is kept for the edge case where a Thread object outlives the async subsystem (late `zend_call_destructors` after RSHUTDOWN).

## [0.6.7] - 2026-04-13

### Added
- **PDO Pool: `getAttribute()` support for pool attributes**: `$pdo->getAttribute(PDO::ATTR_POOL_ENABLED)` now returns `true`/`false` depending on whether the connection pool is active. `PDO::ATTR_POOL_MIN` and `PDO::ATTR_POOL_MAX` return the configured pool size limits (or `false` when pooling is disabled). `PDO::ATTR_POOL_HEALTHCHECK_INTERVAL` is a construction-only attribute and raises an error if read at runtime.

### Fixed
- **Heap-use-after-free in `await_all()`/`await_*()` with string keys**: When any `await_*` function received an array with non-interned string keys (e.g. from `json_decode()` or `str_repeat()`), the returned results/errors arrays had incorrect refcount on those keys. The root cause: `async_waiting_callback_dispose` was called twice per callback (once from `zend_async_callbacks_remove` during `del_callback`, once from `ZEND_ASYNC_EVENT_CALLBACK_RELEASE`), but did not check `ref_count` — it unconditionally called `zval_ptr_dtor` on the key each time, decrementing the string refcount twice instead of once. When the calling function's local variables were freed (`i_free_compiled_variables`), the already-freed string was accessed again — heap-use-after-free. Fixed by adding ref_count guard to `async_waiting_callback_dispose`: when `ref_count > 1`, decrement and return without touching resources; only perform cleanup on the final dispose (`ref_count == 1`).

## [0.6.6] - 2026-04-03

### Added
- **PDO Pool: broken connection detection**: Pooled connections that lose server contact or get interrupted (e.g. cancelled coroutine, server restart, DBA kill) are now automatically detected and destroyed instead of being returned to the pool. This prevents the next coroutine from receiving a broken connection ("MySQL server has gone away", "another command is already in progress"). Works for both MySQL and PostgreSQL.
- **PDO Pool: transparent reconnect after broken connection**: When a coroutine catches an error from a broken connection and retries a query on the same `$pdo`, the pool automatically discards the broken connection and acquires a fresh one. No manual reconnection needed.
- **PDO Pool: error state isolation between coroutines**: `$pdo->errorCode()` and `$pdo->errorInfo()` no longer leak error state from one coroutine to another. Each coroutine sees only its own errors.
- **PDO Pool: `errorCode()` returns `"00000"` on first query**: Previously could return `NULL` when multiple coroutines ran their first query concurrently on fresh connections.

### Fixed
- **Heap-use-after-free in DNS resolve on cancellation**: When a coroutine was cancelled while a DNS resolve (`gethostbyname`, database connect) was in flight, the DNS event memory was freed immediately in `dispose()` while the libuv thread pool callback was still pending. When libuv later invoked the callback, it accessed freed memory — crash or corruption. Fixed by deferring the free to the libuv callback itself: `dispose()` sets a `DISPOSE_PENDING` flag and the callback checks it on completion, taking ownership of the memory cleanup.
- **Pool `max_size` not enforced during concurrent connection creation**: When multiple coroutines tried to open connections simultaneously (e.g. on application startup), the pool could create more connections than `max_size` allowed. Now the limit is strictly enforced — excess coroutines wait until a connection becomes available.
- **`Scope::awaitCompletion()` not marking cancellation Future as used**: The cancellation token passed to `awaitCompletion()` was never marked with `RESULT_USED` / `EXC_CAUGHT`, causing a spurious "Future was never used" warning when the Future was destroyed. Additionally, early return paths (scope already finished, closed, or cancelled) skipped the marking entirely. Fixed by setting flags immediately after parameter parsing, before any early returns.
- **`Scope::awaitAfterCancellation()` not marking cancellation Future as used**: Same issue as `awaitCompletion()` — the optional cancellation Future was only marked when the method reached `resume_when`, but early returns bypassed it. Fixed identically.
- **Heap-use-after-free in `stream_socket_accept()` during coroutine cancellation**: When a coroutine blocked in `stream_socket_accept()` was cancelled during graceful shutdown, `network_async_accept_incoming()` extracted the exception's message string into `*error_string` without incrementing its refcount (`*error_string = Z_STR_P(message)`). The caller then called `zend_string_release_ex()`, freeing the string while the exception object still referenced it. On exception destruction, `zend_object_std_dtor` accessed the freed string — heap-use-after-free. Fixed by using `zend_string_copy()` to properly addref the borrowed string. Same bug existed in the synchronous path `php_network_accept_incoming_ex()` in `main/network.c` — fixed there too.

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
