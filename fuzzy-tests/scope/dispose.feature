Feature: Scope disposal — dispose() vs disposeSafely()

  dispose() force-cancels every child coroutine and tears down the scope.
  disposeSafely() requests a soft disposal that lets children finish if
  they're already on a normal completion path. In both cases the scope
  must end up not-finished-running and no orphan coroutines may remain.

  Invariants in every interleaving:
    no orphan coroutines
    scope_dispose_attempts (or scope_dispose_safely_attempts) == 1
    if a finally handler was attached: scope_finally_$name == 1

  Scenario: dispose mid-flight cancels children
    Given a scope "S"
      And a coroutine "C1" in scope "S"
      And a coroutine "C2" in scope "S"
      And a coroutine "Disposer"
     When coroutine "C1" sleeps 100 ms
      And coroutine "C1" prints "c1"
      And coroutine "C2" sleeps 100 ms
      And coroutine "C2" prints "c2"
      And coroutine "Disposer" disposes scope "S"
     Then counter "scope_dispose_attempts" equals 1
      And no orphan coroutines

  Scenario: disposeSafely after children finished is a no-op cancel
    Given a scope "S"
      And a coroutine "C1" in scope "S"
      And a coroutine "C2" in scope "S"
      And a coroutine "Disposer"
     When coroutine "C1" prints "c1"
      And coroutine "C2" prints "c2"
      And coroutine "Disposer" sleeps 5 ms
      And coroutine "Disposer" disposes safely scope "S"
     Then counter "printed_total" equals 2
      And counter "scope_dispose_safely_attempts" equals 1
      And no orphan coroutines

  Scenario: dispose triggers finally handler exactly once
    Given a scope "S"
      And scope "S" has a finally handler
      And a coroutine "C1" in scope "S"
      And a coroutine "Disposer"
     When coroutine "C1" sleeps 100 ms
      And coroutine "Disposer" disposes scope "S"
     Then counter "scope_dispose_attempts" equals 1
      And counter "scope_finally_S" equals 1
      And no orphan coroutines
