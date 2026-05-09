Feature: Scope ownership of coroutines, with and without cancel

  Coroutines spawned via Scope::spawn live and die with the scope. When the
  scope is cancelled mid-flight every child coroutine receives a cancellation
  and the scope itself transitions to a cancelled state.

  Invariants in every interleaving:
    no orphan coroutines  (await_all completed)
    if cancel was issued: scope is cancelled

  Scenario: scope finishes when all spawned coroutines complete
    Given a scope "S"
      And a coroutine "C1" in scope "S"
      And a coroutine "C2" in scope "S"
      And a coroutine "C3" in scope "S"
     When coroutine "C1" prints "c1"
      And coroutine "C2" prints "c2"
      And coroutine "C3" prints "c3"
     Then counter "printed_total" equals 3
      And scope "S" is finished
      And no orphan coroutines

  Scenario: cancel scope mid-flight
    Given a scope "S"
      And a coroutine "C1" in scope "S"
      And a coroutine "C2" in scope "S"
      And a coroutine "Canceller"
     When coroutine "C1" sleeps 100 ms
      And coroutine "C1" prints "c1"
      And coroutine "C2" sleeps 100 ms
      And coroutine "C2" prints "c2"
      And coroutine "Canceller" cancels scope "S"
     Then counter "scope_cancel_attempts" equals 1
      And scope "S" is cancelled
      And no orphan coroutines
