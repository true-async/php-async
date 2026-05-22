Feature: Coroutine getResult() / getException() reflect termination path

  After a coroutine terminates, getResult() and getException() must report
  the path the runtime actually took:

    normal return : getException() === null
    throw         : getException() instanceof Throwable, getResult() === null
    cancel        : getException() instanceof Async\AsyncCancellation,
                    getResult() === null

  Under randomised scheduling the body, the canceller and the throw-step
  may interleave in any order — the post-termination state is observable
  only after the coroutine is actually finished, so these invariants hold
  for every legal interleaving.

  Scenario: normal return — no exception
    Given a coroutine "T"
     When coroutine "T" prints "done"
     Then coroutine "T" has no exception
      And no orphan coroutines

  Scenario: throw — getException returns the thrown class
    Given a scope "S"
      And scope "S" has an exception handler
      And a coroutine "T" in scope "S"
     When coroutine "T" throws
     Then coroutine "T" exception is "RuntimeException"
      And coroutine "T" result is null
      And no orphan coroutines

  Scenario: cancellation — getException returns AsyncCancellation
    Given a coroutine "T"
      And a coroutine "C"
     When coroutine "T" sleeps 100 ms
      And coroutine "C" cancels coroutine "T"
     Then coroutine "T" exception is "Async\AsyncCancellation"
      And coroutine "T" result is null
      And no orphan coroutines

  Scenario Outline: cancel with varying body delay
    # A 0 ms body can run to completion before the cancel lands — a genuine
    # race, so assert the interleaving-safe union, not cancellation only.
    Given a coroutine "T"
      And a coroutine "C"
     When coroutine "T" sleeps <ms> ms
      And coroutine "C" cancels coroutine "T"
     Then coroutine "T" was cancelled or finished cleanly
      And coroutine "T" result is null
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 5  |
      | 50 |

  Scenario: multiple coroutines — each reports its own state
    Given a scope "S"
      And scope "S" has an exception handler
      And a coroutine "Ok"
      And a coroutine "Bad" in scope "S"
     When coroutine "Ok" prints "ok"
      And coroutine "Bad" throws
     Then coroutine "Ok" has no exception
      And coroutine "Bad" exception is "RuntimeException"
      And coroutine "Bad" result is null
      And no orphan coroutines
