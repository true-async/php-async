Feature: require_once concurrent include chaos

  N coroutines each call require_once on the same .inc file at the same
  time. The symbol-table guard inside ext/async must let exactly one
  declaration win and serve the cached symbol to every other coroutine —
  no redeclare-error, no missing-symbol, no crash regardless of
  scheduling.

  Hand-written backstops: ext/async/tests/include/{003,005,006,009,010}.
  This feature crosses them under random scheduling.

  Invariant: inc_ok_X + inc_cancelled_X + inc_failed_X + inc_missing_X
             == inc_attempts_X
  AND inc_failed_X == 0  (no redeclare-error or include error).

  Scenario Outline: N coroutines concurrently require_once the same file
    Given a coroutine "A"
      And a coroutine "B"
      And a coroutine "C"
      And a coroutine "D"
     When coroutine "A" require_once includes the chaos test inc file
      And coroutine "B" require_once includes the chaos test inc file
      And coroutine "C" require_once includes the chaos test inc file
      And coroutine "D" require_once includes the chaos test inc file
     Then counter "inc_failed_A" equals 0
      And counter "inc_failed_B" equals 0
      And counter "inc_failed_C" equals 0
      And counter "inc_failed_D" equals 0
      And counter "inc_missing_A" equals 0
      And counter "inc_missing_B" equals 0
      And counter "inc_missing_C" equals 0
      And counter "inc_missing_D" equals 0
      And no orphan coroutines

    Examples:
      | dummy |
      | _     |

  Scenario: cancel one of N requirers — others must still succeed
    Given a coroutine "A"
      And a coroutine "B"
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "A" require_once includes the chaos test inc file
      And coroutine "B" require_once includes the chaos test inc file
      And coroutine "C" require_once includes the chaos test inc file
      And coroutine "K" cancels coroutine "B"
     Then counter "inc_failed_A" equals 0
      And counter "inc_failed_B" equals 0
      And counter "inc_failed_C" equals 0
      And counter "inc_missing_A" equals 0
      And counter "inc_missing_C" equals 0
      And no orphan coroutines
