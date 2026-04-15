# TrueAsync Coverage Report

Session wrap-up for the gcov-driven test-writing pass against
`ext/async/`. Companion to `COVERAGE_PLAN.md`, which held the initial
baseline survey and the raw per-file gap tables.

## 1. Headline numbers

| Metric | Baseline | After P0 (curl/mysqli rebuild) | Final | Δ from baseline |
| --- | --- | --- | --- | --- |
| **Lines** | 74.30% (8756 / 11785) | 75.00% | **77.45% (9127 / 11785)** | **+3.15% (+371 lines)** |
| **Functions** | 85.30% (774 / 907) | 86.30% | ~88% | +2.7% |
| **Tests passing** | 804 | 908 | 920+ | +120 |

Build: `./configure --enable-zts --enable-debug --disable-all --enable-async
--enable-pdo --with-pdo-mysql --with-pdo-sqlite --with-pdo-pgsql --with-curl
--with-openssl --with-mysqli=mysqlnd --enable-sockets --enable-posix
--enable-pcntl --enable-gcov`

Working copy of the build with gcda: `/home/edmond/build-gcov-src/`.
HTML report: `build-gcov-src/coverage_html/index.html`.

## 2. Per-file outcome

| File | Baseline | Final | Δ | Note |
| --- | --- | --- | --- | --- |
| `scope.c` | 71.6% | **78.9%** | +7.3% | 13 tests — the largest topical batch |
| `exceptions.c` | 56.3% | **65.0%** | +8.7% | 2 tests + rest is API-only dead code |
| `pool.c` | 76.7% | **85.9%** | +9.2% | 4 tests — healthcheck + error paths |
| `async.c` | 76.5% | **84.5%** | +8.0% | 5 tests — Timeout/signal/shutdown |
| `thread.c` | 73.3% | **78.8%** | +5.5% | 1 test — status accessors |
| `task_group.c` | 81.5% | **84.0%** | +2.5% | 1 test — gc traversal |
| `context.c` | 87.8% | **90.7%** | +2.9% | 1 test — 3-level hierarchy |
| `libuv_reactor.c` | 62.3% | 62.7% | +0.4% | mostly UDP/diagnostic paths, see §5 |
| `thread_pool.c` | 80.4% | 80.4% | 0 | 1 test covered op_array paths but no line-count shift |
| `future.c` | 80.4% | 80.4% | 0 | still best-candidate for next pass |
| `coroutine.c` | 76.5% | 76.5% | 0 | C-API-only helpers dominate the gap |
| `scheduler.c` | 77.0% | 79.2% | +2.2% | inherited from scope/pool tests |

Unchanged files (all ≥85% or too small to matter):
`iterator.c 85.3%`, `channel.c 86.7%`, `thread_channel.c 92.4%`,
`internal/allocator.c 100%`.

## 3. Tests added (28 in 5 commits)

Commits, in order, on branch `98-thread-pool`:

1. **`9f56fb0` — scope (13 tests, 043–055)**
   - 043 re-set `setExceptionHandler`/`setChildScopeExceptionHandler`
   - 044 `awaitAfterCancellation` on a non-cancelled scope
   - 045 self-deadlock in `awaitCompletion`
   - 046 self-deadlock in `awaitAfterCancellation`
   - 047 `setExceptionHandler` handler actually fires
   - 048 `setChildScopeExceptionHandler` handler actually fires
   - 049 `awaitAfterCancellation(errorHandler)` runs the handler
   - 050 `awaitAfterCancellation()` without handler propagates
   - 051 self-deadlock from a grandchild scope (recursive walker)
   - 052 `gc_get` walks scope context values and object keys
   - 053 destroy scope object with active coroutines
   - 054 error handler may throw and its exception propagates
   - 055 `finally()` on a disposed scope fires synchronously

2. **`bafb9f6` — exceptions (2 tests, edge_cases/012–013)**
   - 012 `awaitCompletion()` on a cancelled scope → `async_throw_cancellation`
   - 013 direct `CompositeException::addException()` / `getExceptions()`

