Feature: Scope setExceptionHandler catches child failures

  When a child coroutine throws and the scope has an installed exception
  handler, the handler must run for each escaped exception. The scope is
  not cancelled by the failure (handler absorbs it), and the scope must
  finish without orphan coroutines.

  Invariants in every interleaving:
    no orphan coroutines
    scope_exception_handled_S == number of throwing children
    handled exceptions do not propagate to scope_cancel state

  Scenario: single child throws — handler observes once
    Given a scope "S"
      And scope "S" has an exception handler
      And a coroutine "Bad" in scope "S"
      And a coroutine "Good" in scope "S"
     When coroutine "Good" prints "g"
      And coroutine "Bad" throws
     Then counter "scope_exception_handled_S" equals 1
      And counter "threw_Bad" equals 1
      And counter "printed_total" equals 1
      And no orphan coroutines

  Scenario: multiple children throw — handler fires for each
    Given a scope "S"
      And scope "S" has an exception handler
      And a coroutine "B1" in scope "S"
      And a coroutine "B2" in scope "S"
      And a coroutine "B3" in scope "S"
     When coroutine "B1" throws
      And coroutine "B2" throws
      And coroutine "B3" throws
     Then counter "scope_exception_handled_S" equals 3
      And counter "throw_attempts" equals 3
      And no orphan coroutines

  Scenario: handler keeps scope alive for siblings
    Given a scope "S"
      And scope "S" has an exception handler
      And a coroutine "Bad" in scope "S"
      And a coroutine "Sib" in scope "S"
     When coroutine "Bad" throws
      And coroutine "Sib" sleeps 5 ms
      And coroutine "Sib" prints "ok"
     Then counter "scope_exception_handled_S" equals 1
      And counter "printed_total" equals 1
      And no orphan coroutines
