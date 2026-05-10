Feature: every combinator accepts a cancellation Awaitable

  Each await_* combinator takes an optional cancellation Awaitable. When the
  cancellation completes before any trigger does, the awaiter must wake with
  a CancellationException — and must NOT leave any orphan coroutine behind.

  Invariants:
    await_any_attempts == 1
    await_any_succeeded + await_any_failed == 1
    no orphan coroutines

  Scenario: cancellation fires before any producer; awaiter wakes via cancel
    Given a future "F1"
      And a future "F2"
      And a future "FC"
      And a coroutine "PC"
      And a coroutine "A"
     When coroutine "PC" completes future "FC" with 0
      And coroutine "A" awaits any of futures "F1,F2" with cancellation future "FC"
     Then counter "await_any_attempts" equals 1
      And counter "await_any_failed" equals 1
      And no orphan coroutines

  Scenario: producer wins the race against cancellation
    Given a future "F1"
      And a future "F2"
      And a future "FC"
      And a coroutine "P1"
      And a coroutine "PC"
      And a coroutine "A"
     When coroutine "P1" completes future "F1" with 1
      And coroutine "PC" completes future "FC" with 0
      And coroutine "A" awaits any of futures "F1,F2" with cancellation future "FC"
     Then counter "await_any_attempts" equals 1
      And counter "await_any_succeeded" plus counter "await_any_failed" equals 1
      And no orphan coroutines

  Scenario Outline: vary which producer races cancellation
    Given a future "F1"
      And a future "F2"
      And a future "FC"
      And a coroutine "P"
      And a coroutine "PC"
      And a coroutine "A"
     When coroutine "P" completes future "<which>" with <val>
      And coroutine "PC" completes future "FC" with 0
      And coroutine "A" awaits any of futures "F1,F2" with cancellation future "FC"
     Then counter "await_any_attempts" equals 1
      And counter "await_any_succeeded" plus counter "await_any_failed" equals 1
      And no orphan coroutines

    Examples:
      | which | val |
      | F1    | 1   |
      | F2    | 2   |
