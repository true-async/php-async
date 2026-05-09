# Chaos / fuzz tests for ext/async

This directory holds Gherkin-style scenario tests that exercise ext/async
under the chaos scheduler (`TRUE_ASYNC_SCHED=random:N`) and other fault
injection layers (see `../FUZZ_TESTING.md` for the full strategy).

## Layout

```
fuzzy_tests/
├── _harness/             # parser, executor, step definitions (PHP)
├── _generated/           # auto-generated .phpt files (gitignored)
├── *.feature             # Gherkin scenario sources (committed)
└── *.phpt                # hand-written static chaos tests (committed)
```

## Workflow

Edit `.feature` files. Each `Scenario` and each row of a `Scenario Outline`
becomes one auto-generated `.phpt` under `_generated/`. The generated tests
are not committed — regenerate before running.

```sh
./fuzzy_tests/regen.sh                           # regenerate _generated/
make TESTS=ext/async/fuzzy_tests test            # run all chaos tests
TRUE_ASYNC_SCHED=random:42 make TESTS=ext/async/fuzzy_tests test
```

To reproduce a specific failing case:

```sh
make TESTS=ext/async/fuzzy_tests/_generated/channel_pair__01_...phpt test
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
