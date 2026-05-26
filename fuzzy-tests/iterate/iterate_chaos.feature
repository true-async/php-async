Feature: Async\iterate() concurrency / cancel / exception chaos

  Async\iterate(iterable, callable, concurrency, cancelPending) is a
  fan-out primitive: a callback runs per item with at most `concurrency`
  in-flight, and on exit any callback-spawned children are either
  cancelled (cancelPending=true) or awaited (cancelPending=false). It is
  a thin layer over coroutine bookkeeping that several pieces — concurrency
  cap, cancellation propagation, generator pull, child cleanup — must keep
  in sync under random scheduling.

  Hand-written backstop: ext/async/tests/iterate/{008,011,012,013}.phpt
  pin one deterministic shape each (concurrency cap, sibling-cancel on
  exception, cancelPending true/false). This feature crosses those into
  chaos by varying N, concurrency, suspend depth, callback exception index,
  and overlaying an external killer.

  Liveness / safety invariants (must hold under ANY interleaving):
    iter_attempts_X  == iter_done_X + iter_cancelled_X + iter_failed_X
    iter_cb_started  == iter_cb_finished + iter_cb_cancelled + iter_cb_threw
    no orphan coroutines

  Scenario Outline: clean iterate — every callback runs to completion
    Given a coroutine "I"
     When coroutine "I" iterates <n> items with concurrency <k> via callback that suspends <m> times
     Then counter "iter_attempts_I" equals 1
      And counter "iter_done_I" plus counter "iter_cancelled_I" plus counter "iter_failed_I" equals 1
      And counter "iter_cb_finished_I" plus counter "iter_cb_cancelled_I" plus counter "iter_cb_threw_I" equals counter "iter_cb_started_I"
      And no orphan coroutines

    Examples:
      | n  | k | m |
      | 4  | 1 | 1 |
      | 8  | 2 | 2 |
      | 16 | 4 | 3 |
      | 5  | 5 | 1 |

  Scenario Outline: callback throws at index I — iterate surfaces error, siblings settle
    # Mirrors tests/iterate/011: a throw in the middle of a concurrent fan-out
    # must propagate out of iterate(), and every other started callback must
    # be cancelled (or have already finished).
    Given a coroutine "I"
     When coroutine "I" iterates <n> items with concurrency <k>, callback throws at index <idx>
     Then counter "iter_attempts_I" equals 1
      And counter "iter_done_I" plus counter "iter_cancelled_I" plus counter "iter_failed_I" equals 1
      And counter "iter_cb_finished_I" plus counter "iter_cb_cancelled_I" plus counter "iter_cb_threw_I" equals counter "iter_cb_started_I"
      And counter "iter_cb_threw_I" is at least 1
      And no orphan coroutines

    Examples:
      | n  | k | idx |
      | 5  | 3 | 2   |
      | 8  | 4 | 0   |
      | 12 | 6 | 7   |

  Scenario Outline: cancelPending=true — spawned children must all settle
    # Backstop for tests/iterate/012: every started child must either finish
    # before iterate() returns, or be cancelled by the cancelPending sweep.
    # No child is allowed to remain alive past iterate() return.
    Given a coroutine "I"
     When coroutine "I" iterates <n> items with concurrency <k>, callback spawns child suspending <m> times, cancelPending true
     Then counter "iter_attempts_I" equals 1
      And counter "iter_child_finished_I" plus counter "iter_child_cancelled_I" equals counter "iter_child_started_I"
      And no orphan coroutines

    Examples:
      | n | k | m  |
      | 3 | 1 | 4  |
      | 6 | 3 | 8  |
      | 8 | 4 | 10 |

  Scenario Outline: cancelPending=false — iterate awaits every spawned child
    # Backstop for tests/iterate/013: cancelPending=false guarantees iterate()
    # only returns after every spawned child has completed normally.
    Given a coroutine "I"
     When coroutine "I" iterates <n> items with concurrency <k>, callback spawns child suspending <m> times, cancelPending false
     Then counter "iter_attempts_I" equals 1
      And counter "iter_done_I" plus counter "iter_cancelled_I" plus counter "iter_failed_I" equals 1
      And counter "iter_child_finished_I" plus counter "iter_child_cancelled_I" equals counter "iter_child_started_I"
      And no orphan coroutines

    Examples:
      | n | k | m |
      | 3 | 1 | 2 |
      | 6 | 2 | 3 |
      | 8 | 4 | 4 |

  Scenario Outline: iterate over a generator — lazy pull semantics
    # Generator/Traversable branch of iterate() (tests/iterate/005,009).
    Given a coroutine "I"
     When coroutine "I" iterates a generator of <n> items with concurrency <k>
     Then counter "iter_attempts_I" equals 1
      And counter "iter_cb_finished_I" plus counter "iter_cb_cancelled_I" plus counter "iter_cb_threw_I" equals counter "iter_cb_started_I"
      And no orphan coroutines

    Examples:
      | n  | k |
      | 4  | 2 |
      | 10 | 3 |
      | 12 | 6 |

  Scenario Outline: cancel iterate mid-flight from a sibling coroutine
    # Killer cancels the iterating coroutine while callbacks are parked in
    # suspend(). Cancel must reach every running callback; no orphans.
    Given a coroutine "I"
      And a coroutine "K"
     When coroutine "I" iterates <n> items with concurrency <k> via callback that suspends <m> times
      And coroutine "K" sleeps <delay> ms
      And coroutine "K" cancels coroutine "I"
     Then counter "iter_attempts_I" equals 1
      And counter "iter_done_I" plus counter "iter_cancelled_I" plus counter "iter_failed_I" equals 1
      And counter "iter_cb_finished_I" plus counter "iter_cb_cancelled_I" plus counter "iter_cb_threw_I" equals counter "iter_cb_started_I"
      And no orphan coroutines

    Examples:
      | n  | k | m  | delay |
      | 10 | 2 | 20 | 1     |
      | 12 | 4 | 30 | 5     |
      | 20 | 5 | 40 | 15    |

  Scenario: two iterates running in parallel share no state
    # Two coroutines each iterate over their own item set concurrently;
    # neither's bookkeeping leaks into the other.
    Given a coroutine "A"
      And a coroutine "B"
     When coroutine "A" iterates 8 items with concurrency 3 via callback that suspends 2 times
      And coroutine "B" iterates 6 items with concurrency 2 via callback that suspends 3 times
     Then counter "iter_attempts_A" equals 1
      And counter "iter_attempts_B" equals 1
      And counter "iter_cb_finished_A" plus counter "iter_cb_cancelled_A" plus counter "iter_cb_threw_A" equals counter "iter_cb_started_A"
      And counter "iter_cb_finished_B" plus counter "iter_cb_cancelled_B" plus counter "iter_cb_threw_B" equals counter "iter_cb_started_B"
      And no orphan coroutines
