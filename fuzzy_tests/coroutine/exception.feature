Feature: Exception in a coroutine body does not crash other coroutines

  When one coroutine throws, the runtime must:
    - terminate the throwing coroutine cleanly
    - leave other coroutines free to complete
    - not leak the unhandled exception out of await_all

  Invariants:
    throw_attempts == N (deterministic — number of "throws" steps)
    threw_X == 1 (per throwing coroutine)
    no orphan coroutines

  Scenario: one throwing coroutine, others complete normally
    Given a coroutine "Bad"
      And a coroutine "G1"
      And a coroutine "G2"
     When coroutine "Bad" throws
      And coroutine "G1" prints "g1"
      And coroutine "G2" prints "g2"
     Then counter "throw_attempts" equals 1
      And counter "threw_Bad" equals 1
      And counter "printed_total" equals 2
      And no orphan coroutines

  Scenario: throw race with sleep — runtime cleans up
    Given a coroutine "Bad"
      And a coroutine "Sleeper"
     When coroutine "Bad" throws
      And coroutine "Sleeper" sleeps 50 ms
      And coroutine "Sleeper" prints "woke"
     Then counter "throw_attempts" equals 1
      And counter "printed_total" equals 1
      And no orphan coroutines

  Scenario Outline: many throwing coroutines side-by-side
    Given a coroutine "B1"
      And a coroutine "B2"
      And a coroutine "B3"
      And a coroutine "Quiet"
     When coroutine "B1" throws
      And coroutine "B2" throws
      And coroutine "B3" throws
      And coroutine "Quiet" prints "<msg>"
     Then counter "throw_attempts" equals 3
      And counter "printed_total" equals 1
      And no orphan coroutines

    Examples:
      | msg   |
      | hello |
      | world |