3. **`9fb1f30` — pool (4 tests, 048–051)**
   - 048 periodic healthcheck (`pool_call_healthcheck` + timer callback)
   - 049 healthcheck callback throwing → resource treated as unhealthy
   - 050 factory throwing during min-size prewarm
   - 051 `beforeAcquire` rejection + destructor throwing aborts acquire

4. **`cc5ea17` — thread / task_group / context (4 tests)**
   - `task_group/035` gc_get handler walks tasks in PENDING/RUNNING/ERROR
   - `thread/041` Thread status accessors + finally() fast-path
   - `thread_pool/031` closure with try/catch/static/nested fns
   - `context/007` three-level scope hierarchy context walker

5. **`e065c89` — async.c surface area (5 tests)**
   - `info/003` `phpinfo()` → `PHP_MINFO_FUNCTION(async)`
   - `common/timeout_class_methods` Async\Timeout methods + factory
   - `signal/004` `Async\signal()` fast path for resolved cancellation
   - `edge_cases/014` explicit `Async\graceful_shutdown()`
   - `iterate/type_error_invalid_argument` `Async\iterate` TypeError branch

## 4. Bugs discovered via coverage testing

Three real defects were surfaced by tests that were trying to land on
previously-unreached lines.

### 4.1 `Async\Scope::disposeAfterTimeout()` leaks the scope refcount

**Location:** `scope.c:675`
```c
callback->scope = scope_object->scope;
callback->scope->scope.event.ref_count++;   // ← no paired decrement
```

The timer callback bumps the scope's `ref_count` once, but nothing in
either `scope_timeout_callback()` (L618–634) or
`scope_timeout_coroutine_entry()` (L601–616) releases it. Regardless of
whether the timer fires or the scope empties first, one reference is
always leaked.

**Effect in DEBUG:** 4 consistent zend_mm leaks per invocation
(`scope.c:38`, `zend_string.h:166`, `zend_objects.c:190`,
`scope.c:1208`) — observable by hand but not currently caught by any
phpt.

**Coverage impact:** blocks test 043
(`043-scope_disposeAfterTimeout_with_active_coroutine`, removed after
discovery) and thereby ~52 lines in `scope.c:601-684` — the full
`disposeAfterTimeout` timer pathway.

### 4.2 `async_composite_exception_add_exception` writes to hard-coded slot 7

**Location:** `exceptions.c:258`
```c
zval *exceptions_prop = &composite->properties_table[7];
```

The helper assumes `exceptions` lives at `properties_table[7]`. With
the typed-property layout actually produced for
`Async\CompositeException extends \Exception { private array
$exceptions; }` this slot does not line up with the `exceptions`
property, so:

- `getExceptions()` on an empty composite hits the "Typed property must
  not be accessed before initialization" fatal.
- Multiple `addException()` calls clobber unrelated properties and
  produce `var_dump` output with garbage pointer fields and
  implausible string lengths.

**Coverage impact:** test 013 had to be trimmed to a single-add
scenario; the multi-add iteration branch (~3 lines) is blocked.

### 4.3 `pool_strategy_report_failure` use-after-free

**Location:** `pool.c:348-355`
```c
zend_object *ex = zend_throw_exception(NULL, "Resource validation failed", 0);
zend_clear_exception();
ZVAL_OBJ(&error_zval, ex);   // ← ex is already freed here
```

`zend_throw_exception()` sets `EG(exception)` to a refcount-1 object.
`zend_clear_exception()` drops that reference, freeing the exception.
The subsequent `ZVAL_OBJ(&error_zval, ex)` captures a dangling
pointer, which is then handed to the userland `reportFailure()` handler.

**Reproducer:** construct a pool with a strategy *and* a `beforeRelease`
callback that returns `false`, then acquire + release one resource.
`zend_mm_heap corrupted` + SIGSEGV at shutdown on a ZTS DEBUG build.
Minimal repro also exists (~15 lines of PHP).

**Coverage impact:** blocks the entire
`pool_strategy_report_failure` function (~24 lines in `pool.c:322-364`)
plus indirect coverage of the strategy-failure wiring.

**Suggested fix for all three:** see §7.

## 5. Dead zones — where coverage cannot realistically climb

This is the important part. I walked every remaining uncovered region
(>3 contiguous lines) and classified it. The phpt-only ceiling is
~83–84 %.

