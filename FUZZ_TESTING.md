# Fuzz & Chaos Testing Strategy for ext/async

## Motivation

The existing 1000+ phpt tests in `ext/async/tests/` validate functional
correctness under **one specific execution order**: the deterministic FIFO
scheduler, a pristine loopback network, and a well-behaved allocator. This
catches API-level regressions well, but leaves three classes of bugs almost
entirely uncovered:

1. **Race conditions** — bugs that only manifest when coroutines are scheduled
   in an unusual order.
2. **I/O edge cases** — partial reads, `EAGAIN` storms, mid-stream `RST`,
   backpressure, reorder, duplicates.
3. **Cleanup-on-failure bugs** — dangling handles, refcount leaks, double-close
   when a coroutine is cancelled mid-operation or allocation fails.

The strategy below layers on top of the existing tests rather than replacing
them. Every tool reuses the same seed, so any failure is reproducible by
`ASYNC_FUZZ_SEED=N make test-chaos`.

## Layers

### Layer 1 — Scheduler fuzzing (highest value for effort)

The scheduler's ready queue is a circular buffer. At each `pop_next()` we
insert a fuzz hook that, under a debug build, optionally swaps the head slot
with another occupied slot before popping:

```c
#ifdef ZEND_ASYNC_FUZZ
    if (r->size > 1 && async_fuzz_coin(&r->rng)) {
        size_t j = async_fuzz_index(&r->rng, r->size);
        size_t a = r->head;
        size_t b = (r->head + j) % r->capacity;
        SWAP(r->slots[a], r->slots[b]);
    }
#endif
```

This preserves the ring-buffer data structure, adds O(1) per pop, and is fully
`#ifdef`'d out of release builds. Three modes are selected by env var:

- `ASYNC_SCHED=fifo` — production behaviour, hook is inert.
- `ASYNC_SCHED=random:SEED` — coin-flip swap every pop (Monte-Carlo interleaving).
- `ASYNC_SCHED=pct:SEED:K` — PCT algorithm (Microsoft Research): K priority
  change points across the run; between them scheduling is deterministic. PCT
  provides a probabilistic guarantee of finding any bug of depth `d` with
  probability `1 / (n · k^(d-1))`, which beats pure random for deep races.

The 1000 existing phpt tests, run with 1000 different seeds each, become a
million distinct interleaving checks — essentially free coverage.

Exclusions: system events (timers, libuv callbacks) must not be reordered
relative to user coroutines — swap is skipped for queue slots tagged
`SYSTEM`.

### Layer 2 — I/O chaos

**Toxiproxy** (Shopify, mature, HTTP-API controlled) sits between async client
code and a real server. Configurable "toxics" applied per test:

- `latency` with jitter
- `slicer` — chop TCP stream into random small chunks (exposes partial-read bugs)
- `reset_peer` — RST after N ms
- `bandwidth` / `limit_data` — backpressure scenarios
- `slow_close` — delayed FIN
- `timeout` — stall after N bytes

For scenarios Toxiproxy cannot model (malformed payloads, accept-then-RST,
garbage responses, never-reads-sockets peer), a 30-line in-process PHP
EvilPeer under `tests/chaos/_peers/evil-peer.php` serves the same role with a
seeded fault table.

### Layer 3 — Runtime-internal I/O hooks

ChaosPeer tests user-visible I/O behaviour. It does not catch bugs inside the
runtime itself — lost wakeups, spurious EAGAIN loops, double `uv_close`,
cancellation-during-read races. For those we add a handful of
`ZEND_ASYNC_FUZZ`-gated hooks at libuv integration points:

- In the read callback: occasionally split a delivered chunk into two
  `uv_async_send` deliveries.
- Before `uv_read_start` / `uv_write`: occasionally cancel the current
  coroutine to test cleanup on abort.
- At the point a coroutine is about to park on I/O: occasionally wake it
  immediately (spurious wakeup) to verify the condition is rechecked on
  resume.

Two to four surgical hooks. Driven by the same RNG as the scheduler hook, so
one seed reproduces everything.

### Layer 4 — tc netem (kernel-level network chaos)

Activated in CI only, one-time per run:

