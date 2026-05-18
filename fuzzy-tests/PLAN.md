# Chaos / Fuzz Test Plan for ext/async

This is the working plan for expanding `fuzzy-tests/`. Future contributors
(or future-me with no memory of the previous session) should be able to pick
up from here without re-deriving context.

## What this directory is

A Gherkin-style chaos test harness. Each `.feature` file declares scenarios
in plain language; `_harness/generate.php` produces one `.phpt` per
`Scenario` / `Examples` row into `_generated/`. The phpt-level dispatcher
routes each row to `_harness/Runner::runScenario()`, which executes it
through real ext/async API while the C-level scheduler hook
(`TRUE_ASYNC_SCHED=fifo|random:N|pct:...`) optionally permutes coroutine
ordering.

See `README.md` for usage. See `../FUZZ_TESTING.md` for the multi-layer
strategy (this directory implements Layers 1 + part of step-fuzzing).

## Why we have it

Pure phpt tests assert exact output, which only holds for one specific
scheduling. Chaos tests assert **invariants that must hold under any valid
interleaving** (`sent + send_failed == send_attempts`, `no orphan
coroutines`, channel ends closed, etc).

Already paid for itself: writing `await/await_any.feature` discovered
issue #103 (deadlock in `await_any_or_fail` with Future triggers). The
chaos pattern produced a 4-line repro that 41 existing phpt tests had
never hit, because they only used Coroutine triggers, not Future.

## Bug-hunt pattern that worked once and should work again

A surprising number of bugs live on the **edge between async primitives**.
The pattern that found #103:

1. Pick a multi-trigger combinator (`await_all`, `await_any_or_fail`,
   `await_first_success`, `await_any_of`, etc.).
2. Build a scenario where one producer **synchronously completes a
   later-positioned trigger** before the awaiter reaches it (Future is the
   easiest, because `$state->complete()` is synchronous).
3. Vary which slot is filled (Examples column `which`) and which trigger
   types (`Future`, `Coroutine`, `Channel::recvAsync()`).

Apply this template to each combinator and each trigger type. Do it once
and the matrix tells you whether the runtime treats those triggers
symmetrically.

## Current coverage

| Topic           | Features                                                | Status                            |
|-----------------|---------------------------------------------------------|-----------------------------------|
| channel/        | send_recv_pair, close, multi_sender, iterator           | partial (54% of API)              |
| coroutine/      | many_complete, cancel, exception                        | basic                             |
| await/          | await_all, await_any, await_all_or_fail, await_first_success, await_any_of, mixed_triggers, with_cancellation | broad (combinator family done) |
| scope/          | basic (spawn-in-scope, cancel mid-flight)               | thin                              |
| future/         | complete (with multiple awaiters)                       | thin                              |
| task_group/     | basic, concurrency_limit, race, cancel, close_then_spawn, dispose | broad (every public verb hit) |
| thread_channel/ | basic, close, lock_contention                           | partial (multi-thread sender/receiver TODO) |
| thread_pool/    | submit_n, close_pending, cancel (XFAIL), map            | broad                             |

100 generated phpt × 7 scheduler seeds = 700 chaos runs. `COVERAGE.md`
reports 38.9% of the public API touched (heuristic, lower bound).

## TODO — by topic, in rough priority order

### 1. await/ — finish the combinator family

We tested `await_any_or_fail` and found #103. Mirror the same scenario
shape for every other combinator. Each one is a candidate for the same
class of bug.

- **await_all_or_fail.feature** — N futures, all completed, all results
  collected. Vary completion order (Examples). Invariants: `received_count
  == N`, no orphan coroutines.
- **await_first_success.feature** — one of N futures fails, expect the
  first **successful** to win. With pre-failed Future the awaiter must
  not get stuck.
- **await_any_of.feature** — wait for K out of N. Fuzz K, fuzz which K.
- **mixed_triggers.feature** — `await_all([Coroutine, Future, Channel])`.
  Symmetric behaviour across trigger types.
- **with_cancellation.feature** — every combinator accepts a cancellation
  Awaitable. Cancel the awaiter mid-await; assert no leaks.

### 2. task_group/ — entire surface is uncovered

`Async\TaskGroup` is non-trivial (concurrency limit, queue limit,
close/dispose, all/race/any). New step definitions needed:

```
Given a task group "G"
Given a task group "G" with concurrency <N>
When task group "G" spawns N tasks that print "..."
When coroutine "X" awaits all of "G"
When coroutine "X" awaits race of "G"
When coroutine "X" awaits any of "G"
When coroutine "X" cancels "G"
When coroutine "X" closes "G"
Then group "G" is finished
Then group "G" count equals <N>
```

- **basic.feature** — spawn N tasks, await all, every result collected.
- **concurrency_limit.feature** — concurrency=2 over 10 tasks; only 2 can
  run simultaneously (counter `running_max <= 2` invariant).
- **race.feature** — first to finish wins, others get cancelled.
- **cancel.feature** — cancel mid-flight, all children stop.
- **close_then_spawn.feature** — spawning into a closed group throws.
- **dispose.feature** — dispose triggers cancellation.

### 3. thread_channel/ — known race, fuzz target

Existing `thread_channel/019,024.phpt` are flaky on ASAN per the project
TODO. Reproducing them under randomised scheduling is the entire point.

New step definitions (mirror the Channel ones):

```
Given a thread channel "X" with capacity <N>
When coroutine "A" sends N messages to thread channel "X"
When coroutine "B" receives N messages from thread channel "X"
When coroutine "C" closes thread channel "X"
Then counter "tch_sent_X" plus counter "tch_send_failed_X" equals <N>
```

- **basic.feature** — same patterns as channel/send_recv_pair.
- **close.feature** — same as channel/close.
- **multi_thread.feature** — sender in main thread, receiver in
  `spawn_thread()` body. This is where real parallelism exposes races
  the coroutine-only path cannot see.
- **lock_contention.feature** — many senders + many receivers under
  `random:N` scheduling.

### 4. thread_pool/ — real OS-thread parallelism

`Async\ThreadPool` exposes `submit($callable): Future` and `map`. New
step shape:

```
Given a thread pool "P" with <workers> workers
When coroutine "X" submits to "P" 10 tasks that compute and return values
When coroutine "X" awaits all results from "P"
Then counter "submitted_P" equals 10
Then counter "completed_P" plus counter "failed_P" equals counter "submitted_P"
```

- **submit_n.feature** — submit N, all complete.
- **close_pending.feature** — close pool with pending tasks; pending
  futures must reject cleanly.
- **cancel.feature** — cancel pool mid-flight.
- **map.feature** — `map(items, callable)` returns array same length as
  items.

These are the most likely to surface ASAN issues because they exercise
real OS threads + cross-thread Future completion.

### 5. coroutine/ — fill in details

- **finally.feature** — finally handlers run on normal return,
  exception, and cancellation. Counter invariants on each path.
- **getResult_getException.feature** — read result/exception after
  completion, including `Future::ignore()` interactions.
- **getTrace.feature** — call from within suspended coroutine; assert
  reasonable shape.
- **deep_recursion.feature** — N recursive spawns, no stack issues.

### 6. scope/ — fill in details

- **dispose.feature** — `dispose()` vs `disposeSafely()` vs
  `disposeAfterTimeout()`. Counter invariants on each.
- **nested.feature** — child scopes inherit cancellation; parent cancel
  cascades.
- **exception_handler.feature** — `setExceptionHandler` on scope catches
  child failures.
- **finally.feature** — finally handlers on scope.

### 7. future/ — race semantics

- **error.feature** — `FutureState::error($throwable)` propagates to
  awaiters.
- **map_catch_finally.feature** — chained transformations.
- **cancel_token.feature** — passing a cancellation Future to `await()`.

### 8. channel/ — sharpen edges

- **deadlock_detection.feature** — exercise the per-channel deadlock
  timer (test ext/async/tests/channel/043,044 are the hand-written
  baseline).
- **scope_owned.feature** — channel tied to TaskGroup or Scope, scope
  dies → channel closes (matches tests/channel/049,057,058).
- **iterator_concurrent.feature** — multiple iterators on one channel.

### 9. cross-topic / system-level