### 5.1 API exports with no internal callers (~220 lines, HARD DEAD)

`PHP_ASYNC_API` functions exist for drivers/extensions that consume
TrueAsync, but nothing inside `ext/async` calls them.

| File | Functions | Lines |
| --- | --- | --- |
| `exceptions.c` | `async_throw_timeout`, `async_throw_poll`, `async_throw_deadlock` (each has a 2-branch implementation) | ~66 |
| `zend_common.c` | `zend_exception_to_warning`, `zend_current_exception_get_{message,file,line}`, `zend_exception_merge`, `zend_new_weak_reference_from`, `zend_resolve_weak_reference`, `zend_hook_php_function`, `zend_replace_method`, `zend_get_function_name_by_fci` | 101 |
| `coroutine.c` | `async_coroutine_context_{set,get,has,delete}` C API | 50 |

Coverable only by (a) linking a test-only C extension that calls each
entry point, or (b) deleting the dead code.

### 5.2 Diagnostic `*_info()` describers (~160 lines, SOFT DEAD)

These are the per-event-type string formatters that show up in the
deadlock report (`scheduler.c:dump_deadlock_info`). They are only
called when the scheduler actually detects a deadlock **and** no
zombie-resolution path can progress.

| File | Functions | Lines |
| --- | --- | --- |
| `libuv_reactor.c` | `libuv_*_info` for poll/timer/signal/process/filesystem/dns/exec/io/listen/task/trigger | 108 (L185-310) |
| `scope.c` | `scope_info` | ~14 |
| `task_group.c` | `task_group_info` | ~6 |
| pool/future info | | ~20 |

These *could* in theory be hit by a phpt that triggers a real deadlock
with `async.debug_deadlock=1`. In practice the scheduler's zombie
optimisation auto-completes most phpt-reachable deadlock setups before
the report is produced. A three-way cycle using a scope event kept
failing to deadlock for me because the scope completes as soon as its
only coroutine becomes a zombie waiting on a peer. Coverage-by-test
here is possible, but fragile.

### 5.3 `replay()` event-callback entry points (~60 lines, HARD DEAD)

`scope_replay` (L1039–1067), `task_group_replay`, etc. These are the
C-level mechanism used by extensions that want to attach a late
callback to an already-finished event. No userland surface.

### 5.4 Standalone C tests for `circular_buffer` (~40 lines, HARD DEAD)

`circular_buffer_new`, `circular_buffer_destroy`,
`circular_buffer_capacity`, `zval_circular_buffer_new` are only called
from `ext/async/internal/tests/circular_buffer_test.c`, a standalone C
test that has its own `CmakeLists.txt` and is not linked into PHP
itself. From a phpt perspective they are unreachable; the embedded
`circular_buffer_ctor` used by pool/channel is fully covered.

### 5.5 Fake `scope_object` bridge (~14 lines, HARD DEAD)

`scope.c:1506–1522` creates a temporary `async_scope_object_t` when an
exception is raised against a scope whose PHP object has already been
garbage-collected. Reaching it requires holding an internal-scope
reference past the PHP object destruction, which is not possible from
userland without a specifically crafted GC cycle that the PHP engine
will not actually produce.

### 5.6 Pool C-API destruction paths (~50 lines, HARD DEAD)

`pool.c:977–1099` — `zend_async_pool_destroy()` with
`ZEND_ASYNC_POOL_F_*_INTERNAL` flags, and
`async_pool_create_object_for_pool()` for embedded pool wrappers. Both
only run when a non-async-module driver (curl, pdo_mysql) owns the
pool.

### 5.7 OS-error branches in libuv reactor (~150 lines, FAULT-INJECTION)

Each `if (uv_*_init(...) < 0)` / `uv_*_open < 0` branch across
`libuv_reactor.c` (TCP/UDP/pipe/TTY init, fileno, bind, fsync start,
listen binding, etc.). Reachable in principle with bad file
descriptors or privileged ports, but fragile from phpt — most of them
need an unprivileged non-root environment and genuinely bad inputs.

### 5.8 Bailout / critical-exception paths (~120 lines, HARD DEAD)

