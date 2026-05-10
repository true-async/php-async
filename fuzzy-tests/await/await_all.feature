Feature: await_all completes when every spawned coroutine finishes

  await_all is invoked by the harness after planned actions are queued.
  Every planned coroutine must reach the end of its action list — under
  any scheduler interleaving the printed_total counter will equal the
  number of "prints" steps recorded.

  Scenario Outline: await_all eventually drains every coroutine
    Given a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
      And a coroutine "C4"
      And a coroutine "C5"
     When coroutine "C1" prints "p1"
      And coroutine "C2" prints "p2"
      And coroutine "C3" prints "p3"
      And coroutine "C4" prints "p4"
      And coroutine "C5" prints "p5"
     Then counter "printed_total" equals 5
      And no orphan coroutines

    Examples:
      | unused |
      | a      |

  Scenario: mix prints with suspends
    Given a coroutine "A"
      And a coroutine "B"
      And a coroutine "C"
     When coroutine "A" prints "a1"
      And coroutine "A" suspends
      And coroutine "A" prints "a2"
      And coroutine "B" prints "b1"
      And coroutine "B" suspends
      And coroutine "B" prints "b2"
      And coroutine "C" suspends
      And coroutine "C" prints "c1"
     Then counter "printed_total" equals 5
      And no orphan coroutines
