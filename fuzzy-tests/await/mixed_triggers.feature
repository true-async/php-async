Feature: await_all over a mix of Coroutine and Future triggers

  await_all([Coroutine, Future, ...]) must treat heterogeneous awaitables
  symmetrically — the runtime must not deadlock when a Future is filled
  synchronously while a Coroutine is still queued (the #103 inline-fire
  asymmetry).

  Invariants:
    await_mixed_attempts == 1
    await_mixed_succeeded + await_mixed_failed == 1
    when succeeded, received == count(triggers)

  Scenario: future + coroutines, all succeed
    Given a future "F1"
      And a coroutine "P1"
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "A"
     When coroutine "P1" completes future "F1" with 1
      And coroutine "C1" prints "c1"
      And coroutine "C2" prints "c2"
      And coroutine "A" awaits all mixed triggers "F1,C1,C2"
     Then counter "await_mixed_attempts" equals 1
      And counter "await_mixed_succeeded" equals 1
      And counter "await_mixed_received" equals 3
      And no orphan coroutines

  Scenario Outline: vary which slot is the future, which are coroutines
    Given a future "F1"
      And a coroutine "P1"
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "A"
     When coroutine "P1" completes future "F1" with 7
      And coroutine "C1" prints "c1"
      And coroutine "C2" prints "c2"
      And coroutine "A" awaits all mixed triggers "<order>"
     Then counter "await_mixed_attempts" equals 1
      And counter "await_mixed_succeeded" equals 1
      And counter "await_mixed_received" equals 3
      And no orphan coroutines

    Examples:
      | order      |
      | F1,C1,C2   |
      | C1,F1,C2   |
      | C1,C2,F1   |

  Scenario: coroutine that throws — await_all returns it as an error slot
    # await_all (not _or_fail) does NOT throw on a single failure; it returns
    # [results, errors]. The success counter still fires because the call
    # returned normally.
    Given a future "F1"
      And a coroutine "P1"
      And a coroutine "C1"
      And a coroutine "A"
     When coroutine "P1" completes future "F1" with 1
      And coroutine "C1" throws
      And coroutine "A" awaits all mixed triggers "F1,C1"
     Then counter "await_mixed_attempts" equals 1
      And counter "await_mixed_succeeded" equals 1
      And counter "await_mixed_errors" equals 1
      And no orphan coroutines
