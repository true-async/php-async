# Chaos / fuzz tests for ext/async

This directory holds Gherkin-style scenario tests that exercise ext/async
under the chaos scheduler (`TRUE_ASYNC_SCHED=random:N`) and other fault
injection layers (see `STRATEGY.md` for the full strategy).

## Layout

Tests are grouped by topic, mirroring `ext/async/tests/`. Each topic
directory holds Gherkin `.feature` sources; the generator produces one
`.phpt` per `Scenario` / `Examples` row in `_generated/<topic>/`.

```
fuzzy-tests/
├── _harness/                # parser, executor, step definitions (PHP)
├── _peers/                  # EvilPeer / Toxiproxy / forked-peer fixtures
├── _generated/              # auto-generated .phpt files (gitignored)
├── _frozen/                 # frozen regression tests (committed)
│
│  # core primitives
├── channel/                 # send/recv/close/capacity/iterator/deadlock/scope-owned
├── coroutine/               # spawn/cancel/exception/finally/introspection/recursion
├── await/                   # await_all/any/_or_fail/first_success/any_of/mixed/cancel
├── future/                  # complete/error/map_catch_finally/cancel_token/locations
├── scope/                   # basic/nested/dispose/finally/exception_handler
├── task_group/              # basic/concurrency_limit/race/cancel/dispose/getters
├── task_set/                # join semantics
├── context/                 # context propagation
├── pool/                    # Async\Pool + CircuitBreaker
├── spawn_with/              # SpawnStrategy hooks
├── thread/ thread_channel/ thread_pool/   # real OS-thread parallelism
├── exceptions/              # CompositeException
│
│  # I/O + transport chaos (EvilPeer / Toxiproxy)
├── io/                      # streams, sockets, TLS, UDP, DNS, flock, feof,
│                            #   stream_select, fs_watcher, backpressure, reset
├── curl/                    # async curl_exec + curl_multi against evil HTTP peers
├── db/                      # PDO mysql/pgsql/sqlite, mysqli, pool, stmt cache
├── exec/                    # proc_open / proc_close storm + parked-reader race
│
│  # runtime internals
├── signal/ gc/ include/ iterate/ output_buffer/ protect/ remote_future/
└── cross_topic/             # shutdown_with_pending, cancel_during_io
```

Each topic mirrors a subsystem in `ext/async/tests/`. Run `./regen.sh`
then `_harness/coverage.php` to see the live API-coverage report
(`COVERAGE.md`).

## Workflow

Edit `.feature` files. Each `Scenario` and each row of a `Scenario Outline`
becomes one auto-generated `.phpt` under `_generated/`. The generated tests
are not committed — regenerate before running.

```sh
./fuzzy-tests/regen.sh                           # regenerate _generated/
make TESTS=ext/async/fuzzy-tests test            # run all chaos tests
TRUE_ASYNC_SCHED=random:42 make TESTS=ext/async/fuzzy-tests test
```

To reproduce a specific failing case:

```sh
make TESTS=ext/async/fuzzy-tests/_generated/channel_pair__01_...phpt test
```

## Freezing a failure

A chaos failure is only meaningful with *both* fuzz seeds — `CHAOS_GEN_SEED`
(value fuzz) and `TRUE_ASYNC_SCHED` (scheduler fuzz). `_generated/` .phpt
files pin the program but not the seeds, and `_generated/` is gitignored, so
a caught failure would vanish on the next regenerate.

`_harness/freeze.php` turns a failing run into a permanent, deterministic
regression test: it copies the generated .phpt into `_frozen/<topic>/` with
both seeds pinned in an `--ENV--` block. `_frozen/` **is** committed and runs
in CI like any other test — no environment setup needed to reproduce.

```sh
# replay the env the failure was found with — both seeds are picked up
TRUE_ASYNC_SCHED=random:42 CHAOS_GEN_SEED=7 \
    php fuzzy-tests/_harness/freeze.php \
        fuzzy-tests/_generated/io/backpressure__03_*.phpt
# -> froze -> fuzzy-tests/_frozen/io/backpressure__03_...__sched-random42__gen-7.phpt
```

Commit the frozen file; it then reproduces the exact case on every run.

## Writing a scenario

```gherkin
Feature: descriptive name

  Scenario: human-readable case
    Given a channel "ch" with capacity 0
      And a coroutine "A"
      And a coroutine "B"
     When coroutine "A" sends 5 messages to "ch"
      And coroutine "B" receives 5 messages from "ch"
     Then counter "sent_ch" equals counter "received_ch"
      And counter "received_ch" equals 5

  Scenario Outline: parameterised by Examples
    Given a channel "ch" with capacity <cap>
     When coroutine "A" sends <msgs> messages to "ch"
     ...
    Examples:
      | cap | msgs |
      | 0   | 5    |
      | 3   | 10   |
```

Steps are matched against regex patterns registered in
`_harness/Steps.php`. Add new step definitions there.

## Mutation blocks

