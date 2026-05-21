Feature: await_any returns when any of the given futures completes

  await_any_or_fail(triggers) returns the first completed value, or throws
  if every trigger fails. Under chaos scheduling the producer order is
  random, so we cannot predict which future wins, but we can assert:

    await_any_attempts == 1
    await_any_succeeded + await_any_failed == 1
    at least one of the producers ran (sum of completed_F* >= 1) when
    success was reached

  Scenario: three producers, one awaiter, awaiter sees first
    Given a future "F1"
      And a future "F2"
      And a future "F3"
      And a coroutine "P1"
      And a coroutine "P2"
      And a coroutine "P3"
      And a coroutine "A"
     When coroutine "P1" completes future "F1" with 1
      And coroutine "P2" completes future "F2" with 2
      And coroutine "P3" completes future "F3" with 3
      And coroutine "A" awaits any of futures "F1,F2,F3"
     Then counter "await_any_attempts" equals 1
      And counter "await_any_succeeded" plus counter "await_any_failed" equals 1
      And no orphan coroutines

  # The F2-only completion case once deadlocked the runtime (await_any saw
  # no inline-fired waker for a later future); fixed under issue #103
  # (start_waker_events replay). The | F2 | 2 | row is reinstated below.
  Scenario Outline: vary which producers fire
    Given a future "F1"
      And a future "F2"
      And a coroutine "P"
      And a coroutine "A"
     When coroutine "P" completes future "<which>" with <val>
      And coroutine "A" awaits any of futures "F1,F2"
     Then counter "await_any_attempts" equals 1
      And counter "await_any_succeeded" plus counter "await_any_failed" equals 1
      And no orphan coroutines

    Examples:
      | which | val |
      | F1    | 1   |
      | F2    | 2   |
