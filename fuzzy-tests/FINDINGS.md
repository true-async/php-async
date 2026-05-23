# Chaos-test findings

Observations surfaced by the `fuzzy-tests/` chaos suite while chasing
invariant violations. Each entry records what was seen, what it turned out
to be, and how the suite was adjusted.

## Safe-scope zombie coroutines (not a leak)

A `disposeAfterTimeout` chaos scenario tripped the "no orphan coroutines"
invariant: a child coroutine stayed alive after the scope was disposed.

Investigated — it is **expected behaviour**, not a leak. A *safe* scope (the
default; `Scope::inherit()` inherits `DISPOSE_SAFELY` from the root scope)
does not forcibly cancel an already-started child on `cancel()` / `dispose()`.
It marks the child a **zombie** (`coroutine.c`, `async_coroutine_cancel`,
`is_safely` branch), drops it from the active coroutine count, and lets it
finish on its own. `asNotSafely()` opts into forced cancellation.

The chaos scenario now builds the scope with `asNotSafely()` so it exercises
the real cancel/reap path and the invariant holds.

**Side effect worth noting:** a zombie parked in a long `delay()` keeps its
libuv timer armed — and therefore the event loop alive — until that timer
naturally expires. For `disposeAfterTimeout`, whose purpose is *bounded*
cleanup, this means the loop can still linger for the child's full sleep.
Tracked as a design question (kill zombies once only zombies remain?).

## Writer hangs when the peer resets the connection (real bug — fixed)

The `io/backpressure` chaos feature uploads more than the loopback buffers
can hold, so the client's `fwrite()` suspends on the reactor's write-wait
hook. The "peer drains part then abandons the connection" scenario hung:
the writer, parked on `ASYNC_WRITABLE`, never woke after the peer's RST.

Root cause in the reactor poll layer. When a socket is reset, libuv reports
the `POLLERR` as `UV_EBADF`; `on_poll_event` (`libuv_reactor.c`) turns that
into a bare `ASYNC_DISCONNECT`. `async_poll_notify_proxies` then woke only
proxies whose mask intersected the triggered events. A **read** proxy's mask
is `ASYNC_READABLE | ASYNC_DISCONNECT`, so readers woke — but a **write**
proxy's mask is `ASYNC_WRITABLE` only, so `ASYNC_DISCONNECT & ASYNC_WRITABLE
== 0` and the writer was never notified. libuv had already stopped the poll
handle, so the coroutine hung forever.

Fixed in `async_poll_notify_proxies`: a disconnect or error is terminal for
the descriptor — every proxy on it is now released, reader or writer, not
only those whose mask requested `DISCONNECT`. Regression test:
`tests/stream/046-write_wakes_on_peer_reset.phpt`.

## Concurrent async writes to one descriptor corrupt the heap (real bug — fixed)

Adding a hard-reset (`SO_LINGER`) toxic for `io/hard_reset.feature` needed
`socket_import_stream()` — which looked like it corrupted the heap. It did
not: narrowing the repro to four coroutines doing nothing but concurrent
`fwrite(STDERR, …)` — no sockets at all — still crashed 12/12 (ASAN: SEGV in
`uv__async_io` calling a NULL callback).

Root cause in the async file/pipe I/O layer. A `zend_async_io_req_t` is a
plain result struct; the only awaitable event lives on the *handle*
(`io->base.event`). Every coroutine writing one descriptor parks on that one
shared event, so a single write's completion `NOTIFY`'d them all.
`php_stdiop_write()` did not re-check `req->completed` after waking, so a
spuriously woken coroutine disposed its own request while that request's
`uv_write` was still in flight — libuv then wrote into freed memory.

Fixed in `php_stdiop_write()` / `php_stdiop_read()`: re-suspend until *this*
request completed. Regression test `tests/io/083-concurrent_async_write.phpt`.
The structural fix — per-request completion events instead of the broadcast —
is tracked in true-async/php-async#130.

## Async curl drops a chunked response body (real bug — fixed)

The new `curl/http_chaos.feature` (issue #136) drives an async `ext/curl`
client against the EvilPeer in its `http` mode. Every scenario passed except
the three "chunked transfer encoding" rows, which failed with
`curl_get_ok == 0`: curl reported `CURLE_WRITE_ERROR` —
*"Failure writing output to destination, passed 272 returned 17"* — after
delivering only the first 17-byte chunk to the `CURLOPT_WRITEFUNCTION`
callback. The same program on stock PHP 8.3 returns the whole body.

Root cause in `ext/curl/curl_async.c`. The async write path uses libcurl's
`CURL_WRITEFUNC_PAUSE` / unpause pattern: the first `curl_write` call copies
the data, spawns a coroutine for the PHP callback and returns `PAUSE`; the
completion callback stores the callback's return value and unpauses, and the
*re-call* returns that stored value. The re-call branch assumed libcurl
re-delivers exactly the slice that was paused on — but on unpause libcurl
re-delivers the whole paused window **and coalesces any freshly decoded data
into it**. With chunked transfer-encoding the de-chunker produces many small
pieces, so the re-call carried 272 bytes while the stored result was 17 →
`passed 272 returned 17` → `CURLE_WRITE_ERROR`. (A fixed Content-Length body
arrives one network read at a time, one write callback per reactor wakeup,
so it never tripped — only chunked decoding coalesces.)

Fixed in `curl_async_write_user()`: the re-call now tracks a
`consumed_offset` through the (possibly grown) window — it reports the full
length back to libcurl only once the PHP callback has accepted every byte,
otherwise it feeds the remainder through another callback slice. A genuine
short return / exception still surfaces verbatim via a new `aborted` flag.
Tracked in php-src as `#136`.

## Async PDO MySQL pool leaks a raw warning on a dropped connection (observation)

The `db/mysql_chaos.feature` suite (issue #136) fronts a real MySQL server
with Toxiproxy and drops the connection mid-query with the `reset_peer`
toxic. With `PDO::ATTR_ERRMODE = ERRMODE_EXCEPTION` the driver correctly
raises a `PDOException` — but the **pool-enabled** path also emits a bare
`E_WARNING` ("Error while reading greeting packet") from mysqlnd on top of
the exception, where the non-pooled `new PDO()` path of the same failing
connect does not.

It does not break error handling — the exception still propagates and is
caught — so the chaos steps simply `@`-silence the expected noise, the same
way the raw-socket I/O steps already do. Worth a follow-up: under
ERRMODE_EXCEPTION the pool's internal connect (`pdo_pool_acquire_conn` →
`db_handle_factory`) should suppress the low-level mysqlnd warning the way
the direct constructor path does. Not a correctness bug; tracked as a
loose end, not fixed here.
