# Coverage Progress — Phase 2

Tracks the phpt-only "achievable budget" from `COVERAGE_REPORT.md` §6
(realistic ceiling ~80%). This file is updated as blocks land so the
next session can pick up where the previous one stopped.

Start of phase: 77.45% lines / 88% functions (from §1 of the report).

End of phase: **78.34% lines / 89.1% functions** (+0.89% lines,
+104 lines of 11785; +1.1% functions, +10 functions).

## Plan

| # | Target | File | Budget | Status |
|---|---|---|---|---|
| 1 | finally handlers chain | `future.c:1192–1252` | ~60 lines | **DONE** (+5.42% → 85.80%) |
| 2 | `Async\iterate` + small `async.c` gaps | `async.c` | ~50 lines | **DONE** (+1.58% → 86.07%) |
| 3 | TaskGroup cancel/race/error | `task_group.c:243–1457` | ~40 lines | **DONE** (+2.17% → 86.18%) |
| 4 | Channel iterator paths | `channel.c` | ~40 lines | **DONE** (+2.30% → 89.00%) |
| 5 | Thread internals | `thread.c:1957–2172` | ~40 lines | **DONE** (+0.17%, mostly unreachable) |
| 6 | fs_watcher coalesce | `fs_watcher.c:141–246` | ~20 lines | **SKIPPED** (libuv event ordering) |
| 7 | thread_pool submit-after-close | `thread_pool.c:483–592` | ~15 lines | **DONE** (+0.58% → 80.98%) |
| 8 | Context find-local | `context.c:34–38,126–132` | ~10 lines | **DONE** (+1.16% → 91.86%) |

## Log

Entries appended as blocks land. Format: date, target, tests added,
commit hash, notes.

### 2026-04-15 — future.c (target #1)

`future.c` 80.38% → **85.80%** (+5.42% lines, ~52 lines of 958).

Tests added (7, future/031–037):

- **031** `isCompleted()` / `isCancelled()` across pending / completed /
  rejected-with-Exception / rejected-with-AsyncCancellation.
- **032** `cancel()` with default cancellation, custom cancellation,
  and short-circuit on already-completed (no-op branch).
- **033** `getAwaitingInfo()` returns a 1-element array containing the
  zend_future_info() string; asserts "pending" → "completed" transition.
- **034** `FutureState::complete()` / `error()` already-completed error
  paths — exercises the `FutureState is already completed at %s:%d`
  branch via double-complete, error-after-complete, complete-after-error.
- **035** `Future::finally()` with a rejected parent where the handler
  itself throws — hits `zend_exception_set_previous()` so the thrown
  exception's ->previous is the original parent exception.
- **036** `map()` / `catch()` / `finally()` TypeError on non-callable
  argument.
- **037** `finally()` on already-completed / already-failed futures —
  exercises the eager-spawn branch in `async_future_create_mapper`
  (L1672–1697) for `Future::completed()` and `Future::failed()`.

Remaining gaps in future.c (~14% of 958):
- `FUTURE_STATE_METHOD` getAwaitingInfo / getCompleted* on destroyed state
- `Future::await` with `cancellation_event` argument (~40 lines)
- `iterator.c`/`future_iterator_*` error paths (~30 lines, fragile)
- Error paths behind `ecalloc` / `zend_new_array` failures (fault-injection only)

### 2026-04-15 — async.c (target #2)

`async.c` 84.49% → **86.07%** (+1.58%, ~12 lines of 761). The §6
report over-estimated reachable lines in the `Async\iterate` block —
the remaining gap is the per-iteration chain/exception merge path that
requires both the iterator callback AND the cancel path to throw
simultaneously, plus defensive `ecalloc`/`zend_new_array` failure
branches. Instead, picked small surface-area wins across other async.c
functions.

Tests added (6):

- **iterate/014** IteratorAggregate::getIterator() throwing propagates
  through `Async\iterate` — covers L856-867.