A scenario can leave a step slot open and let the generator fill it several
ways. Two block kinds:

```gherkin
  Scenario: a future is created one way, then one thing happens to it
    Given a future "F"
      And a coroutine "P"
      And a coroutine "A"
    One of:
      - coroutine "P" completes future "F" with 1
      - coroutine "P" completes future "F" with 2
      - coroutine "P" fails future "F" with "boom"
    One of:
      - coroutine "A" awaits future "F"
      - coroutine "A" inspects locations of future "F"
     Then counter "fut_loc_bad_F" equals 0
      And no orphan coroutines
```

- **`One of:`** — exactly one `- ` alternative is chosen per generated `.phpt`.
- **`Any of:`** — any subset (the power set, including none and all).

Multiple blocks multiply: the example above expands to `3 × 2 = 6` `.phpt`
files, named `…__g0o2_g1o0.phpt` (group 0 → alternative 2, group 1 →
alternative 0). Mutation blocks combine with `Examples:` rows too — the
product of both axes is generated.

The generator emits at most **20** variants per scenario; a larger
combination space is sampled deterministically (seeded from the scenario
name, so re-running `regen.sh` is stable). Override the cap with a comment:

```gherkin
# @chaos-max 50
```

Because the chosen alternatives vary, `Then`-invariants must hold for **every**
variant — write them against counters (`attempts == ok + bad`), not against a
value that only one alternative produces.

## Fuzz syntax in step values

Inside a step value (after the regex captures it), the `ValueResolver` accepts:

| Syntax     | Resolves to                                      |
|------------|--------------------------------------------------|
| `42`       | the integer 42                                   |
| `1\|5`     | random int in [1, 5] (deterministic per RNG seed)|
| `1..5`     | same as above                                    |
| `random:N` | random int in [0, N)                             |
| `"text"`   | the string `text`                                |

The RNG is seeded from `CHAOS_GEN_SEED` (env), independent from the
scheduler RNG (`TRUE_ASYNC_SCHED=random:N`).

## Two-axis fuzzing

| Axis                 | Controlled by         | Default |
|----------------------|-----------------------|---------|
| Scheduler interleavings | `TRUE_ASYNC_SCHED` | fifo (deterministic) |
| Step value fuzz inputs  | `CHAOS_GEN_SEED`   | 1       |

Iterating both gives you a `(program × schedule)` matrix. Failing seeds are
reproducible by replaying the same pair.

## I/O chaos — crossing low-level faults with logic chaos

The `io/` topic adds a third axis: **transport chaos**. An `EvilPeer`
(`_peers/EvilPeer.php`) is a deliberately misbehaving network peer driven
by a declarative fault table — toxics such as payload slicing, inter-chunk
drip delay and abrupt mid-stream close. Toxic parameters accept the fuzz
syntax above (`random:N`, `1|5`), so they are seeded by `CHAOS_GEN_SEED`.

The point is to **cross** the axes around a *fixed* data oracle:

| Plane          | State  | Knob                                    |
|----------------|--------|-----------------------------------------|
| data / protocol | FIXED  | the EvilPeer payload — the known answer |
| transport       | chaos  | EvilPeer toxics (mutation blocks)       |
| logic / program | chaos  | `One of:` / `Any of:` mutation blocks   |
| scheduler       | chaos  | `TRUE_ASYNC_SCHED`                      |

Because the payload is fixed the invariant stays decidable over the whole
`transport × logic × schedule` product. When all axes are crossed, assert
only **universal** invariants — liveness (the coroutine always finishes)
and safety (received bytes are always a prefix of the payload). See
`io/combined_chaos.feature`.

## The chaos event log

On a **failure** the executor prints a `chaos-log:` — the exact low-level
sequence that produced it: each EvilPeer's resolved toxic parameters and
delivery trace (`slice=33 delay=2 reset=-1 | w33 d2 w33 … close@256`) and
every client's I/O trace. A failure is debuggable without a re-run.

A failing run is fully specified by five recoverable coordinates:
feature + scenario, the mutation combo (in the `.phpt` name, e.g.
`__g0a0a1_g1o1`), `CHAOS_GEN_SEED`, `TRUE_ASYNC_SCHED`, and the resolved
EvilPeer fault table (from the chaos-log).

### Freezing a failure into a regression test

A chaos failure is meant to be **frozen** into a deterministic, non-chaos
test — the proper end of the workflow ("every found bug becomes a fixed
scenario"). Remove all randomness:

1. **flatten** the mutation blocks to the failing combo — one plain branch,
   no `One of:` / `Any of:`;
2. **resolve** `random:N` / `1|5` toxic values to the literals the
   chaos-log reported (`slice 33`, `delay 2`);
3. **pin** the scheduler — `fifo` (or a fixed `random:N`).

The result is an ordinary deterministic `.feature` — or a hand-written
`tests/io/NNN-*.phpt` regression test. Parameter-level freezing is enough
for full determinism: slicing is deterministic given fixed parameters, and
pinning the scheduler removes the only remaining source of variance.
