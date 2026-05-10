Feature: Coroutine state predicates under chaos scheduler

  Coroutine exposes is*() predicates for each lifecycle phase
  (started / running / suspended / completed / cancelled +
  cancellationRequested). At any given instant exactly one of the terminal
  states {completed, cancelled} or transient states {running, suspended,
  not-yet-started} is observable. After Context::run() every coroutine
  has terminated, so:

    - isStarted(), isCompleted() must be true
    - isRunning(), isSuspended() must be false
    - isCancelled() reflects the termination path

  An inspector coroutine that races a target's body samples its state at
  one instant; under random scheduling that sample can land on any
  reachable state. We assert only the union invariant
  (sum of state buckets == inspect_attempts) and the post-termination
  predicates.

  Scenario: post-termination predicates after a normal return
    Given a coroutine "T"
     When coroutine "T" prints "done"
     Then coroutine "T" is completed
      And no orphan coroutines

  Scenario: post-termination predicates after cancellation
    Given a coroutine "T"
      And a coroutine "C"
     When coroutine "T" sleeps 100 ms
      And coroutine "C" cancels coroutine "T"
     Then coroutine "T" is completed
      And coroutine "T" is cancelled
      And no orphan coroutines

  Scenario: inspector races a sleeping target
    Given a coroutine "T"
      And a coroutine "I"
     When coroutine "T" sleeps 50 ms
      And coroutine "I" inspects state of coroutine "T"
     Then counter "state_inspect_attempts_T" equals 1
      And coroutine "T" is completed
      And no orphan coroutines

  Scenario: inspector races a cancellation
    Given a coroutine "T"
      And a coroutine "I"
      And a coroutine "C"
     When coroutine "T" sleeps 50 ms
      And coroutine "I" inspects state of coroutine "T"
      And coroutine "C" cancels coroutine "T"
     Then counter "state_inspect_attempts_T" equals 1
      And coroutine "T" is completed
      And coroutine "T" is cancelled
      And no orphan coroutines

  Scenario: many inspectors on one target
    Given a coroutine "T"
      And a coroutine "I1"
      And a coroutine "I2"
      And a coroutine "I3"
      And a coroutine "I4"
     When coroutine "T" sleeps 50 ms
      And coroutine "I1" inspects state of coroutine "T"
      And coroutine "I2" inspects state of coroutine "T"
      And coroutine "I3" inspects state of coroutine "T"
      And coroutine "I4" inspects state of coroutine "T"
     Then counter "state_inspect_attempts_T" equals 4
      And coroutine "T" is completed
      And no orphan coroutines

  Scenario Outline: vary sleep length
    Given a coroutine "T"
      And a coroutine "I"
     When coroutine "T" sleeps <ms> ms
      And coroutine "I" inspects state of coroutine "T"
     Then counter "state_inspect_attempts_T" equals 1
      And coroutine "T" is completed
      And no orphan coroutines

    Examples:
      | ms  |
      | 0   |
      | 5   |
      | 50  |
      | 200 |