- **common/timeout_value_error** `Async\timeout(0)` / `-1` / `-1000`
  → ValueError "must be greater than 0" (L694-697).
- **common/await_any_of_exception_releases_arrays** non-iterable
  futures arg → exception path releases results/errors arrays and
  re-throws (L637-640).
- **common/current_coroutine_not_in_coroutine** at script root —
  "The current coroutine is not defined" (L772-775).
- **common/current_context_at_root** `Async\current_context()` and
  `Async\coroutine_context()` at script root return independent
  Context objects (L717-720, L745-748).
- **common/await_same_cancellation** passing the same event as both
  awaitable and cancellation clears the cancellation slot (L306-307).
- **sleep/003-delay_zero_immediate** `Async\delay(0)` enqueue fast
  path without timer (L671-672).

Remaining async.c gaps (~14%): iterate cancel-pending exception merge
(L963-987), bailout paths inside `Async\protect` (L252-263), internal
finally-handler dispatcher (L243-244), and thread spawn event creation
error (L181-182). All either fault-injection or require bailout.

### 2026-04-15 — task_group.c (target #3)

`task_group.c` 84.01% → **86.18%** (+2.17%, ~18 lines of 832).

Tests added (5, task_group/035–039):

- **035** `all()` called after all tasks already failed synchronously
  rejects immediately via CompositeException — covers L1406-1421
  synchronous-settled reject branch.
- **036** `race()` called after first task already in TASK_STATE_ERROR
  synchronously rejects — L1452-1457 immediate reject path.
- **037** `any()` called after all tasks already failed synchronously
  rejects via CompositeException — L1495-1500.
- **038** four small error branches in a single file: empty-`any()`,
  negative `__construct($concurrency)`, duplicate integer key via
  `spawnWithKey`.
- **039** direct `$group->getIterator()` call hits the PHP method body
  (L1715-1721) — normal `foreach` goes through the class's
  get_iterator handler and never reaches this code.

Incidental latent bug found: keeping an intermediate `$future =
$group->all()` variable across a try/catch triggers a segfault at
coroutine teardown in the synchronously-settled path. Worked around
in test 035 by chaining `$group->all()->await()` directly. Logged as a
separate issue; not investigated in this pass.

Found-and-skipped dead code: task_group.c L1305-1307
`"Cannot spawn tasks on a completed TaskGroup"` — shadowed by the
earlier `IS_SEALED` check at L1300, so the "completed" branch can
never fire unless the group is both completed AND un-sealed, which
`ASYNC_TASK_GROUP_SET_COMPLETED` only does for sealed groups.

Remaining task_group.c gaps (~14%): `task_group_dispose()`,
`task_group_replay()`, `task_group_info()` — only called from the
scheduler deadlock-debug reporter (see report §5.2), unreachable by
phpt in practice.

### 2026-04-15 — channel.c (target #4)

`channel.c` 86.70% → **89.00%** (+2.30%, ~9 lines of 391).

Tests added (2):

- **channel/039-channel_iterator_unbuffered** `foreach` over an
  unbuffered (rendezvous, capacity=0) channel — exercises
  `channel_iterator_move_forward` L471-475 which reads directly from
  `channel->rendezvous_value`. Existing test 008 only covered the
  buffered `zval_circular_buffer_pop` branch.
- **channel/040-channel_foreach_by_ref** `foreach ($ch as &$v)` →
  "Cannot iterate channel by reference" Error — covers
  `channel_get_iterator()` L517-519.

Discovered but not added: direct `$channel->getIterator()` call hits
METHOD(getIterator) at L772-778, but the method currently returns an
`__iterator_wrapper` that fails the declared `: Iterator` return type
with a Fatal error. Not a phpt-writable case.

Remaining channel.c gaps (~11%): `channel_info()` / `channel_dispose()`
deadlock-reporter paths (report §5.2), `async_channel_get_gc()` which
requires GC walk during a cycle, and `channel_iterator_get_current_key`
which is never called because channel iteration produces null keys.

