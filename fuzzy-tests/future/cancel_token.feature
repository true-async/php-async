Feature: Future::await() with cancellation token

  Future::await($cancellation) accepts a Completable that, when fulfilled,
  aborts the wait. Under chaos scheduling the producer of F and the
  producer of the cancellation Future race; whichever completes first
  decides the awaiter's outcome:

    - If F completes first → await returns its value (awaited_F).
    - If FC fires first    → await raises Async\AsyncCancellation
                             (await_cancelled_F).
    - If F errored first   → await re-raises that error (await_failed_F).

  In every interleaving exactly one of those three outcomes occurs per
  await call, so:

    awaited_F + await_cancelled_F + await_failed_F == await_attempts_F

  Scenario: cancellation fires first → await_cancelled
    Given a future "F"
      And a future "FC"
      And a coroutine "P"
      And a coroutine "PC"
      And a coroutine "A"
     When coroutine "P" sleeps 200 ms
      And coroutine "P" completes future "F" with 1
      And coroutine "PC" completes future "FC" with 0
      And coroutine "A" awaits future "F" with cancellation future "FC"
     Then counter "await_attempts_F" equals 1
      And counter "awaited_F" plus counter "await_cancelled_F" equals counter "await_attempts_F"
      And counter "await_failed_F" equals 0
      And no orphan coroutines

  Scenario: producer wins → awaited
    Given a future "F"
      And a future "FC"
      And a coroutine "P"
      And a coroutine "PC"
      And a coroutine "A"
     When coroutine "P" completes future "F" with 1
      And coroutine "PC" sleeps 200 ms
      And coroutine "PC" completes future "FC" with 0
      And coroutine "A" awaits future "F" with cancellation future "FC"
     Then counter "await_attempts_F" equals 1
      And counter "awaited_F" plus counter "await_cancelled_F" equals counter "await_attempts_F"
      And counter "await_failed_F" equals 0
      And no orphan coroutines

  Scenario: producer errored → await_failed
    Given a scope "S"
      And scope "S" has an exception handler
      And a future "F"
      And a future "FC"
      And a coroutine "P" in scope "S"
      And a coroutine "PC"
      And a coroutine "A"
     When coroutine "P" fails future "F" with "boom"
      And coroutine "PC" sleeps 200 ms
      And coroutine "PC" completes future "FC" with 0
      And coroutine "A" awaits future "F" with cancellation future "FC"
     Then counter "await_attempts_F" equals 1
      And counter "awaited_F" plus counter "await_cancelled_F" plus counter "await_failed_F" equals counter "await_attempts_F"
      And no orphan coroutines

  Scenario Outline: vary which side wins the race
    Given a future "F"
      And a future "FC"
      And a coroutine "P"
      And a coroutine "PC"
      And a coroutine "A"
     When coroutine "P" sleeps <p_ms> ms
      And coroutine "P" completes future "F" with 1
      And coroutine "PC" sleeps <c_ms> ms
      And coroutine "PC" completes future "FC" with 0
      And coroutine "A" awaits future "F" with cancellation future "FC"
     Then counter "await_attempts_F" equals 1
      And counter "awaited_F" plus counter "await_cancelled_F" equals counter "await_attempts_F"
      And counter "await_failed_F" equals 0
      And no orphan coroutines

    Examples:
      | p_ms | c_ms |
      | 0    | 0    |
      | 5    | 5    |
      | 10   | 50   |
      | 50   | 10   |
