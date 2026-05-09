Feature: Scope finally handler runs exactly once on every termination path

  finally() registers a callback that runs when the scope terminates,
  regardless of whether termination was natural completion, cancellation,
  or disposal. The handler must fire exactly once per scope.

  Invariants in every interleaving:
    scope_finally_S == 1
    no orphan coroutines

  Scenario: finally fires on natural completion
    Given a scope "S"
      And scope "S" has a finally handler
      And a coroutine "C1" in scope "S"
      And a coroutine "C2" in scope "S"
     When coroutine "C1" prints "c1"
      And coroutine "C2" prints "c2"
     Then counter "printed_total" equals 2
      And counter "scope_finally_S" equals 1
      And scope "S" is finished
      And no orphan coroutines

  Scenario: finally fires on cancel mid-flight
    Given a scope "S"
      And scope "S" has a finally handler
      And a coroutine "C1" in scope "S"
      And a coroutine "Canceller"
     When coroutine "C1" sleeps 100 ms
      And coroutine "C1" prints "c1"
      And coroutine "Canceller" cancels scope "S"
     Then counter "scope_finally_S" equals 1
      And scope "S" is cancelled
      And no orphan coroutines

  Scenario: finally still fires when no children were spawned
    Given a scope "S"
      And scope "S" has a finally handler
      And a coroutine "Driver"
     When coroutine "Driver" prints "d"
     Then counter "scope_finally_S" equals 1
      And no orphan coroutines