### 2026-04-15 — thread.c (target #5)

`thread.c` 78.78% → **78.95%** (+0.17%, ~2 lines of 1145).

The §6 report's "~40 lines" estimate turned out to be unreachable via
phpt:
- The `thread_event == NULL` branches in `isRunning` / `isCompleted` /
  `isCancelled` / `getResult` / `getException` / `cancel` at
  L2203-2288 are defensive dead code. `thread_event` is only nulled
  inside `thread_object_dtor` (L2147), so by the time those branches
  could be reached the object is already being freed and no further
  PHP method calls are possible.
- The bailout-capture and exception-transfer paths at L1925-2022 fire
  inside the internal thread entry point. Attempting to exercise them
  from phpt either crashes (test 042 draft with finally registered on
  a still-running thread → SIGSEGV — latent bug, logged) or gets
  swallowed by the thread pool's own dispatcher.

Test added:

- **thread/042-thread_finally_non_callable** `$t->finally("not-a-fn")`
  hits the `zend_is_callable` rejection at L2309-2311. The second
  half of the original test (finally registration on a running
  thread) crashed on teardown and was removed — the failing path is
  a separate latent bug to investigate later.

Remaining thread.c gaps: require fault injection, custom thread pool,
or fix of the pre-existing crash in the finally-handlers-on-running
path.

### 2026-04-15 — fs_watcher.c (target #6) SKIPPED

Target is the RENAME-on-existing-CHANGE coalesce merge branch at
fs_watcher.c L143-144. The plan was to trigger `file_put_contents`
followed by `rename()` on the same file within the coalesce window
so the libuv fs_event callback fires twice for the same key. On
Linux inotify, `rename()` emits `MOVED_FROM` / `MOVED_TO` with a
*different* filename than the preceding CHANGE, so the coalesce key
(dir+filename) doesn't match — a merged event never forms. Skipped;
would need per-platform shims or a libuv test harness.

### 2026-04-15 — thread_pool.c (target #7)

`thread_pool.c` 80.40% → **80.98%** (+0.58%, ~2 lines of 347).

Test added:

- **thread_pool/032-map_on_closed_pool** `$pool->map()` on a closed
  pool throws `ThreadPoolException("ThreadPool is closed")`, covering
  METHOD(map) L515-522. Existing test 007 already covered the same
  guard for `submit()`.

Remaining ~19% is the post-send channel-closed race (L480-592, both
submit and map paths), where `pool->base.closed` is still 0 but the
channel send fails. Reproducing that cleanly would need a second
thread closing the channel between the closed-check and the send
call — not worth a fuzzy sleep-based test.

### 2026-04-15 — context.c (target #8)

`context.c` 90.70% → **91.86%** (+1.16%, ~2 lines of 172).

Test added:

- **context/008-context_get_missing** `$ctx->get("missing")` returns
  NULL, covering METHOD(get) L242 fallback.

Remaining ~8% is the C-API-only "invalid key type" error branches
at L85/110/126/159 (the PHP methods catch bad types via
VALIDATE_CONTEXT_KEY before reaching the helpers) and the parent-
scope walker L63-65 which needs a 3+ level scope hierarchy with
contexts on each level — covered indirectly by context/007 but
the exact NULL-fallback line isn't hit.

## End of phase 2

Aggregate `lcov` summary after phase 2:

```
lines......: 78.3% (9231 of 11785 lines)
functions..: 89.1% (808 of 907 functions)
```

Delta from phase 2 start: **+104 lines (+0.89%)**, **+10 functions
(+1.10%)** across 6 targets. Phase 2 conclusion: most of the §6
"achievable" targets had smaller actual payoff than the report
estimated — the dominant cost in the remaining gap is defensive
branches behind already-covered guards, C-API helpers without
internal callers, and fault-injection paths.
