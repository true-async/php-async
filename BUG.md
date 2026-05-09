# Known bugs

## Child threads — `STDIN`/`STDOUT`/`STDERR` constants are not registered

**Severity:** medium (ergonomic — user code that relies on these constants in a worker fails; no silent data loss).

### Symptoms

Inside a closure running in a child thread (via `Async\spawn_thread()`, `Async\Thread`, or `Async\ThreadPool::submit()`):

- `defined('STDIN')`, `defined('STDOUT')`, `defined('STDERR')` all return `false`.
- `get_defined_constants()` does not contain these names.
- Using them throws `Error: Undefined constant "STDERR"`.

A side-effect that looks scarier than it is:

- `var_export($resource)` from a child thread prints `NULL` for any stream/resource opened in that thread (e.g. `var_export(fopen(...))`). The resource itself is fully functional — `is_resource()` returns `true`, `get_resource_type()` returns `"stream"`, and `fwrite()` / `fclose()` behave normally. The cosmetic `NULL` is `var_export`'s rendering of a resource whose type id is not present in the child thread's `EG(known_resource_types)`.

### What is NOT broken (despite earlier reports)

The original write-up of this bug also claimed:

- `fopen()` returns `NULL` instead of a resource — **incorrect**. It returns a working resource; `var_export` is what prints `NULL`.
- `fwrite()` returns the byte count but writes nothing — **incorrect**. The file is written and `fclose()` flushes correctly.
- `error_log()` is silently dropped — **incorrect**. Returns `true` and writes to the configured log file.
- `error_get_last()` returns `NULL` — **incorrect**. Returns the expected error array.
- `MAIN file content: (empty)` after `await_all_or_fail` — that observation came from a race in the original reproducer (`echo file_get_contents(...)` ran in the main thread before the awaiting coroutine, scheduled with `spawn()`, had a chance to wait on the futures). With proper synchronisation the file contents are present.

These were verified by direct probes against the current ZTS+TrueAsync build (2026-05-04). Only the missing `STDIN/STDOUT/STDERR` constants are real.

### Root cause

`cli_register_file_handles()` (`sapi/cli/php_cli.c:523`) is what creates the `STDIN/STDOUT/STDERR` constants in CLI: it opens `php://stdin/stdout/stderr` as `php_stream` instances and registers them as constants in `EG(zend_constants)`. It runs **once**, from `main()` in the CLI SAPI, for the main thread only.

In ZTS, `EG(zend_constants)` is per-thread. Child threads spawned by `ext/async` go through `async_thread_run` → `async_thread_request_startup` → `php_request_startup`. `php_request_startup` calls `sapi_module.activate` (which in CLI is `sapi_cli_activate` — only signal-handler setup, no file-handle registration), so child threads never get those constants.

`ext/parallel` has the same gap — it does not register `STDIN/STDOUT/STDERR` for its child threads either, and ships no tests for them.

### Reproducer

```php
<?php
use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

spawn(function() {
    $t = spawn_thread(static fn(): bool => defined('STDERR'));
    var_dump(await($t)); // bool(false) — should be true
});
```

### Concurrent stdio writes from multiple threads

A natural follow-up question: is it legal for multiple child threads to write to `STDERR` (or `STDOUT`) concurrently?

- **OS level (fd 2 / fd 1).** POSIX guarantees that `write(2)` calls of `<=PIPE_BUF` bytes (Linux: 4096) are atomic on pipes/FIFOs; for regular files opened `O_APPEND` they are atomic too. Short writes from multiple threads to the same fd will not interleave at the byte level.
- **`php_stream` level.** A `php_stream` object is not thread-safe — its buffer, position, refcount and flags are not guarded. Sharing a single `php_stream` across threads (e.g. by passing the same resource zval around) is a data race.

The correct design is therefore: each child thread gets its **own** `php_stream` wrapping the same underlying fd. Each thread's `fwrite(STDERR, …)` goes through that thread's private buffer; the resulting `write(2)` syscalls hit a shared fd but, being short, are atomic per POSIX. This is the same shape `cli_register_file_handles` uses for the main thread (with `PHP_STREAM_FLAG_NO_RSCR_DTOR_CLOSE` so request shutdown does not close fd 0/1/2).

### Fix

In `async_thread_request_startup` (`ext/async/thread.c:1640`) — after `php_request_startup()` succeeds — open `php://stdin`, `php://stdout`, `php://stderr` as per-thread `php_stream`s, mark them with `PHP_STREAM_FLAG_NO_RSCR_DTOR_CLOSE`, and register `STDIN/STDOUT/STDERR` constants in the current thread's `EG(zend_constants)`. Mirrors `cli_register_file_handles` but per-thread.

This covers all child-thread code paths (`Async\Thread`, `Async\spawn_thread`, `Async\ThreadPool` workers) since they all share `async_thread_run` → `async_thread_request_startup`.

### Tests

Regression coverage was missing entirely — no `*.phpt` in `ext/async/tests/{thread,thread_pool}/` exercised `STDERR`, `STDIN`, `STDOUT`, `fopen`, `fwrite` or `error_log` inside a child-thread closure. The fix ships with tests for both single-thread and pool-worker paths, including a parallel-write stress test that verifies no message corruption when multiple workers write to `STDERR` concurrently.

