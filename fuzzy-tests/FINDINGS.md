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
