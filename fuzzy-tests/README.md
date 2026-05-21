# Chaos / fuzz tests for ext/async

This directory holds Gherkin-style scenario tests that exercise ext/async
under the chaos scheduler (`TRUE_ASYNC_SCHED=random:N`) and other fault
injection layers (see `../FUZZ_TESTING.md` for the full strategy).

## Layout

Tests are grouped by topic, mirroring `ext/async/tests/`. Each topic
directory holds Gherkin `.feature` sources; the generator produces one
`.phpt` per `Scenario` / `Examples` row in `_generated/<topic>/`.

```
fuzzy-tests/
├── _harness/                # parser, executor, step definitions (PHP)
├── _generated/              # auto-generated .phpt files (gitignored)
├── channel/                 # Channel send/recv/close/cap chaos
│   ├── send_recv_pair.feature
│   └── close.feature
├── coroutine/               # spawn/await/cancel chaos
│   └── many_complete.feature
├── scope/                   # (TODO) scope cancel/dispose ordering
├── future/                  # (TODO) Future complete vs await race
├── await/                   # (TODO) await_all / await_any cancellation
├── task_group/              # (TODO) join semantics under cancel
├── thread_channel/          # (TODO) cross-thread channel ordering
└── thread_pool/             # (TODO) real-parallel worker chaos
```

Topics with `_TODO.md` are placeholders awaiting features.

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
