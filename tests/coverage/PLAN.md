# TrueAsync: Coverage Analysis & Test Plan

Baseline (build: `--enable-gcov --enable-zts --enable-debug --disable-all --enable-async --enable-pdo --with-pdo-mysql --with-pdo-sqlite --enable-sockets --enable-posix --enable-pcntl`):

- **Lines:** 74.3% (8756 / 11785)
- **Functions:** 85.3% (774 / 907)
- **Tests:** 804 passed, 156 skipped, 1 xfail-warned, 0 failed
- Report: `/home/edmond/build-gcov-src/coverage_html/index.html`
- Data: `/home/edmond/build-gcov-src/coverage_async.info`

## 1. Per-file coverage (sorted by %)

| File                           | Lines    | Missing | Funcs  |
| ------------------------------ | -------- | ------- | ------ |
| zend_common.c                  | **25.7%** | 101     | 4/13   |
| exceptions.c                   | **56.3%** | 80      | 14/20  |
| libuv_reactor.c                | **62.3%** | **791** | 120/158|
| internal/circular_buffer.c     | 64.8%    | 63      | 15/19  |
| pool.c                         | 71.2%    | 198     | 47/55  |
| scope.c                        | 71.6%    | 221     | 45/49  |
| async_API.c                    | 72.6%    | 157     | 23/32  |
| thread.c                       | 73.3%    | 306     | 61/72  |
| coroutine.c                    | 76.5%    | 174     | 44/49  |
| async.c                        | 76.5%    | 179     | 37/44  |
| scheduler.c                    | 77.0%    | 177     | 34/36  |
| future.c                       | 80.4%    | 188     | 67/76  |
| thread_pool.c                  | 80.4%    | 68      | 19/21  |
| task_group.c                   | 81.5%    | 154     | 70/76  |
| fs_watcher.c                   | 84.7%    | 34      | 19/21  |
| iterator.c                     | 85.3%    | 38      | 16/17  |
| channel.c                      | 86.7%    | 52      | 39/44  |
| context.c                      | 87.8%    | 21      | 19/20  |
| thread_channel.c               | 92.4%    | 19      | 24/27  |

## 2. Root causes of missing coverage

Gaps cluster into six categories. Each category maps to a set of concrete tests below.

### A. Modules disabled in build → tests SKIP-ped (156 tests)

The gcov build uses the repo's `config.nice` as-is (only `--enable-gcov` added). That means tests under these dirs almost all SKIP:

- `ext/async/tests/curl/*` — no `--with-curl`
- `ext/async/tests/mysqli/*` — no `--with-mysqli`
- `ext/async/tests/pdo_pgsql/*` — no `--with-pdo-pgsql`
- `ext/async/tests/dns/*`, partial `stream/*` (UDP) — partly covered by libuv anyway
- `ext/async/tests/exec/*` — gated on `pcntl`/`posix`, built, but some sub-scenarios skip

**Impact on coverage:** indirectly hurts `libuv_reactor.c`, `exceptions.c` (I/O error constructors), and some `async.c` ini/integration paths.

**Action A1:** rebuild with `--with-curl --with-mysqli --with-pdo-pgsql` and rerun report.
Expected bump: ~3–5% overall lines, biggest gain in `libuv_reactor.c` DNS / poll / exec paths.

### B. Deadlock / diagnostics mode never exercised

Several large gaps are "dump state for debugging" helpers that only run when `ASYNC_G(debug_deadlock)` INI is on.

| Location | What's untested |
|---|---|
| `libuv_reactor.c:185-310` (126 lines) | `libuv_poll_info`, `libuv_timer_info`, `libuv_signal_info`, `libuv_process_info`, `libuv_filesystem_info`, `libuv_dns_*_info`, `libuv_exec_info`, `libuv_trigger_info`, `libuv_io_info`, `libuv_listen_info`, `libuv_task_info`, `io_type_name` — all `*_info()` event describers for deadlock reports |
| `scope.c:1070-1083` | `scope_info()` describer |
| `scheduler.c:574-642` | `print_deadlock_report()` — the whole deadlock reporter that walks waiting coroutines and prints their events |
| `scheduler.c:842-856` | The auto-stop branch after deadlock detection |

**Action B1:** `tests/info/002-deadlock_report.phpt` — INI `async.debug_deadlock=1`, start two coroutines that mutually await each other, let scheduler detect the deadlock, assert the printed report mentions both coroutines and their event types.