```bash
tc qdisc add dev lo root netem delay 5ms 2ms loss 0.1% reorder 1% duplicate 0.1%
```

Zero source-code integration. Catches timing-dependent bugs (timers firing on
the boundary of a reply, heartbeat races, duplicate-packet handling) that
loopback would never exercise. Requires `CAP_NET_ADMIN` — so gated to
container-based CI, not dev machines.

### Layer 5 — Allocator fault injection

PHP's `emalloc` can be wrapped in debug builds to fail with probability `p`
controlled by `ASYNC_ALLOC_FAULT_RATE`. Exposes missing error paths in the
runtime (which usually assume allocation succeeds). Must be gated so it only
affects allocations issued from within the async runtime or from code running
inside a coroutine — otherwise PHP's own startup paths fail and the test
harness never reaches user code.

## Other techniques to keep on the roadmap

| Technique | What it catches | Cost |
|---|---|---|
| **Differential testing**: run the same operation both sync and async, compare outcomes | Divergent semantics between sync/async paths | Medium — requires paired test harness |
| **ThreadSanitizer (TSan)** on the thread-pool path | Real data races across OS threads (not coroutines) | Low — just a build mode |
| **Valgrind / Helgrind** on a subset of tests | Memory leaks, uninit reads, races | High runtime; nightly only |
| **Soak tests**: run one test in a loop for hours, track RSS | Slow leaks, handle exhaustion, state drift | Nightly/weekly only |
| **Stress multiplicity**: 10k coroutines doing the same op | Queue overflow, lock contention, scheduler starvation | Low |
| **Signal injection**: SIGTERM/SIGINT at random points during async work | Shutdown-path bugs, partial cleanup | Low |
| **Shutdown-order fuzzing**: close pool / scheduler while coroutines still live | Use-after-free on teardown | Low |
| **Delta debugging / seed minimisation**: when a seed fails, auto-reduce test input | Human time saved on triage | One-time tooling |
| **`rr` record-replay** for triage of rare races | Precise stepping through a reproducer | Dev-time tool, not CI |
| **Time-travel of event ordering**: fuzz the order in which ready libuv events are delivered | Handler-ordering bugs | Hook at libuv loop iteration |
| **Persistence / worker-restart fuzzing**: pool state across process boundaries | Serialization gaps, dangling refs | Medium |

These do not need to be built up-front. The scheduler hook (layer 1) and
ChaosPeer (layer 2) should find the bulk of issues; the rest is added when a
specific bug class starts recurring.

## Directory layout

A **separate directory** `ext/async/tests/chaos/` is recommended, not a
separate top-level folder:

```
ext/async/tests/
├── await/                     # existing functional phpt
├── channel/
├── ...                        # (existing)
└── chaos/                     # new
    ├── README.md              # how to run, seed conventions
    ├── _peers/
    │   └── evil-peer.php      # in-process malformed-peer server
    ├── _harness/
    │   ├── toxiproxy.inc      # start/stop helper
    │   └── seed_matrix.inc    # iterate test × seed
    ├── scheduler/             # tests that ONLY make sense with ASYNC_SCHED≠fifo
    ├── io/                    # tests targeting Toxiproxy + EvilPeer
    ├── cancellation/          # cancel-during-X races
    └── fault_injection/       # allocator faults, signal injection
```

Rationale:

1. **Different runtime contract.** Chaos tests may be flaky *by design* (a
   test fails on 1 seed in 10000) — mixing them with deterministic phpt breaks
   the "all green or broken" invariant.
2. **Different runner.** `run-tests.php` runs each test once. Chaos tests need
   a seed matrix wrapper — the wrapper lives alongside them.
3. **Different build requirement.** Chaos tests require `--enable-async-fuzz`
   (see below); functional tests must keep running on a release build.
4. **Different CI tier.** Functional tests run per-PR. Chaos tests run
   nightly with aggregated reporting (`N seeds passed / M failed / which`).
5. **Reuse, not duplication.** The most important chaos coverage comes from
   running **existing** phpt tests under chaos modes, not from writing new
   tests. `ext/async/tests/chaos/` is for tests that specifically assert
   chaos-mode behaviour (e.g. "refcount is zero after forced cancellation").

## Build flag

