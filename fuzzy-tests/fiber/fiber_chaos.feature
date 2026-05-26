Feature: Fiber + coroutine interop chaos

  Fiber lives in zend_fiber.c, but every Fiber call from inside an
  Async\spawn() coroutine routes back through ext/async's coroutine
  bookkeeping — start() / suspend() / resume() each cross the boundary.
  Issue #118 was a tracing-JIT crash on exactly this seam; the chaos
  scheduler is the right tool to keep that surface honest.

  Hand-written backstops: ext/async/tests/fiber/{001-029}.phpt pin one
  deterministic shape each (start/suspend/resume, concurrent fibers,
  exception propagation, getCoroutine + cancel, gc-during-start). This
  feature crosses them under random scheduling by varying fiber count,
  suspend depth, throw location, and overlaying an external killer.

  Liveness / safety invariants (must hold under ANY interleaving):
    fib_started_X >= fib_returned_X + fib_threw_X
        (every fiber either returns, throws, or is left in-progress)
    no orphan coroutines

  Scenario Outline: drive one fiber with N suspends to completion
    Given a coroutine "F"
     When coroutine "F" drives a fiber with <n> suspends
     Then counter "fib_started_F" is at most 1
      And counter "fib_returned_F" is at most 1
      And counter "fib_threw_F" is at most 1
      And counter "fib_cancelled_F" is at most 1
      And no orphan coroutines

    Examples:
      | n |
      | 1 |
      | 3 |
      | 5 |
      | 8 |

  Scenario Outline: M concurrent fibers each with N suspends
    Given a coroutine "F"
     When coroutine "F" drives <m> concurrent fibers each with <n> suspends
     Then counter "fib_started_F" is at most <m>
      And counter "fib_returned_F" is at most <m>
      And no orphan coroutines

    Examples:
      | m | n |
      | 2 | 2 |
      | 3 | 3 |
      | 4 | 4 |
      | 5 | 6 |

  Scenario Outline: fiber that throws on resume K — exception surfaces cleanly
    Given a coroutine "F"
     When coroutine "F" drives a fiber that throws on resume <k>
     Then counter "fib_started_F" is at most 1
      And counter "fib_returned_F" equals 0
      And no orphan coroutines

    Examples:
      | k |
      | 0 |
      | 1 |
      | 3 |
      | 5 |

  Scenario Outline: cancel the host coroutine while fibers are mid-flight
    # Issue #118 territory: the host coroutine is parked between
    # Fiber::resume() calls when a sibling cancels it. AsyncCancellation
    # must unwind the host without corrupting the fiber's stack.
    Given a coroutine "F"
      And a coroutine "K"
     When coroutine "F" drives <m> concurrent fibers each with <n> suspends
      And coroutine "K" sleeps <delay> ms
      And coroutine "K" cancels coroutine "F"
     Then counter "fib_started_F" is at most <m>
      And no orphan coroutines

    Examples:
      | m | n  | delay |
      | 2 | 5  | 1     |
      | 3 | 8  | 5     |
      | 4 | 10 | 15    |

  Scenario: two coroutines each drive their own fiber — independent state
    Given a coroutine "A"
      And a coroutine "B"
     When coroutine "A" drives a fiber with 5 suspends
      And coroutine "B" drives a fiber with 7 suspends
     Then counter "fib_started_A" is at most 1
      And counter "fib_started_B" is at most 1
      And counter "fib_returned_A" is at most 1
      And counter "fib_returned_B" is at most 1
      And counter "fib_threw_A" is at most 1
      And counter "fib_threw_B" is at most 1
      And counter "fib_cancelled_A" is at most 1
      And counter "fib_cancelled_B" is at most 1
      And no orphan coroutines

  Scenario: fiber drive nested inside protect — cancel is deferred past fiber teardown
    # protect() must keep the fiber alive even when a cancel is racing.
    Given a coroutine "P"
      And a coroutine "K"
     When coroutine "P" runs protect with 3 inner suspends
      And coroutine "P" drives a fiber with 3 suspends
      And coroutine "K" cancels coroutine "P"
     Then counter "protect_inner_started_P" equals counter "protect_inner_finished_P"
      And counter "fib_started_P" is at most 1
      And no orphan coroutines