**Action B2:** `tests/info/003-event_info_strings.phpt` — for each event kind (Timer, Signal, DNS, Exec, IO-TCP/UDP/pipe/file/TTY, Listen, Process, Filesystem watcher, Task, Trigger, Poll): create one, force it into the deadlock waiting list, dump the info string. One test, many sub-cases. Covers `libuv_*_info` family and `scope_info`.

### C. UDP + socket-option paths unexercised

`libuv_reactor.c:4700-4958` (~250 lines) contains:
- `udp_send_cb`, `udp_recv_alloc_cb`, `udp_recv_cb`
- `libuv_udp_sendto`, `libuv_udp_recvfrom`
- `libuv_io_set_option` (TCP `NODELAY`, `KEEPALIVE`; UDP `BROADCAST`, `MULTICAST_LOOP`, `MULTICAST_TTL`, `TTL`)
- `libuv_udp_set_membership` (multicast join/leave)

There **are** UDP tests (`stream/028-030`, `socket_ext/003`, `socket/003`) — but they all SKIP in the current build (no `--enable-sockets`... wait, sockets IS enabled, but those phpt files may gate on `ext/sockets`). Confirm after rebuild.

**Action C1:** rebuild and re-measure; most of this block should then go green.

**Action C2:** if still red after rebuild, add:
- `tests/io/040-udp_broadcast.phpt` — `setOption(BROADCAST, 1)` + `setOption(BROADCAST, 0)`
- `tests/io/041-udp_multicast_ttl.phpt` — `setOption(MULTICAST_TTL, 32)`, `setOption(MULTICAST_LOOP, 0)`
- `tests/io/042-udp_multicast_membership.phpt` — join + leave multicast group `239.x`
- `tests/io/043-tcp_socket_options.phpt` — `NODELAY`, `KEEPALIVE`
- `tests/io/044-socket_option_invalid_type.phpt` — set UDP option on TCP and vice versa (hits the `default` branches)

### D. libuv init/open **error paths** — require faulty OS state

Dozens of 3–8 line gaps in `libuv_reactor.c` are the `if (uv_*_init(...) < 0)` and `uv_fileno` error branches. Examples:

- L4005-4008: `uv_pipe_open` fails
- L4013-4029: `uv_tty_init` fails
- L4035-4056: `uv_tcp_init` + `uv_tcp_open` fail
- L4050-4056: `uv_udp_init` fails
- L4397-4420: `uv_fs_fsync` start fails
- L4452-4517: listen bind / ipv6 parse / `uv_fileno` failures

These are hard to hit without fault injection — passing garbage FDs or closed handles is the usual trick.

**Action D1:** `tests/io/045-open_closed_fd.phpt` — `fclose($f)`, then wrap the numeric fd in an async IO and assert it throws. Covers `uv_pipe_open` / `uv_tcp_open` error branches.

**Action D2:** `tests/io/046-tty_on_regular_file.phpt` — open a regular file as TTY → `uv_tty_init` returns error. (Expected output: `Failed to initialize TTY handle:`)

**Action D3:** `tests/io/047-listen_bad_address.phpt` — `Async\listen("::zz::", 0)` invalid ipv6. Covers `uv_ip6_addr` error branch.

**Action D4:** `tests/io/048-listen_privileged_port.phpt` (skip-if-root) — bind to port 80 as non-root → `uv_tcp_bind` fails.

**Action D5:** `tests/io/049-fsync_on_pipe.phpt` — call `fflush`/`fsync` on a non-file handle — asserts the "Pipes and TTYs have no disk buffer" fast-return branch (L4397-4404).

**Note:** remaining 3-line error branches (maybe 15-20 spots) will not be realistic to hit without fault injection. Accept as known residual.

### E. Stderr branch of exec is untested

`libuv_reactor.c:3169-3194` — `exec_std_err_alloc_cb` and `exec_std_err_read_cb`. `tests/exec/*` likely only capture stdout.

**Action E1:** `tests/exec/010-capture_stderr.phpt` — spawn `sh -c 'echo err 1>&2'`, await, assert `std_error` captured.

**Action E2:** `tests/exec/011-stdout_stderr_interleaved.phpt` — both streams, ensure interleaved capture works (also covers L3180-3190 nread<0 close branch).

### F. API helpers used **only from C extensions**, not PHP userland

Large blocks of unreached code are C API shims intended for other extensions (mysqli/curl/pgsql) to plug into the TrueAsync API.