`async.c:814–987` (partial), `scope.c:858-1121`, `scheduler.c:842-856`.
These run when `zend_bailout()` is invoked mid-operation (e.g., fatal
error during a coroutine dispose). phpt has no controlled way to
trigger a bailout that the engine will not then treat as a test
failure.

### 5.9 Private `__construct` C guards (~6 lines, HARD DEAD)

Both `Async\Thread::__construct` and `Async\Timeout::__construct` throw
"Cannot directly construct …". Because the stub declares them
`private`, PHP's visibility check fires first with a different error
and the C body is never reached.

### 5.10 Defensive "should not happen" branches (~30–50 lines spread out)

Scattered `ZEND_ASSERT`-style early returns and warnings that guard
internal state the runtime should never produce. These are not bugs,
they are belt-and-braces code. Most common in
`circular_buffer.c:414-420`, `future.c`, and `scheduler.c` finalisation
paths.

## 6. Still achievable (next pass budget: ~150–200 lines)

Everything below is reachable with more phpt effort.

| Target | File | Approx lines | Notes |
| --- | --- | --- | --- |
| finally handlers chain | `future.c:1192–1252` | ~60 | biggest single achievable block |
| TaskGroup cancel/race/error paths | `task_group.c:243–1457` | ~40 across 5 spots | needs targeted tests for `race()`/`any()` with failed tasks |
| `Async\iterate` error paths | `async.c:859–987` | ~50 | iterator creation failures + cancel_pending merges |
| Thread internals | `thread.c:1957–2172` | ~40 | needs tests for `getResult`/`getException` on a still-running thread, etc. |
| Channel close/timeout | `channel.c:322–778` | ~40 | several close-race edge cases |
| fs_watcher coalesce RENAME+CHANGE | `fs_watcher.c:141–246` | ~20 | two events on same path within coalesce window |
| thread_pool submit-after-close race | `thread_pool.c:483–592` | ~15 | very tight race window |
| Context find-local error branch | `context.c:34–38, 126–132` | ~10 | mostly covered now |

After landing the full achievable budget, realistic ceiling is **~80%**.

## 7. Recommended next steps

Ordered by ROI, highest first.

1. **Fix the three bugs found in §4** (Opus-4 session list).
   Fixing `disposeAfterTimeout` unlocks ~52 lines;
   fixing `pool_strategy_report_failure` unlocks ~24 lines;
   fixing `composite_exception properties_table[7]` unlocks ~5 lines
   and removes a memory corruption.

2. **Delete the mostly-dead C API exports in §5.1** (or wire them into
   an `ext/async/tests/capi_probe/` test extension). Deleting is
   cheaper: `async_throw_timeout/poll/deadlock` genuinely have no
   internal callers, and most of `zend_common.c` is the same.

3. **Write the achievable batch in §6** — targets in `future.c`,
   `task_group.c`, `async.c` iterate, `thread.c`, `channel.c`. This is
   the last chunk that pays off with phpt-only work.

4. **Consider teaching `make test` to run
   `ext/async/internal/tests/circular_buffer_test.c`** — it already
   exists as a standalone C test with full coverage of the `_new` /
   `_destroy` / `_capacity` API. Linking it into the PHP test harness
   would close ~40 lines in `circular_buffer.c` for free.

5. Everything beyond that requires fault injection, a deadlock-debug
   phpt framework, or tolerating bailout behaviour in run-tests. Not
   worth the engineering churn unless a specific customer bug pushes
   us there.

## 8. How to reproduce the measurement

```bash
cd /home/edmond/build-gcov-src

# Reset coverage counters
find . -name '*.gcda' -delete

# Run the full async suite
MYSQL_TEST_USER=test MYSQL_TEST_PASSWD=test \
MYSQL_TEST_SOCKET=/var/run/mysqld/mysqld.sock MYSQL_TEST_HOST=localhost \
make test TESTS='ext/async/tests'

# Capture + summary
lcov --capture --directory ext/async --output-file coverage_async.info --no-external
lcov --summary coverage_async.info

# HTML
rm -rf coverage_html
genhtml coverage_async.info --output-directory coverage_html \
    --prefix /home/edmond/build-gcov-src
```
