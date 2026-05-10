Feature: Many independent coroutines all complete

  Scenario: 5 independent coroutines, no shared state
    Given a coroutine "C0"
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
      And a coroutine "C4"
     When coroutine "C0" prints "c0"
      And coroutine "C1" prints "c1"
      And coroutine "C2" prints "c2"
      And coroutine "C3" prints "c3"
      And coroutine "C4" prints "c4"
     Then counter "printed_total" equals 5
      And no orphan coroutines
