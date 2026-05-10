Feature: Future error propagation to awaiters

  When a Future is failed via FutureState::error(), every awaiter — whether
  it parked before the failure or arrived after — must see the exception.
  No awaiter may complete normally; no awaiter may hang.

  Invariants in every interleaving:
    error_attempts_F == 1
    errored_F == 1                       (single-shot)
    awaited_F == 0                       (no successful await)
    await_failed_F == await_attempts_F   (every awaiter saw the error)
    no orphan coroutines

  Scenario: one producer, one awaiter — error is observed
    Given a future "F"
      And a coroutine "P"
      And a coroutine "A"
     When coroutine "P" fails future "F" with "boom"
      And coroutine "A" awaits future "F"
     Then counter "error_attempts_F" equals 1
      And counter "errored_F" equals 1
      And counter "await_attempts_F" equals 1
      And counter "awaited_F" equals 0
      And counter "await_failed_F" equals 1
      And no orphan coroutines

  Scenario: one producer, three awaiters all see the error
    Given a future "F"
      And a coroutine "P"
      And a coroutine "A1"
      And a coroutine "A2"
      And a coroutine "A3"
     When coroutine "P" fails future "F" with "boom"
      And coroutine "A1" awaits future "F"
      And coroutine "A2" awaits future "F"
      And coroutine "A3" awaits future "F"
     Then counter "errored_F" equals 1
      And counter "await_attempts_F" equals 3
      And counter "awaited_F" equals 0
      And counter "await_failed_F" equals 3
      And no orphan coroutines

  Scenario: late awaiter (producer fails first, awaiter joins after)
    Given a future "F"
      And a coroutine "P"
      And a coroutine "A"
     When coroutine "P" fails future "F" with "late"
      And coroutine "A" sleeps 5 ms
      And coroutine "A" awaits future "F"
     Then counter "errored_F" equals 1
      And counter "await_failed_F" equals 1
      And no orphan coroutines

  Scenario Outline: parameterised awaiter count
    Given a future "F"
      And a coroutine "P"
      And a coroutine "A1"
      And a coroutine "A2"
     When coroutine "P" fails future "F" with "<msg>"
      And coroutine "A1" awaits future "F"
      And coroutine "A2" awaits future "F"
     Then counter "errored_F" equals 1
      And counter "await_attempts_F" equals 2
      And counter "await_failed_F" equals 2
      And counter "awaited_F" equals 0

    Examples:
      | msg     |
      | x       |
      | failure |
      | k       |