| File | Lines | Functions |
|---|---|---|
| `coroutine.c:1077-1126` | 50 | `async_coroutine_context_{set,get,has,delete}` — C API for context |
| `async_API.c:1171-1197` | 27 | `async_pool_try_acquire_wrapper`, `async_pool_release_wrapper`, `async_pool_close_wrapper` — pool-API shims |
| `async_API.c:165-317` | ~60 | `zend_async_*_register` validators (already-registered error paths) |
| `zend_common.c:25-246` | 101 | `zend_exception_to_warning`, `zend_current_exception_get_{message,file,line}`, `zend_exception_merge`, `zend_new_weak_reference_from`, `zend_resolve_weak_reference`, `zend_hook_php_function`, `zend_replace_method`, `zend_get_function_name_by_fci` |

These are impossible to cover via phpt alone. Options:
1. Accept as residual — it's exercised only when another extension actually uses the C API.
2. Rebuild with `--with-curl --with-mysqli --with-pdo-pgsql`. curl integration in particular uses `async_coroutine_context_*` for per-coroutine state — should close most of **coroutine.c** gap.
3. Write a tiny test-only C extension `ext/async/tests/capi_probe/` that calls each C API entrypoint and asserts return values — only worth doing if we want a hard 95%+ number.

**Action F1 (cheap):** rebuild with curl + mysqli + pdo_pgsql (covers 1 & 2). Revisit the zend_common.c numbers after.

**Action F2 (optional):** the capi_probe test extension.

### G. Feature edge cases with partial coverage

These are scenarios where the main path is hit but a side branch isn't.

**scope.c:**
- L1039-1068: `scope_replay()` — scope replay after completion (used by persistent awaiters). Test: `tests/scope/0xx-scope_replay_after_finish.phpt` — finish a scope, subscribe a new callback, assert it gets UNDEF/NULL.
- L1535-1598: **child-scope exception handler** — when a nested scope's exception is handled by the parent's `setChildExceptionHandler`. Test: `tests/scope/0xx-child_exception_handler.phpt` — parent with setChildExceptionHandler, child throws, assert parent handler runs and parent survives.
- L601-684, 1063-1082: scope cancellation through nested scopes with exception handlers registered.

**thread.c:**
- L400-450: closure transfer branches for `op_array` fields — `static_variables`, `literals`, `arg_info` with return type, `live_range`, `doc_comment`, `attributes`, `try_catch_array`, `vars`, `dynamic_func_defs`.
  - Tests already cover simple closures; need `tests/thread_pool/031-closure_with_all_oparray_fields.phpt`: closure with typed params, typed return, try/catch inside, static locals, attributes, nested function definitions. One test can flip ~40 lines.
- L2122-2361 (~170 lines): method implementations for `getResult`, `getException`, `cancel`, probably also statics. Need `tests/thread/0xx-getResult_before_join.phpt`, `tests/thread/0xx-getException_after_throw.phpt`, `tests/thread/0xx-cancel_noop.phpt` (cancel currently returns false — test the TODO behavior).
- L207-344: thread pool lifecycle branches (queue close while draining, etc).

**pool.c:**
- L540-657, 756-789: **healthcheck** path — `pool_healthcheck_callback_dispose`, `pool_healthcheck_timer_callback`, the actual healthcheck call into user fcall. No test exercises healthcheck currently.
  - Test: `tests/pool/0xx-healthcheck_healthy.phpt` — create pool with healthcheck returning true, acquire, release, wait, acquire again.
  - Test: `tests/pool/0xx-healthcheck_reject.phpt` — healthcheck returns false → resource discarded on acquire.
  - Test: `tests/pool/0xx-healthcheck_throws.phpt` — healthcheck throws → treated as unhealthy.
  - Test: `tests/pool/0xx-healthcheck_interval.phpt` — periodic healthcheck timer ticks.
- L977-1017: `zend_async_pool_destroy` custom fcall release branches — paths where factory/destructor/healthcheck/before_acquire/before_release **are** user fcalls (not INTERNAL flag).
  - Already partly covered — needs a test with all 5 user callbacks set to close branches `L994-1008`.
- L1050-1099: `async_pool_create_object_for_pool` — also C API, same as category F.

**async.c:**
- L814-826, 859-907, 920-987: cleanup on critical error / bailout — the "scheduler abort" paths.
- L1167-1315, 1358-1439: INI handlers / MINFO registration / module shutdown edge cases.
  - Test: `tests/info/0xx-phpinfo_async_section.phpt` — assert `phpinfo()` shows async section with expected keys. Covers the MINFO block.
  - Test: `tests/info/0xx-ini_debug_deadlock.phpt` — flip the INI at runtime via `ini_set`.