## ThreadPool::cancel corrupts the heap *(fixed)*

**Severity:** high — crash with `zend_mm_heap corrupted` + segfault.

### Symptoms

Cancelling a `ThreadPool` while pending tasks were enqueued occasionally
produced `zend_mm_heap corrupted` + SIGSEGV at request shutdown when the
captured stack trace contained an outer closure with nested function
definitions (`static fn` etc.).

### Root cause

`thread_pool_drain_tasks(reject=true)` rejects each pending future by
transferring a fresh cancellation `Exception` into the future's persistent
state. The exception's `trace` array captures live arguments from the call
stack — including the running outer closure object. `closure_transfer_obj`
snapshots the outer closure's op_array into a persistent arena, and the
LOAD path destroys that arena right after rebuilding the closure on the
worker heap via `op_array_to_emalloc`.

`op_array_to_emalloc` deep-copied opcodes, literals, vars, arg_info, etc.
but NOT `dynamic_func_defs`. The outer closure has nested closures referenced
through `dynamic_func_defs[i]` — those pointers kept pointing into the
arena. After `async_thread_snapshot_destroy()` released the arena, the
loaded closure carried dangling pointers; `destroy_op_array()` later walked
them and corrupted the heap.

### Fix

`op_array_to_emalloc` now copies `dynamic_func_defs` recursively into a
single emalloc block (pointer table + struct bodies laid out contiguously)
so `destroy_op_array`'s `efree(op_array->dynamic_func_defs)` releases
everything cleanly. Regression coverage:
`ext/async/tests/thread_pool/036-cancel_with_pending_outer_closure.phpt`.

## Segfault in `await_all_or_fail` / `await_first_success` when a Future errors *(fixed)*

**Severity:** high — crash on a routine error path.

### Symptoms

When a trigger passed to one of the multi-trigger combinators
(`await_all_or_fail`, `await_first_success`, `await_any_of_or_fail`,
`await_all`) was already settled with an error before iteration began, the
awaiter segfaulted instead of receiving / throwing the error.

A plain `Future::await()` on the same errored state worked correctly — the
crash only manifested when the combinator walked the trigger list and
encountered an already-closed errored slot.

The same crash reproduced when one of the triggers was a Coroutine that had
thrown rather than a Future that had errored:

```php
$bad = spawn(fn() => throw new RuntimeException("bad"));
spawn(function() use ($bad) { await_all([$bad], null, true, true); });
// -> Segmentation fault
```

### Root cause

`async_await_futures` walks each trigger and, for already-closed events,
calls `ZEND_ASYNC_EVENT_REPLAY` which synchronously fires the registered
callback (`async_waiting_callback`). The callback's two error paths
(`!ignore_errors` and `ignore_errors && resolved_count >= total`) called
`ZEND_ASYNC_RESUME` / `ZEND_ASYNC_RESUME_WITH_ERROR` on
`await_callback->callback.coroutine` — but that pointer is `NULL` at this
point because `zend_async_resume_when` (which sets it) has not yet been
called for synchronously-replayed callbacks. Dereferencing `NULL->waker`
inside `async_coroutine_resume` crashed.

### Fix

- Added `pending_exception` to `async_await_context_t`.
- `async_waiting_callback` now NULL-guards both resume calls. In the
  `!ignore_errors` branch, when the callback fires synchronously
  (coroutine still running, not suspended yet) the exception is stashed on
  the await context instead of being delivered via resume.
- `async_await_futures` rethrows the stashed exception via
  `zend_throw_exception_internal` after the iteration loop / suspend block.
- The dtor releases any uncollected stashed exception ref.

Regression coverage:
- `ext/async/tests/await/094-awaitAllOrFail_already_errored_future.phpt`
- `ext/async/tests/await/095-awaitAll_already_failed_coroutine.phpt`

### Reproducer

```php
<?php
use Async\Future;
use Async\FutureState;
use function Async\spawn;
use function Async\await_all;
use function Async\await_all_or_fail;

$st1 = new FutureState();
$st2 = new FutureState();
$st3 = new FutureState();
$f1 = new Future($st1);
$f2 = new Future($st2);
$f3 = new Future($st3);

spawn(fn() => $st1->complete(1));
spawn(fn() => $st2->error(new RuntimeException("boom")));
spawn(fn() => $st3->complete(3));
$a = spawn(function() use ($f1, $f2, $f3) {
    try { await_all_or_fail([$f1, $f2, $f3]); }
    catch (\Throwable $e) { echo "caught\n"; }
});

await_all([$a]); // -> Segmentation fault
```

Discovered by `ext/async/fuzzy_tests/await/await_all_or_fail.feature`
(scenario "one of N fails — await_all_or_fail must throw") and
`await_first_success.feature` (scenario "every producer fails").

### Status

Open. Tracked here pending issue file in https://github.com/true-async/php-async/issues/.
The corresponding chaos rows are kept in the suite as expected failures
until the fix lands — see `ext/async/fuzzy_tests/PLAN.md` "Known issue
tracking".
