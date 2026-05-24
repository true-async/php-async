# IO chaos coverage plan

Working plan for expanding chaos coverage of I/O operations under
`ext/async`. Companion to `PLAN.md` (which covers core primitives:
channels, scopes, futures, coroutines, task groups, thread pool).

## What this targets

PHP code that goes through the libuv reactor via php-src async hooks:

- `main/network_async.c` (116 ZEND_ASYNC refs) — main stream hook:
  `await_stream_socket`, `stream_select`, `accept_incoming`,
  `connect_socket`.
- `main/streams/plain_wrapper.c` (75 refs) — file IO async hooks.
- `main/streams/xp_socket.c` (15 refs) — socket transport async.
- `ext/async/libuv_reactor.c` — reactor backend (timers, fs, sockets).

Hand-written `ext/async/tests/{io,stream,socket}/` already covers happy
paths and many edge cases (~140 tests). The chaos angle complements them
with **race conditions and cancellation-during-I/O** that fixed-output
phpt tests cannot express.

## Existing baselines worth referencing

- `tests/stream/045-accept_cancel_uaf.phpt` — accept blocked, cancel
  during graceful shutdown. Cited from `fuzzy-tests/cross_topic/
  cancel_during_io.feature`.
- `tests/io/007-pipe_close_during_io.phpt` — proc_open + pipes pattern.
- `tests/stream/004-stream_socket_client_server.phpt` — TCP client/
  server full cycle.

## Already done (this branch)

- `fuzzy-tests/cross_topic/cancel_during_io.feature` — TCP accept and
  pipe read under direct `$coro->cancel()`. Layer 1 starter.

## Layer 1 — in-process chaos, no external peers

Local sockets (`stream_socket_pair`, loopback `tcp://127.0.0.1:0`) and
pipes are enough. No new harness infra beyond what's already in place.

Goal: every reactor I/O path is exercised under random scheduling with
cancel/close racing the wait.

### High priority (race surface most likely to surface UAF / leaks)

- **`io/cancel_during_connect.feature`** — TCP connect to a blackhole
  address (192.0.2.1:81 — RFC 5737 TEST-NET-1), killer cancels mid-wait.
  Reactor must release the connect request without UAF.

- **`io/cancel_during_write.feature`** — fill `SO_SNDBUF` (small), then
  fwrite blocks waiting for writeable. Cancel mid-block.

- **`io/stream_close_during_read.feature`** — coroutine A blocked in
  `fread`, coroutine B calls `fclose` on the same handle. Scheduler
  picks the order. Must not corrupt the reactor request list.

### Medium priority (correctness invariants)

- **`io/concurrent_readers.feature`** — pipe pair, N readers on the
  read end, sender pushes N messages. Cancel half. Invariant:
  `received_total + recv_failed_total == N`.

- **`io/stream_select_chaos.feature`** — `stream_select` over a set of
  streams; data arrives on 1–2 mid-select; vary `tv` and cancel the
  selecting coroutine.

- **`io/connect_with_timeout.feature`** — TCP connect to unreachable
  with explicit timeout. Timeout must fire, no leaked watcher.

### Low priority (file IO race / consistency)

- **`io/file_concurrent_writes.feature`** — 10 coroutines write distinct
  payloads to one file; final size equals sum of writes (use `flock` or
  offset-based writes).

## Layer 2 — protocol-level fault injection

Per `FUZZ_TESTING.md` this is future work. Lives in the same
`fuzzy-tests/` tree under a topic subfolder (e.g. `fuzzy-tests/io/` or
`fuzzy-tests/net/`) — no separate harness, reuse the existing one.

Helper scripts (Toxiproxy controller, EvilPeer) live next to the
existing `_harness/` and `_peers/` (the latter to be created when
needed) — same Runner, same Steps registry, just additional step
definitions for fault injection.

- **Toxiproxy** between client and server — toxin types: `slicer`
  (chunked TCP), `latency`, `bandwidth`, `timeout`, `reset_peer`.
- **EvilPeer** — in-process PHP script (`fuzzy-tests/_peers/evil-peer.php`)
  that accepts and behaves badly: RST on accept, garbage payloads,
  never-reads-then-EOF, lengths-don't-match. ~30 lines.

### Planned features (Layer 2)

- **`io/tcp_partial_writes.feature`** — Toxiproxy slicer → server reads
  in random small chunks; client behaviour must be byte-stream
  semantically equivalent regardless.

- **`io/tcp_disconnect_mid_request.feature`** — peer drops TCP
  connection in the middle of an exchange.

- **`io/dns_slow.feature`** — slow DNS, async resolver must remain
  interruptible.

- **`io/evil_peer_garbage.feature`** — peer sends well-formed framing
  with garbage payloads; client must report parse error, not crash.

## Layer 4 — kernel-level network chaos