**scheduler.c:**
- L91-109: early-start branch when reactor already initialized.
- L704-752: finalization on shutdown with pending coroutines.
- L884-919, 1208-1307: edge cases in `scheduler_wait_for_event` — timeout-driven exits.
  - Test: `tests/scheduler/0xx-shutdown_with_pending.phpt` — spawn a coroutine that awaits forever, let the request end, assert clean shutdown + warning.

**task_group.c:** 
- 154 missing lines in 92% func-coverage file → mostly branch misses inside partially-covered functions. Targets:
  - Partial cancellation (L-ranges in the middle of the file) — race between `cancel()` and `await`.
  - Test: `tests/task_group/0xx-cancel_during_await.phpt`.

**future.c:** 
- 188 missing lines → mostly error-state propagation branches.
  - `tests/future/0xx-await_already_rejected.phpt`
  - `tests/future/0xx-await_with_cancellation_object.phpt` (non-null cancellation source)
  - `tests/future/0xx-double_reject.phpt` — second reject should be ignored.

**channel.c:**
- 52 missing lines, 5 untested methods — likely destructor races and `tryRecv`/`trySend` branches on a closed channel.

**exceptions.c:** (category B above mostly) +
- L41-46, L138-221, L268-282: context-less branches of `async_throw_{cancellation,input_output,timeout,poll,deadlock}` — only reachable when `EG(current_execute_data) == NULL` (i.e. exception constructed from a callback outside any PHP frame).
  - These are typically hit indirectly from libuv callbacks. A test that forces a poll error in a non-userland frame (e.g., a timer callback that throws) would cover them.
  - Test: `tests/edge_cases/0xx-exception_from_native_callback.phpt` — signal handler registered then the signal arrives during idle.

**internal/circular_buffer.c:**
- L54-94: capacity-zero / grow-on-empty branches.
- L297-318, L414-420: ring wrap + shrink branches.
  - Test: `tests/channel/0xx-buffer_wrap_extreme.phpt` — capacity 3, push/pop 100 times, assert FIFO holds.
  - Test: `tests/channel/0xx-buffer_resize_full.phpt` — fill, send one more (should block), drain half, send → ensure wrap.

## 3. Prioritized action list

**P0 — biggest bang per phpt (rebuild only):**
1. Rebuild with `--with-curl --with-openssl --enable-mysqli --with-mysqli=mysqlnd --with-pdo-pgsql`. Rerun. Projected +4-6% lines (categories A + partial C + partial F).

**P1 — small phpt batch, big gain (~20 new tests):**
2. Info/deadlock tests (category B1+B2): +~200 lines.
3. Socket options + UDP options + invalid-option (C2): +~80 lines.
4. Exec stderr capture (E1+E2): +~30 lines.
5. Pool healthcheck suite (pool.c healthcheck): +~60 lines.
6. Scope child-exception + replay (scope.c): +~70 lines.
7. Thread `getResult` / `getException` / `cancel` method tests: +~50 lines.
8. Circular buffer wrap/resize edge cases: +~30 lines.
9. Closure with full op_array feature set: +~45 lines.
10. Future rejection edge cases: +~40 lines.

**P2 — error injection tests:**
11. Bad-fd / bad-address libuv init failures (D1–D5): +~50 lines.

**P3 — optional / low ROI:**
12. C API probe extension for category F (zend_common.c, coroutine.c context API, pool wrappers): requires new test extension.
13. Residual 3-line OS-error branches — accept as known.

## 4. Projected coverage after P0+P1+P2

- After P0: **~78-80%** lines, **~88%** functions.
- After P1: **~87-90%** lines, **~93%** functions.
- After P2: **~91-92%** lines.
- P3 cap (realistic): **~94-95%**. Anything higher requires deleting dead code or adding gcov-excludes for info dumps / OS-error branches.

## 5. How to re-measure

```bash
cd /home/edmond/build-gcov-src
# reset counters
find . -name '*.gcda' -delete
# rerun tests
make test TESTS='ext/async/tests'
# capture + summary
lcov --capture --directory ext/async --output-file coverage_async.info --no-external
lcov --summary coverage_async.info
# html
rm -rf coverage_html
genhtml coverage_async.info --output-directory coverage_html --prefix /home/edmond/build-gcov-src
```
