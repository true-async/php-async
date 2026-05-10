Feature: Cancel a sleeping coroutine

  A target coroutine is in delay() when another coroutine cancels it. The
  scheduler must wake the target, deliver the cancellation, and clean up
  the timer without leaking resources.

  Invariants:
    cancel_attempts == N        (number of "cancels" steps, deterministic)
    no orphan coroutines        (await_all completed for everyone)

  Scenario: cancel one sleeper
    Given a coroutine "Sleeper"
      And a coroutine "Canceller"
     When coroutine "Sleeper" sleeps 200 ms
      And coroutine "Sleeper" prints "after sleep"
      And coroutine "Canceller" cancels coroutine "Sleeper"
     Then counter "cancel_attempts" equals 1
      And no orphan coroutines

  Scenario: cancel several sleepers
    Given a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "S3"
      And a coroutine "Canceller"
     When coroutine "S1" sleeps 100 ms
      And coroutine "S2" sleeps 100 ms
      And coroutine "S3" sleeps 100 ms
      And coroutine "Canceller" cancels coroutine "S1"
      And coroutine "Canceller" cancels coroutine "S2"
      And coroutine "Canceller" cancels coroutine "S3"
     Then counter "cancel_attempts" equals 3
      And counter "cancel_target_missing" equals 0
      And no orphan coroutines

  Scenario: cancel an already-finished coroutine is a no-op
    Given a coroutine "Quick"
      And a coroutine "Canceller"
     When coroutine "Quick" prints "done"
      And coroutine "Canceller" cancels coroutine "Quick"
     Then counter "cancel_attempts" equals 1
      And no orphan coroutines