Per `FUZZ_TESTING.md`: `tc netem` for loss / reorder / corruption /
duplicate. Out of scope until Layers 1+2 are done. Mentioned for
completeness — tracks at OS level, not user-visible from a PHP test.

## Harness considerations for Layer 1

Most of the planned features need only steps that are already drafted
or trivial extensions thereof:

- `coroutine "X" listens for one connection on a fresh socket` ✓ (done)
- `coroutine "X" reads from a fresh pipe` ✓ (done)
- `coroutine "X" connects to "<host>:<port>"` — new
- `coroutine "X" fills socket then writes <N> bytes` — new
- `coroutine "X" closes file descriptor used by "Y"` — new (cross-coro
  fd ref; needs a small `$ctx->ioFds[$name]` map)
- `coroutine "X" runs stream_select on registered streams` — new

Counter conventions mirror channel chaos:
`io_<verb>_attempts_$X` / `io_<verb>_ok_$X` /
`io_<verb>_cancelled_$X` / `io_<verb>_failed_$X` /
`io_<verb>_timeout_$X`. Sum invariant: pre-try cancel may leave all
buckets at 0 (set up before the try block was a yield point — this is
documented in `cross_topic/cancel_during_io.feature`).

## Coverage gap relative to hand-written phpt

Updated after #127/#129/#138 closed Layer 1+2 IO. Remaining gaps tracked
under umbrella issue **#143**.

| Subsystem | Hand-written | Chaos |
|-----------|--------------|-------|
| TCP accept | 016, 045 | partial (`cancel_during_io`) |
| TCP connect | 007, 031, 039 | ✓ #138 (`cancel_during_connect`, `connect_with_timeout`, `connect_v6`) |
| TCP read/write under cancel | none | ✓ #127 (`stream_close_during_read`, `backpressure`, `hard_reset`) |
| pipe read | 007 (io), 044 | ✓ #127 (`concurrent_readers`) |
| pipe write under back-pressure | none | ✓ #129 (`backpressure`) |
| stream_select | 005, 010, 017–023, 032–037 | ✓ #138 (`stream_select_chaos`) |
| SSL connect (client) | 025–026 | ✓ #138 (`tls_connect`) |
| SSL accept (server) | 027 | **TODO — #143** |
| UDP | 028–030 | ✓ #138 (`udp_chaos`) |
| File IO concurrent | 049, 056, 060, 069, 083 | ✓ #138 (`file_concurrent_writes`) |
| feof semantics | 038–044 | **TODO — #143** |
| flock under reactor | 081 | **TODO — #143** |
| proc_open / exec / shell_exec | exec/001–024 | **TODO — #143** (whole subsystem, UAF class proven in `011`) |
| curl_multi | curl/003, 010 | **TODO — #143** (single-handle covered by #136) |
| socket ext (POSIX sockets) | socket/001–004 | **TODO — #143** (separate path from streams) |
| DNS | dns/001–015 | ✓ #138 (`dns_slow`) |
| signals | signal/* | ✓ #138 (`signal_chaos`) |
| FileSystemWatcher | fs_watcher/* | ✓ #138 (`fs_watcher_chaos`) |

## Layer 3 — process / subprocess chaos (#143)

`proc_open`/`exec` were skipped through Layers 1–2 because they need a
real child process. They're the next white zone:

- **`exec/proc_open_chaos.feature`** — `proc_close()` races a parked
  `fread()` on the child's stdout (mirrors `io/stream_close_during_read`
  but with a real OS pipe to a real process). Killer cancels the reader
  or closes the proc handle. Invariant: no UAF on the process handle
  (regression backstop for `tests/exec/011`).
- **`exec/proc_concurrent.feature`** — N coroutines `proc_open` concurrent
  children + collect output. Cancel half. Reactor must reap every child
  without zombies.
- **`exec/proc_signal.feature`** — coroutine reads stdout, another sends
  SIGTERM. Read must surface EOF cleanly, exit-code via `proc_close`.

## Layer 3 — curl_multi chaos (#143)

`http_chaos.feature` (#136) drove `curl_exec` (single handle). The
`curl_multi_*` reactor path (`ext/curl/curl_async.c` — same file as the
#136 chunked-bug fix) is untouched.

- **`curl/curl_multi_chaos.feature`** — N parallel transfers through
  EvilPeer toxics; cancel mid-`curl_multi_select`; close one handle
  mid-flight; mix slow / fast peers.

## Tier strategy (recommended once Layer 1 is filled)

Per `FUZZ_TESTING.md`:

- **Per-PR (~5 min)**: fifo + 2 random seeds across all Layer 1 IO
  features + existing chaos suite.
- **Nightly (~1 hour)**: 100 random seeds × Layer 1, plus Layer 2 with
  Toxiproxy enabled.

Currently neither tier is wired into CI — would need workflow files.
