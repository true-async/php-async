Feature: await_first_success returns the first successfully completed future

  await_first_success(triggers) skips errors until one trigger settles with a
  value. If every trigger errors, the call throws (CompositeException).

  Invariants under chaos scheduling:
    await_first_attempts == 1
    await_first_succeeded + await_first_failed == 1
    when at least one producer completes successfully, the awaiter must succeed

  Scenario Outline: at least one producer completes; awaiter succeeds
    # Pre-failed producers must NOT trap the awaiter — the bug class behind #103.
    Given a future "F1"
      And a future "F2"
      And a future "F3"
      And a coroutine "P1"
      And a coroutine "P2"
      And a coroutine "P3"
      And a coroutine "A"
     When coroutine "P1" fails future "F1" with "boom1"
      And coroutine "P2" completes future "F2" with <val>
      And coroutine "P3" fails future "F3" with "boom3"
      And coroutine "A" awaits first success of futures "F1,F2,F3"
     Then counter "await_first_attempts" equals 1
      And counter "await_first_succeeded" equals 1
      And counter "await_first_failed" equals 0
      And no orphan coroutines

    Examples:
      | val |
      | 42  |
      | 7   |

  Scenario: every producer fails — await_first_success returns null + errors
    # await_first_success does NOT throw when no producer succeeds; it
    # returns [null, errors]. The Steps.php wrapper only catches; succeeded
    # is incremented because the call returned without throwing.
    Given a future "F1"
      And a future "F2"
      And a coroutine "P1"
      And a coroutine "P2"
      And a coroutine "A"
     When coroutine "P1" fails future "F1" with "boom1"
      And coroutine "P2" fails future "F2" with "boom2"
      And coroutine "A" awaits first success of futures "F1,F2"
     Then counter "await_first_attempts" equals 1
      And counter "await_first_succeeded" equals 1
      And counter "await_first_failed" equals 0
      And no orphan coroutines

  Scenario Outline: vary which slot is the only successful producer
    # Specifically targets the #103 family: a single producer fills a
    # later-positioned trigger. The awaiter must reach that slot.
    Given a future "F1"
      And a future "F2"
      And a future "F3"
      And a coroutine "P"
      And a coroutine "PE1"
      And a coroutine "PE2"
      And a coroutine "A"
     When coroutine "P" completes future "<which>" with 1
      And coroutine "PE1" fails future "<err1>" with "e1"
      And coroutine "PE2" fails future "<err2>" with "e2"
      And coroutine "A" awaits first success of futures "F1,F2,F3"
     Then counter "await_first_attempts" equals 1
      And counter "await_first_succeeded" equals 1
      And no orphan coroutines

    Examples:
      | which | err1 | err2 |
      | F1    | F2   | F3   |
      | F2    | F1   | F3   |
      | F3    | F1   | F2   |
