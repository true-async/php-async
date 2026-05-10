Feature: Coroutine finally() runs on every termination path

  Coroutine::finally() registers a callback that must fire exactly once for
  every successful registration, regardless of how the coroutine terminates
  (normal return, thrown exception, cancellation) and regardless of whether
  the registration happens before or after the coroutine has already
  finished. Under randomised scheduling the registrar may race the body —
  the callback still runs because the runtime replays finally on already-
  completed coroutines.

  Invariants for every interleaving:
    finally_registered_T == finally_register_attempts_T   (registration is synchronous)
    finally_called_T     == finally_registered_T          (every registered handler fires)

  Scenario: finally on a coroutine that returns normally
    Given a coroutine "T"
      And a coroutine "R"
     When coroutine "R" registers finally on coroutine "T"
      And coroutine "T" prints "done"
     Then counter "finally_register_attempts_T" equals 1
      And counter "finally_called_T" equals counter "finally_registered_T"
      And no orphan coroutines

  Scenario: finally on a coroutine that throws
    Given a scope "S"
      And scope "S" has an exception handler
      And a coroutine "T" in scope "S"
      And a coroutine "R"
     When coroutine "R" registers finally on coroutine "T"
      And coroutine "T" throws
     Then counter "finally_register_attempts_T" equals 1
      And counter "finally_called_T" equals counter "finally_registered_T"
      And no orphan coroutines

  Scenario: finally on a sleeping coroutine that gets cancelled
    Given a coroutine "T"
      And a coroutine "R"
      And a coroutine "C"
     When coroutine "R" registers finally on coroutine "T"
      And coroutine "T" sleeps 100 ms
      And coroutine "C" cancels coroutine "T"
     Then counter "finally_register_attempts_T" equals 1
      And counter "finally_called_T" equals counter "finally_registered_T"
      And no orphan coroutines

  Scenario: multiple finally handlers all fire
    Given a coroutine "T"
      And a coroutine "R1"
      And a coroutine "R2"
      And a coroutine "R3"
     When coroutine "R1" registers finally on coroutine "T"
      And coroutine "R2" registers finally on coroutine "T"
      And coroutine "R3" registers finally on coroutine "T"
      And coroutine "T" prints "done"
     Then counter "finally_register_attempts_T" equals 3
      And counter "finally_called_T" equals counter "finally_registered_T"
      And no orphan coroutines

  Scenario Outline: register finally racing the body
    Given a coroutine "T"
      And a coroutine "R"
     When coroutine "R" registers finally on coroutine "T"
      And coroutine "T" sleeps <ms> ms
     Then counter "finally_register_attempts_T" equals 1
      And counter "finally_called_T" equals counter "finally_registered_T"
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 5  |
      | 50 |

  Scenario: finally that throws still counts as called
    Given a scope "S"
      And scope "S" has an exception handler
      And a coroutine "T" in scope "S"
      And a coroutine "R"
     When coroutine "R" registers throwing finally on coroutine "T"
      And coroutine "T" prints "done"
     Then counter "finally_register_attempts_T" equals 1
      And counter "finally_called_T" equals counter "finally_registered_T"
      And no orphan coroutines
