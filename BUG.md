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
