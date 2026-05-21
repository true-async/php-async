Feature: SpawnStrategy hooks under chaos scheduler

  spawn_with(SpawnStrategy, task) drives three hooks on the strategy:

    provideScope()           — supplies the scope the coroutine joins
    beforeCoroutineEnqueue() — runs before the coroutine is enqueued
    afterCoroutineEnqueue()  — runs after it is enqueued

  Each hook fires exactly once per spawn_with call, regardless of how the
  chaos scheduler interleaves the surrounding coroutines.

  Scenario: spawn_with drives provideScope and the enqueue hooks
    Given a coroutine "C"
     When coroutine "C" spawns a strategy-driven coroutine labelled "S"
     Then counter "ss_attempts_S" equals 1
      And counter "ss_spawn_ok_S" equals 1
      And counter "ss_spawn_failed_S" equals 0
      And counter "ss_provide_scope_S" equals 1
      And counter "ss_before_enqueue_S" equals 1
      And counter "ss_after_enqueue_S" equals 1
      And counter "ss_body_ran_S" equals 1
      And no orphan coroutines

  Scenario: many strategy-driven spawns each fire their hooks once
    Given a coroutine "A"
      And a coroutine "B"
      And a coroutine "D"
     When coroutine "A" spawns a strategy-driven coroutine labelled "SA"
      And coroutine "B" spawns a strategy-driven coroutine labelled "SB"
      And coroutine "D" spawns a strategy-driven coroutine labelled "SD"
     Then counter "ss_provide_scope_SA" equals 1
      And counter "ss_before_enqueue_SA" equals 1
      And counter "ss_after_enqueue_SA" equals 1
      And counter "ss_provide_scope_SB" equals 1
      And counter "ss_before_enqueue_SB" equals 1
      And counter "ss_after_enqueue_SB" equals 1
      And counter "ss_provide_scope_SD" equals 1
      And counter "ss_before_enqueue_SD" equals 1
      And counter "ss_after_enqueue_SD" equals 1
      And no orphan coroutines
