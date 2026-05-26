Feature: ob_start() per-coroutine isolation chaos

  Every coroutine sees its OWN output-buffer stack: when coroutine A
  pushes ob_start() and coroutine B writes via echo, B's output must
  NOT land in A's buffer (and vice-versa). ext/async swaps the OB
  stack on every coroutine switch; this feature verifies the swap is
  watertight under random scheduling.

  Hand-written backstops: ext/async/tests/output_buffer/{001-008}.phpt
  pin deterministic shapes. This feature crosses them under chaos by
  varying coroutine count + chunk count.

  Invariant: ob_clean_X (per-coroutine buffer matched the expected
  string exactly) plus ob_dirty_X (buffer contaminated by another
  coroutine — a real bug) plus ob_cancelled_X plus ob_failed_X equals
  ob_attempts_X. The only acceptable values are ob_clean_X == 1 or
  cancelled/failed; ob_dirty_X MUST stay 0.

  Scenario Outline: N coroutines write to their own buffer concurrently
    Given a coroutine "A"
      And a coroutine "B"
      And a coroutine "C"
     When coroutine "A" writes <chunks> chunks to its own output buffer
      And coroutine "B" writes <chunks> chunks to its own output buffer
      And coroutine "C" writes <chunks> chunks to its own output buffer
     Then counter "ob_dirty_A" equals 0
      And counter "ob_dirty_B" equals 0
      And counter "ob_dirty_C" equals 0
      And counter "ob_clean_A" plus counter "ob_cancelled_A" plus counter "ob_failed_A" equals counter "ob_attempts_A"
      And counter "ob_clean_B" plus counter "ob_cancelled_B" plus counter "ob_failed_B" equals counter "ob_attempts_B"
      And counter "ob_clean_C" plus counter "ob_cancelled_C" plus counter "ob_failed_C" equals counter "ob_attempts_C"
      And no orphan coroutines

    Examples:
      | chunks |
      | 2      |
      | 5      |
      | 10     |
      | 20     |

  Scenario Outline: many coroutines interleave ob writes
    Given a coroutine "A"
      And a coroutine "B"
      And a coroutine "C"
      And a coroutine "D"
      And a coroutine "E"
     When coroutine "A" writes <chunks> chunks to its own output buffer
      And coroutine "B" writes <chunks> chunks to its own output buffer
      And coroutine "C" writes <chunks> chunks to its own output buffer
      And coroutine "D" writes <chunks> chunks to its own output buffer
      And coroutine "E" writes <chunks> chunks to its own output buffer
     Then counter "ob_dirty_A" equals 0
      And counter "ob_dirty_B" equals 0
      And counter "ob_dirty_C" equals 0
      And counter "ob_dirty_D" equals 0
      And counter "ob_dirty_E" equals 0
      And no orphan coroutines

    Examples:
      | chunks |
      | 3      |
      | 8      |
      | 15     |
