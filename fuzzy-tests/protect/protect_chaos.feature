Feature: Async\protect() cancel-deferral chaos

  Async\protect(closure) is the primitive callers rely on to keep a
  critical cleanup region from being torn apart by a racing cancel:
  AsyncCancellation must not land on any suspend() executed inside the
  closure, regardless of how many times the coroutine is cancelled while
  inside it. The instant protect() returns, any pending cancel becomes
  observable on the next suspend.

  Hand-written backstops: ext/async/tests/protect/{001-010}.phpt pin
  one deterministic shape each. This feature crosses them into chaos by
  varying inner suspend count, killer-cancel timing, and the post-protect
  follow-up step under random scheduling.

  Liveness / safety invariants (must hold under ANY interleaving):
    protect_inner_started_X == protect_inner_finished_X
        (a started protect closure ALWAYS finishes — never cancelled)
    protect_entered_X       == protect_exited_X + protect_caught_cancel_X
                                                + protect_threw_X
    no orphan coroutines

  Scenario Outline: cancel during protect — inner suspends must NOT cancel
    # Spec: every suspend() inside the protect closure runs to completion
    # even when a killer cancels the host coroutine mid-flight. The cancel
    # may still surface on a later suspend AFTER protect() returns — that
    # is correct, but inner_started must equal inner_finished.
    Given a coroutine "P"
      And a coroutine "K"
     When coroutine "P" runs protect with <n> inner suspends
      And coroutine "K" sleeps <delay> ms
      And coroutine "K" cancels coroutine "P"
     Then counter "protect_inner_started_P" equals counter "protect_inner_finished_P"
      And counter "protect_entered_P" is at most 1
      And counter "protect_exited_P" plus counter "protect_caught_cancel_P" plus counter "protect_threw_P" equals counter "protect_entered_P"
      And no orphan coroutines

    Examples:
      | n  | delay |
      | 1  | 0     |
      | 3  | 1     |
      | 5  | 5     |
      | 10 | 10    |
      | 20 | 25    |

  Scenario Outline: deferred cancel lands on post-protect suspend
    # After protect() exits, a previously-issued cancel must become
    # observable on the very next yield point. Either the post-protect
    # suspend catches AsyncCancellation, or — when the cancel never fired
    # before protect returned — the trailing suspend runs cleanly.
    # Either way: inner_started == inner_finished (the cancel never
    # leaked into the protected region).
    Given a coroutine "P"
      And a coroutine "K"
     When coroutine "P" runs protect with <n> inner suspends then suspends once more
      And coroutine "K" sleeps <delay> ms
      And coroutine "K" cancels coroutine "P"
     Then counter "protect_inner_started_P" equals counter "protect_inner_finished_P"
      And counter "protect_entered_P" is at most 1
      And counter "protect_exited_P" plus counter "protect_caught_cancel_P" plus counter "protect_threw_P" equals counter "protect_entered_P"
      And counter "protect_after_P" plus counter "protect_post_cancelled_P" equals counter "protect_exited_P"
      And no orphan coroutines

    Examples:
      | n  | delay |
      | 3  | 1     |
      | 5  | 5     |
      | 8  | 15    |
      | 12 | 30    |

  Scenario Outline: nested protect — outer cancel suppressed across both layers
    # protect() must compose: nesting another protect() inside the inner
    # closure does not weaken the outer guarantee, and a cancel cannot
    # squeeze through the seams between the two frames.
    Given a coroutine "P"
      And a coroutine "K"
     When coroutine "P" runs nested protect with <n> inner suspends
      And coroutine "K" sleeps <delay> ms
      And coroutine "K" cancels coroutine "P"
     Then counter "protect_inner_started_P" equals counter "protect_inner_finished_P"
      And counter "protect_inner_started_P" equals counter "protect_nested_inner_P"
      And counter "protect_entered_P" is at most 1
      And no orphan coroutines

    Examples:
      | n | delay |
      | 2 | 1     |
      | 5 | 5     |
      | 8 | 15    |

  Scenario Outline: user exception in protect propagates regardless of cancel
    # An exception thrown by the user closure must surface to the caller's
    # try/catch — protect must not swallow it or replace it with a cancel
    # even when a cancel has been queued.
    Given a coroutine "P"
      And a coroutine "K"
     When coroutine "P" runs protect that throws
      And coroutine "K" sleeps <delay> ms
      And coroutine "K" cancels coroutine "P"
     Then counter "protect_entered_P" is at most 1
      And counter "protect_threw_P" plus counter "protect_caught_cancel_P" plus counter "protect_exited_P" equals counter "protect_entered_P"
      And counter "protect_inner_started_P" equals counter "protect_entered_P"
      And no orphan coroutines

    Examples:
      | delay |
      | 0     |
      | 5     |
      | 20    |

  Scenario: many cancels during a long protect — none leak inside
    # Killer hammers cancel() three times in a row. All three must be
    # absorbed; the protected region runs to completion.
    Given a coroutine "P"
      And a coroutine "K"
     When coroutine "P" runs protect with 10 inner suspends
      And coroutine "K" cancels coroutine "P"
      And coroutine "K" cancels coroutine "P"
      And coroutine "K" cancels coroutine "P"
     Then counter "protect_inner_started_P" equals counter "protect_inner_finished_P"
      And counter "protect_inner_started_P" is at most 1
      And no orphan coroutines

  Scenario: two coroutines run protect concurrently — independent state
    Given a coroutine "A"
      And a coroutine "B"
      And a coroutine "K"
     When coroutine "A" runs protect with 5 inner suspends
      And coroutine "B" runs protect with 7 inner suspends
      And coroutine "K" cancels coroutine "A"
      And coroutine "K" cancels coroutine "B"
     Then counter "protect_inner_started_A" equals counter "protect_inner_finished_A"
      And counter "protect_inner_started_B" equals counter "protect_inner_finished_B"
      And no orphan coroutines
