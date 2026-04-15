# Coverage Progress — Phase 2

Tracks the phpt-only "achievable budget" from `COVERAGE_REPORT.md` §6
(realistic ceiling ~80%). This file is updated as blocks land so the
next session can pick up where the previous one stopped.

Start of phase: 77.45% lines / 88% functions (from §1 of the report).

## Plan

| # | Target | File | Budget | Status |
|---|---|---|---|---|
| 1 | finally handlers chain | `future.c:1192–1252` | ~60 lines | **DONE** (+5.42% → 85.80%) |
| 2 | `Async\iterate` error paths | `async.c:859–987` | ~50 lines | pending |
| 3 | TaskGroup cancel/race/error | `task_group.c:243–1457` | ~40 lines | pending |
| 4 | Channel close/timeout | `channel.c:322–778` | ~40 lines | pending |
| 5 | Thread internals | `thread.c:1957–2172` | ~40 lines | pending |
| 6 | fs_watcher coalesce | `fs_watcher.c:141–246` | ~20 lines | pending |
| 7 | thread_pool submit-after-close | `thread_pool.c:483–592` | ~15 lines | pending |
| 8 | Context find-local | `context.c:34–38,126–132` | ~10 lines | pending |

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
