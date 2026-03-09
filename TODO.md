# TrueAsync TODO

## v0.7.0: Fix scheduler re-launch during module RSHUTDOWN

### Problem

When `mysqli_debug()` is active, mysqlnd writes debug trace to a file stream during its
`PHP_RSHUTDOWN`. This file stream was opened while async was active, so it has `async_io`
attached. The write goes through `php_stdiop_write()` which enters the async I/O path and
calls `ZEND_ASYNC_SCHEDULER_INIT()`, which re-launches the scheduler — creating new scopes,
fiber contexts, coroutines that are never cleaned up.

### Root cause

The shutdown sequence in `php_request_shutdown()` (`main/main.c`):

```
1992: ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN(false)  // scheduler shuts down fully
1996: ZEND_ASYNC_DEACTIVATE                        // state = OFF
2012: zend_deactivate_modules()                    // RSHUTDOWN for all modules
```

The scheduler is fully destroyed at step 1. State is set to OFF at step 2.
But at step 3, mysqlnd RSHUTDOWN calls `DBG_ENTER("RSHUTDOWN")` which writes to the
debug stream. `php_stdiop_write()` checks:

```c
if (data->async_io != NULL && data->is_blocked && !ZEND_ASYNC_IS_SCHEDULER_CONTEXT)
```

This passes because `async_io` was set when the stream was opened (async was still active).
Then `ZEND_ASYNC_SCHEDULER_INIT()` checks only `ZEND_ASYNC_CURRENT_COROUTINE == NULL`
(true — scheduler is dead) without checking `ZEND_ASYNC_IS_OFF`, and calls
`async_scheduler_launch()` which also has no `ZEND_ASYNC_IS_OFF` guard.

Result: scheduler is re-launched, allocating 15 objects that are never freed.
PHP's internal leak detector reports them. Valgrind confirms no real leaks (everything
is freed at process exit, just after the leak check runs).

### Call chain (confirmed via GDB)

```
zm_deactivate_mysqlnd()          // mysqlnd RSHUTDOWN
  → DBG_ENTER("RSHUTDOWN")
    → mysqlnd_debug_func_enter()
      → mysqlnd_debug_log_va()   // writes ">RSHUTDOWN\n" to trace file
        → php_stream_write()
          → php_stdiop_write()   // async_io != NULL on the trace stream
            → ZEND_ASYNC_SCHEDULER_INIT()
              → async_scheduler_launch()  // SECOND launch!
```

### Proposed solution

**Do not fully destroy the scheduler in `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN`.**

Instead, the scheduler should transition to a "draining" or "idle" state:
- All user coroutines are finalized
- The reactor is stopped
- But the main coroutine, scopes, and fiber contexts remain alive

This way, any I/O during module RSHUTDOWN goes through the existing scheduler
(the write completes synchronously, no new scheduler launch needed).

Full scheduler destruction happens in `PHP_RSHUTDOWN(async)`, which runs **last**
among all modules (async is registered early in `php_builtin_extensions`, so its
RSHUTDOWN runs in reverse order — after all other modules).

The `ZEND_ASYNC_DEACTIVATE` call moves from `main.c:1996` into `PHP_RSHUTDOWN(async)`.

### Key files to modify

| File | Change |
|------|--------|
| `main/main.c:1992-1996` | Remove `ZEND_ASYNC_DEACTIVATE`; adjust `ZEND_ASYNC_RUN_SCHEDULER_AFTER_MAIN` to not fully destroy |
| `ext/async/scheduler.c` | Split shutdown into two phases: drain (after main script) and destroy (at RSHUTDOWN) |
| `ext/async/async.c` (`PHP_RSHUTDOWN`) | Add full scheduler destruction + `ZEND_ASYNC_DEACTIVATE` |
| `ext/async/async_API.c` (`engine_shutdown`) | May need adjustment — currently frees buffers/hash in `zend_deactivate()` |

### Affected tests

These 4 tests currently FAIL due to the 15 false-positive memory leak reports:

- `ext/mysqli/tests/mysqli_debug.phpt`
- `ext/mysqli/tests/mysqli_debug_control_string.phpt`
- `ext/mysqli/tests/mysqli_debug_ini.phpt`
- `ext/mysqli/tests/mysqli_debug_mysqlnd_control_string.phpt`

No test modifications needed — once the fix is in place, the leak reports disappear
and the tests pass as-is.

## Analyze all PHP_STREAM_AS_STDIO call sites

### Problem

When async IO is active, `php_stdiop_cast(PHP_STREAM_AS_STDIO)` creates a `FILE*` via
`fdopen()`. Since the fd is owned by libuv, we currently `dup()` the fd before `fdopen()`
to avoid dual ownership (see `plain_wrapper.c`, `php_stdiop_cast`, marked as TEMPORARY).

This is a workaround. A proper solution requires analyzing **all** code paths that call
`php_stream_cast(PHP_STREAM_AS_STDIO)` to understand how the resulting `FILE*` is used:

- Is `fwrite(fp)` used, or does the caller go through `php_stream_write()`?
- Does the caller close the `FILE*` independently?
- Can the caller work with async IO directly instead of requiring a `FILE*`?

### Known call sites to audit

- `ext/curl/interface.c` — `curl_setopt(CURLOPT_FILE, ...)` casts stream to `FILE*`,
  but async curl write path uses `php_stream_write()`, not `fwrite(fp)`.
- Any extension using `php_stream_cast(PHP_STREAM_AS_STDIO)` in combination with
  C library functions that expect `FILE*`.

### Goal

Eliminate the `dup()` workaround by ensuring async IO streams either:
1. Never need `PHP_STREAM_AS_STDIO` cast (preferred), or
2. Have a well-defined ownership model for the `FILE*` copy.

---

### How to verify

1. **Quick check** — run the 4 failing tests:
   ```
   sapi/cli/php run-tests.php ext/mysqli/tests/mysqli_debug.phpt \
       ext/mysqli/tests/mysqli_debug_control_string.phpt \
       ext/mysqli/tests/mysqli_debug_ini.phpt \
       ext/mysqli/tests/mysqli_debug_mysqlnd_control_string.phpt
   ```

2. **Reproduce the leak** — minimal script:
   ```
   sapi/cli/php -r '
       mysqli_debug(sprintf("d:t:O,%s/test.trace", sys_get_temp_dir()));
       $link = mysqli_connect("localhost", "test", "test", "test", 0, "/var/run/mysqld/mysqld.sock");
       mysqli_close($link);
       echo "done\n";
   '
   ```
   Should print only `done` with no leak messages.

3. **Valgrind** — confirm no real leaks:
   ```
   valgrind --leak-check=full sapi/cli/php -r '...' 2>&1 | tail -5
   ```
   Should show `All heap blocks were freed -- no leaks are possible`.

4. **GDB** — confirm scheduler launches only once:
   ```
   break async_scheduler_launch
   run -r '...'
   ```
   Should hit the breakpoint exactly once (not twice).

5. **Full async test suite** — ensure no regressions:
   ```
   sapi/cli/php run-tests.php ext/async/tests/
   ```
