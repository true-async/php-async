Feature: Future complete and await

  One coroutine completes a Future with a value, multiple coroutines await
  it. All awaiters see the same value regardless of when each one is
  scheduled relative to the producer.

  Invariants:
    complete_attempts_F == 1     (deterministic)
    awaited_F + await_failed_F == await_attempts_F
    awaited_F == N when producer ran successfully

  Scenario: one producer, one awaiter
    Given a future "F"
      And a coroutine "P"
      And a coroutine "A"
     When coroutine "P" completes future "F" with 42
      And coroutine "A" awaits future "F"
     Then counter "complete_attempts_F" equals 1
      And counter "completed_F" equals 1
      And counter "await_attempts_F" equals 1
      And counter "awaited_F" plus counter "await_failed_F" equals 1
      And no orphan coroutines

  Scenario: one producer, three awaiters all get the value
    Given a future "F"
      And a coroutine "P"
      And a coroutine "A1"
      And a coroutine "A2"
      And a coroutine "A3"
     When coroutine "P" completes future "F" with 7
      And coroutine "A1" awaits future "F"
      And coroutine "A2" awaits future "F"
      And coroutine "A3" awaits future "F"
     Then counter "complete_attempts_F" equals 1
      And counter "completed_F" equals 1
      And counter "await_attempts_F" equals 3
      And counter "awaited_F" plus counter "await_failed_F" equals 3
      And no orphan coroutines

  Scenario Outline: parameterised awaiter count
    Given a future "F"
      And a coroutine "P"
      And a coroutine "A1"
      And a coroutine "A2"
     When coroutine "P" completes future "F" with <val>
      And coroutine "A1" awaits future "F"
      And coroutine "A2" awaits future "F"
     Then counter "complete_attempts_F" equals 1
      And counter "await_attempts_F" equals 2
      And counter "awaited_F" plus counter "await_failed_F" equals 2

    Examples:
      | val |
      | 0   |
      | 1   |
      | 100 |
