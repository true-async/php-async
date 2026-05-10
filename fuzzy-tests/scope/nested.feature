Feature: Nested scopes — parent cancellation cascades to children

  Scope::inherit($parent) creates a child scope whose lifecycle is tied
  to the parent. Cancelling the parent must cancel the child and every
  coroutine in either scope.

  Invariants in every interleaving:
    no orphan coroutines
    parent cancel issued: both parent and child end cancelled

  Scenario: child completes naturally, parent finishes
    Given a scope "P"
      And a child scope "C" of "P"
      And a coroutine "Pc1" in scope "P"
      And a coroutine "Cc1" in scope "C"
     When coroutine "Pc1" prints "p1"
      And coroutine "Cc1" prints "c1"
     Then counter "printed_total" equals 2
      And no orphan coroutines

  Scenario: cancelling the parent cascades into the child
    Given a scope "P"
      And a child scope "C" of "P"
      And a coroutine "Pc1" in scope "P"
      And a coroutine "Cc1" in scope "C"
      And a coroutine "Canceller"
     When coroutine "Pc1" sleeps 100 ms
      And coroutine "Cc1" sleeps 100 ms
      And coroutine "Canceller" cancels scope "P"
     Then counter "scope_cancel_attempts" equals 1
      And scope "P" is cancelled
      And no orphan coroutines

  Scenario: cancelling only the child leaves the parent alive
    Given a scope "P"
      And a child scope "C" of "P"
      And a coroutine "Pc1" in scope "P"
      And a coroutine "Cc1" in scope "C"
      And a coroutine "Canceller"
     When coroutine "Pc1" prints "p1"
      And coroutine "Cc1" sleeps 100 ms
      And coroutine "Canceller" cancels scope "C"
     Then counter "scope_cancel_attempts" equals 1
      And counter "printed_total" equals 1
      And scope "C" is cancelled
      And no orphan coroutines
