Feature: await_all_or_fail returns every result when every future completes

  await_all_or_fail(triggers) suspends until every trigger has settled. If any
  one of them errors, the call throws and we never reach the success branch.
  Under chaos scheduling the producer order is random, so we cannot predict
  which producer fires first; we can only assert symmetric outcomes:

    await_all_attempts == 1
    await_all_succeeded + await_all_failed == 1
    when succeeded, received == N

  Scenario Outline: N producers, all complete; awaiter sees all results
    Given a future "F1"
      And a future "F2"
      And a future "F3"
      And a coroutine "P1"
      And a coroutine "P2"
      And a coroutine "P3"
      And a coroutine "A"
     When coroutine "P1" completes future "F1" with <v1>
      And coroutine "P2" completes future "F2" with <v2>
      And coroutine "P3" completes future "F3" with <v3>
      And coroutine "A" awaits all of futures "F1,F2,F3"
     Then counter "await_all_attempts" equals 1
      And counter "await_all_succeeded" plus counter "await_all_failed" equals 1
      And counter "await_all_received" equals 3
      And no orphan coroutines

    Examples:
      | v1 | v2 | v3 |
      | 1  | 2  | 3  |
      | 10 | 20 | 30 |

  Scenario: one of N fails — await_all_or_fail must throw
    Given a future "F1"
      And a future "F2"
      And a future "F3"
      And a coroutine "P1"
      And a coroutine "P2"
      And a coroutine "P3"
      And a coroutine "A"
     When coroutine "P1" completes future "F1" with 1
      And coroutine "P2" fails future "F2" with "boom"
      And coroutine "P3" completes future "F3" with 3
      And coroutine "A" awaits all of futures "F1,F2,F3"
     Then counter "await_all_attempts" equals 1
      And counter "await_all_failed" equals 1
      And counter "await_all_succeeded" equals 0
      And no orphan coroutines

  Scenario: completing the same future twice is a no-op for await_all_or_fail
    # The second complete() throws "already completed" — recorded in
    # complete_failed_F1 — but await_all sees F1 settled exactly once, so
    # the awaiter still receives N results.
    Given a future "F1"
      And a future "F2"
      And a coroutine "P1"
      And a coroutine "P2"
      And a coroutine "P3"
      And a coroutine "A"
     When coroutine "P1" completes future "F1" with 1
      And coroutine "P2" completes future "F1" with 11
      And coroutine "P3" completes future "F2" with 2
      And coroutine "A" awaits all of futures "F1,F2"
     Then counter "await_all_attempts" equals 1
      And counter "await_all_succeeded" plus counter "await_all_failed" equals 1
      And counter "completed_F1" plus counter "complete_failed_F1" equals 2
      And no orphan coroutines