- **shutdown_with_pending.feature** — coroutines still alive at request
  end; runtime cleans up without leaks. Likely to surface dangling-cache
  type bugs (the same class as #103's secondary symptom).
- **cancel_during_io.feature** — cancel a coroutine that's blocked on a
  reactor I/O event.

## How to add a new feature

1. Create `topic/name.feature` with `Feature: …` and `Scenario(s):`.
2. If you need a new step, add the regex + handler in
   `_harness/Steps.php`. Convention: `coroutine "X" verb args`.
3. If you need a new entity (planned object that materialises at
   `Context::run()`), add the field + a `defineX()` method to
   `_harness/Context.php`, instantiate it in `Context::run()`.
4. Run `./regen.sh` from this directory; check that
   `_generated/topic/...phpt` files were produced.
5. `make TEST_PHP_ARGS="-q --no-progress" TESTS=ext/async/fuzzy-tests/_generated/topic test`
6. Repeat with `TRUE_ASYNC_SCHED=random:N` for several N.
7. If a row reproducibly fails — file an issue; either remove the row
   from Examples (with a `# blocked on #NNN` comment) or fix the runtime.

## Invariant style — recap

Read `feedback_chaos_invariants.md` (in user memory) before writing
assertions. Short version:

| Don't | Do |
|---|---|
| `Then counter "sent_ch" equals 5` | `Then counter "sent_ch" plus counter "send_failed_ch" equals 5` |
| `Then output contains "got: 42"` | `Then counter "received_ch" equals counter "sent_ch"` |

Under randomised scheduling some operations *will* fail (e.g. send into a
channel that another coroutine just closed). That is correct runtime
behaviour, not a bug, and the invariants must encode it. Real bugs
manifest as **counter mismatches you can't explain by legal interleavings**
or as ASAN crashes.

## Expanding the harness

If a topic needs entities the harness doesn't model yet (e.g. `ThreadPool`,
`TaskGroup`), they go in `_harness/Context.php` next to existing ones —
each entity has:

- a `defineX(name, ...)` method called from `Given` handlers,
- a private `defs[name] => spec` array,
- a public `instances[name] => realObj` array populated in `run()`,
- cleanup in the same `run()`'s tail (close/dispose).

Step handlers always operate through `$ctx->`, never reach for globals.

## Running the matrix

The CI tier is not yet wired (Layer 1 strategy in `../FUZZ_TESTING.md`
mentions per-PR / nightly tiers — none of those exist yet as workflows
in the repo). Local matrix:

```sh
for sched in fifo random:1 random:7 random:42 random:1337; do
  TRUE_ASYNC_SCHED=$sched make TEST_PHP_ARGS="-q --no-progress" \
    TESTS=ext/async/fuzzy-tests test || echo "FAIL on $sched"
done
```

For value fuzzing add `CHAOS_GEN_SEED` to the same matrix.

## Stretch goals (not in scope until everything above is done)

- **Layer 2 (I/O chaos)** — Toxiproxy / in-process EvilPeer for streams,
  sockets, curl. Separate directory `fuzzy-tests/io_chaos/`, separate
  harness.
- **Layer 5 (allocator faults)** — fault-inject `emalloc` to expose
  missing error paths in the runtime. Needs `--enable-async-fuzz`-time
  hook; not yet added.
- **PCT scheduler mode** — already plumbed in `internal/fuzz.c` as a
  stub; finish the algorithm (priority-change-points). Replaces the
  coin-flip random with depth-bounded probabilistic guarantees.

## Known issue tracking

- `await/await_any.feature` — Examples row `| F2 | 2 |` is omitted with a
  comment, blocked on the inline-fire asymmetry of
  `zend_future_add_callback` / `start_waker_events`. After the #103 fix
  shipped (commit `25120d8` adds `start_waker_events` replay),
  reintroduce that row and verify it passes.
- `thread_channel/multi_thread.feature` — not yet written. Requires the
  harness to model `Async\spawn_thread()` bodies (a coroutine planned to
  run inside a child thread). Pending harness extension.

### Resolved

- Segfault in multi-trigger combinators when a Future errored or a
  Coroutine threw — fixed in `ext/async/async_API.c` (`pending_exception`
  on `async_await_context_t`, NULL-guarded resumes); regression tests
  `tests/await/094-awaitAllOrFail_already_errored_future.phpt` and
  `tests/await/095-awaitAll_already_failed_coroutine.phpt`. The previously
  XFAIL'd chaos scenarios under `await/` are reinstated and pass under
  fifo + 6 random scheduler seeds.
- `ThreadPool::cancel()` heap corruption — fixed in
  `ext/async/thread.c` (`op_array_to_emalloc` now copies `dynamic_func_defs`
  contiguously); regression test
  `tests/thread_pool/036-cancel_with_pending_outer_closure.phpt`.
  `fuzzy-tests/thread_pool/cancel.feature` reinstated.
