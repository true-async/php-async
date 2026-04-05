# TrueAsync TODO

## Windows: stream_select() does not support pipes

### Problem

Test `ext/standard/tests/streams/proc_open_bug64438.phpt` fails on Windows.

The test creates pipes via `proc_open`, calls `stream_set_blocking(false)`, then uses
`stream_select()` + `fread()` in a loop. Expected: 2 entries per pipe:
`[Read 4097 bytes, Closing pipe]`. Actual: 3 entries: `[Read 0 bytes, Read 4097 bytes, Closing pipe]`.

### Root cause

`select()` on Windows works **only with Winsock sockets**, not pipes.
`stream_select()` on pipes returns a false positive — reports "readable" when no data is available.

Before async this was masked: `stream_set_blocking(false)` on Windows pipes **always failed**
(returned -1 in `plain_wrapper.c`), so `is_blocked` stayed `1`. `fread()` entered the
`PeekNamedPipe` wait loop (up to 32 sec) and waited for data — compensating for unreliable `stream_select`.

With async IO, `stream_set_blocking(false)` now works (`plain_wrapper.c:1082-1086` sets
`is_blocked=0` when `async_io != NULL`). Now `fread()` with `PeekNamedPipe` sees 0 available
bytes and returns 0 immediately (correct non-blocking behavior), exposing the `stream_select` bug.

### Conclusion

This is **not an async IO bug** — it is a Windows `select()` limitation. Our code correctly
implements non-blocking reads. The test relied on `stream_set_blocking(false)` silently failing,
which hid the `stream_select` pipe incompatibility.

### Possible solutions

1. Mark the test as `XFAIL` on Windows with async (minimal change)
2. Implement `stream_select` for Windows pipes via `PeekNamedPipe`/`WaitForMultipleObjects`
   instead of `select()` (proper fix, but significant work)

---

## Windows: bug51056 — TCP timing issue

### Problem

Test `ext/standard/tests/streams/bug51056.phpt` fails on Windows.

Server writes 8 bytes, `usleep(50000)`, 301 bytes, `usleep(50000)`, 8 bytes.
Client reads with `fread($fp, 256)`. Expected 4 reads: 8, 256, 45, 8.
Actual: 3 reads: 8, 256, 53 (last 45+8 merged).

### Root cause

The test uses `fsockopen()` → `php_stream_socket_ops` → `php_sockop_read`.
There is **no async IO integration** in `xp_socket.c`. This is a TCP socket, not a pipe.

The issue is TCP timing: 50ms `usleep` between writes is not enough to separate TCP segments
(Nagle's algorithm). The last two writes arrive as a single TCP segment on the client side.

**Not an async IO bug.**

---

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

## PDO Pool: protocol-level query cancellation on coroutine cancel

### Problem

When a coroutine is cancelled during an active SQL query (`SELECT SLEEP(5)`),
the cancellation interrupts `php_stream_read()` at the poll level. The MySQL/PgSQL
protocol stream is left in an inconsistent state — the response packet is partially
read or not read at all. The connection is broken ("MySQL server has gone away")
but the pool doesn't know and returns it to the next coroutine.

### Current workaround

Destroy the connection instead of returning it to the pool when the coroutine
finishes with an exception (implemented in `pdo_pool_binding_on_coroutine_finish`
by checking the `exception` parameter).

### Proper solution: protocol-level cancel

Instead of destroying the connection, send a protocol-level cancel command
via a **second connection**, then drain the response on the original connection:

- **MySQL**: `KILL QUERY <thread_id>` — cancels the running query on the server.
  The original connection receives an error response, protocol re-syncs.
- **PostgreSQL**: `PQcancel()` — libpq already provides this. Sends a cancel
  request to the backend, which interrupts the query and returns an error result.

After the cancel, read and discard the error response on the original connection.
The connection is now in a clean state and can be safely returned to the pool.

### Implementation notes

- Requires a helper/control connection per pool (or per cancel request)
- MySQL `thread_id` available via `mysql_thread_id()` on the connection
- PgSQL cancel object available via `PQgetCancel()` on the connection
- Must handle the case where the query finishes before the cancel arrives
- Driver-specific: each driver needs its own cancel implementation
- Consider adding `cancel_query` method to `pdo_dbh_methods_t`

