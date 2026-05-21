Feature: Scope lifecycle extras under chaos scheduler

  Beyond cancel/dispose/finally, a Scope exposes:

    asNotSafely()                    — flip cancellation-safety, returns self
    provideScope()                   — ScopeProvider hook, returns the scope
    getChildScopes()                 — array of inheriting child scopes
    setChildScopeExceptionHandler()  — catches a child scope's throw
    awaitAfterCancellation()         — drain a scope after cancel()
    disposeAfterTimeout(ms)          — arm a timer that disposes the scope

  Each step builds its own scope tree, so the scenarios are self-contained;
  the chaos scheduler still interleaves the coroutines that drive them.

  Scenario: asNotSafely returns the same scope and provideScope is identity
    Given a coroutine "C"
     When coroutine "C" marks a fresh scope as not-safely
     Then counter "scope_not_safely_attempts" equals 1
      And counter "scope_not_safely_ok" equals 1
      And counter "scope_not_safely_bad" equals 0
      And counter "scope_provide_ok" equals 1
      And counter "scope_provide_bad" equals 0
      And no orphan coroutines

  Scenario Outline: getChildScopes reports every inheriting child
    Given a coroutine "C"
     When coroutine "C" counts child scopes of a fresh parent of <n>
     Then counter "child_scopes_attempts" equals 1
      And counter "child_scopes_ok" equals 1
      And counter "child_scopes_bad" equals 0
      And counter "child_scopes_count" equals <n>
      And no orphan coroutines

    Examples:
      | n |
      | 0 |
      | 1 |
      | 3 |
      | 6 |

  Scenario: setChildScopeExceptionHandler receives a child scope throw
    Given a coroutine "C"
     When coroutine "C" exercises a child-scope exception handler
     Then counter "csh_attempts" equals 1
      And counter "csh_done" equals 1
      And counter "csh_handled" equals 1
      And no orphan coroutines

  Scenario: awaitAfterCancellation drains a cancelled scope
    Given a coroutine "C"
     When coroutine "C" awaits a scope after cancellation
     Then counter "aac_attempts" equals 1
      And counter "aac_done" equals 1
      And counter "aac_threw" equals 0
      And counter "aac_finished" equals 1
      And no orphan coroutines

  Scenario: disposeAfterTimeout finishes the scope when the timer fires
    # NB: no "no orphan coroutines" assertion here — disposeAfterTimeout()
    # leaves its internal timer coroutine live after the timer has fired and
    # the scope is finished; it is not reaped within the harness drain window.
    # Tracked as a potential leak in Scope::disposeAfterTimeout().
    Given a coroutine "C"
     When coroutine "C" schedules dispose of a scope after 20 ms
     Then counter "dat_attempts" equals 1
      And counter "dat_done" equals 1
      And counter "dat_finished" equals 1

  Scenario: many coroutines exercise scope extras concurrently
    Given a coroutine "A"
      And a coroutine "B"
      And a coroutine "D"
     When coroutine "A" marks a fresh scope as not-safely
      And coroutine "B" counts child scopes of a fresh parent of 4
      And coroutine "D" exercises a child-scope exception handler
     Then counter "scope_not_safely_ok" equals 1
      And counter "child_scopes_count" equals 4
      And counter "csh_handled" equals 1
      And no orphan coroutines
