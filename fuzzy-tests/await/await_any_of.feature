Feature: await_any_of_or_fail returns K results out of N triggers

  await_any_of_or_fail(K, triggers) suspends until K triggers have completed
  successfully (errors propagate when fewer than K can succeed). Under chaos
  scheduling we cannot predict *which* K complete first; we can only assert:

    await_anyof_attempts == 1
    await_anyof_succeeded + await_anyof_failed == 1
    when succeeded, received == K

  Scenario Outline: pick K out of N producers that all succeed
    Given a future "F1"
      And a future "F2"
      And a future "F3"
      And a future "F4"
      And a coroutine "P1"
      And a coroutine "P2"
      And a coroutine "P3"
      And a coroutine "P4"
      And a coroutine "A"
     When coroutine "P1" completes future "F1" with 1
      And coroutine "P2" completes future "F2" with 2
      And coroutine "P3" completes future "F3" with 3
      And coroutine "P4" completes future "F4" with 4
      And coroutine "A" awaits <k> out of futures "F1,F2,F3,F4"
     Then counter "await_anyof_attempts" equals 1
      And counter "await_anyof_succeeded" equals 1
      And counter "await_anyof_received" equals <k>
      And no orphan coroutines

    Examples:
      | k |
      | 1 |
      | 2 |
      | 3 |
      | 4 |

  Scenario Outline: K succeed, the rest fail — outcome depends on which fires first
    # await_any_of_or_fail is _or_fail: any single error propagates. Under
    # the chaos scheduler the failing producers may fire BEFORE K successes
    # land, so the call may either succeed (with K results) or throw. Both
    # outcomes are legal — the invariant is just exactly-one-of.
    Given a future "F1"
      And a future "F2"
      And a future "F3"
      And a future "F4"
      And a coroutine "P1"
      And a coroutine "P2"
      And a coroutine "PE1"
      And a coroutine "PE2"
      And a coroutine "A"
     When coroutine "P1" completes future "F1" with 1
      And coroutine "P2" completes future "F2" with 2
      And coroutine "PE1" fails future "F3" with "boom3"
      And coroutine "PE2" fails future "F4" with "boom4"
      And coroutine "A" awaits <k> out of futures "F1,F2,F3,F4"
     Then counter "await_anyof_attempts" equals 1
      And counter "await_anyof_succeeded" plus counter "await_anyof_failed" equals 1
      And no orphan coroutines

    Examples:
      | k |
      | 1 |
      | 2 |

  Scenario: too many failures — K successes are unreachable
    Given a future "F1"
      And a future "F2"
      And a future "F3"
      And a coroutine "P1"
      And a coroutine "PE1"
      And a coroutine "PE2"
      And a coroutine "A"
     When coroutine "P1" completes future "F1" with 1
      And coroutine "PE1" fails future "F2" with "e2"
      And coroutine "PE2" fails future "F3" with "e3"
      And coroutine "A" awaits 2 out of futures "F1,F2,F3"
     Then counter "await_anyof_attempts" equals 1
      And counter "await_anyof_failed" equals 1
      And counter "await_anyof_succeeded" equals 0
      And no orphan coroutines