A dedicated configure flag is warranted:

```
./configure --enable-async-fuzz ...
```

Sets the preprocessor macro `ZEND_ASYNC_FUZZ=1`, which in turn activates:

- Scheduler swap hook in the ring buffer
- libuv I/O chaos hooks
- Allocator fault-injection wrapper
- Additional invariant assertions (e.g. "no coroutine outlives its pool slot")

**Why a separate flag and not `ZEND_DEBUG`:**

- `ZEND_DEBUG` is used by many developers during normal development; forcing
  them to pay fuzz-hook overhead (even small) is wrong.
- Fuzz builds are meant to be combined with `--enable-address-sanitizer` /
  `--enable-undefined-sanitizer`. A dedicated flag makes that intent explicit.
- The flag can gate extra runtime diagnostics that would be too noisy in a
  normal debug build (fault logs, scheduler decision traces).
- A CI matrix can run `release`, `debug`, `debug + async-fuzz + ASAN`
  separately without entanglement.

**Guarantees:**

- When `--enable-async-fuzz` is off, every hook is `#ifdef`'d to nothing. Not
  gated by a runtime flag — literal zero bytes in the binary. This matters
  both for performance and for audit: reviewers can confirm production builds
  are untouched.
- When on but `ASYNC_SCHED=fifo` and no other env vars are set, behaviour is
  byte-for-byte identical to a non-fuzz build (hooks check the RNG state and
  early-out). This lets CI use one binary for all chaos modes.

## Seed and reproducibility

All sources of non-determinism read from a single `ASYNC_FUZZ_SEED` (64-bit
integer from env). From it we derive substreams via SplitMix64 for:

- Scheduler decisions (`r->rng`)
- I/O chaos decisions (libuv hooks)
- Allocator fault decisions
- EvilPeer fault table
- Toxiproxy toxin configuration

A failing CI run prints the seed as its **first line** on failure:

```
ASYNC_FUZZ_SEED=0x3f9a2b17cd88e401   test=ext/async/tests/channel/drain.phpt
```

Re-running with the same seed and the same binary reproduces the failure.
Failing seeds are appended to `ext/async/tests/chaos/_failed_seeds.txt` (not
checked into git) for local triage, and uploaded as a CI artifact.

## CI tiers

- **Per-PR** (fast, ≤5 min): functional phpt tests, no fuzz.
- **Per-PR optional** (`[ci-chaos]` in commit message): layer 1 + layer 2
  with 10 seeds per test.
- **Nightly** (~1 hour): full chaos suite, 100 seeds per test, Toxiproxy
  enabled, tc netem enabled, ASAN+UBSAN on.
- **Weekly** (~6 hours): soak tests, Valgrind/Helgrind on a subset, allocator
  fault injection at multiple rates.

A failing nightly is not a release blocker by default — it's an issue to
triage. A failing per-PR run is a blocker, so the per-PR chaos tier must have
a very low false-positive rate (hence fewer seeds and only layer 1 + 2).

## What this strategy is NOT

- It is **not** a byte-level fuzzer for async APIs. PHP's built-in libFuzzer
  SAPI in `sapi/fuzzer/` already covers parser/unserialize/etc.; adding a
  `php-fuzz-async` there would mostly replicate existing phpt coverage at
  higher cost. The bugs we're hunting are ordering and I/O bugs, not input
  bugs.
- It is **not** a replacement for phpt. Functional tests stay as the primary
  correctness signal. Chaos is additive.
- It is **not** a goal to pass under the most hostile settings. A run with
  `loss 50% latency 2s reset_peer every byte` will fail — and should. The
  goal is to define a **realistic-but-adversarial profile** that we commit to
  pass, and to reproduce any failure under it.

## References

- PCT algorithm: Burckhardt et al., "A Randomized Scheduler with
  Probabilistic Guarantees of Finding Bugs", ASPLOS 2010.
- Greybox fuzzing for concurrency: Wolff et al., ASPLOS 2024.
- Toxiproxy: https://github.com/Shopify/toxiproxy
- tc-netem manual: `man 8 tc-netem`.
- PHP fuzzer SAPI: `sapi/fuzzer/README.md` (byte-level, different purpose).
