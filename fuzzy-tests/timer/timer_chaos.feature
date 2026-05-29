Feature: timer chaos — delay() and timeout() under cancel races + concurrent timers

  `Async\delay()` parks the coroutine on a libuv timer; `Async\timeout()`
  returns an Awaitable that fires after N ms, used as the cancellation arm
  of `await($work, timeout($m))`. The deterministic `tests/sleep/*` and
  `tests/common/timeout_*` pin happy paths; this feature fuzzes the race
  surface under the chaos scheduler: cancel mid-delay, many timers firing
  at once, and the timeout-vs-work race (whoever loses must release its
  libuv watcher — a leaked timer is the #082 bug class, caught by running
  the suite under ASAN leak detection).

  Invariants — true under any interleaving:
    - every coroutine ends in exactly one outcome bucket (the buckets sum
      to attempts);
    - no orphan coroutines;
    - no leaked libuv timer/watcher (ASAN detect_leaks).

  Scenario: a parked delay() is cancelled mid-wait
    # Waiter parks in a long delay(); a killer cancels it before it fires.
    # delay() must unwind via AsyncCancellation and release its timer.
    Given a coroutine "W"
      And a coroutine "K"
     When coroutine "W" runs a cancellable delay of 200 ms
      And coroutine "K" sleeps 20 ms
      And coroutine "K" cancels coroutine "W"
     Then counter "delay_ok_W" plus counter "delay_cancelled_W" plus counter "delay_failed_W" equals counter "delay_attempts_W"
      And counter "delay_cancelled_W" equals 1
      And coroutine "W" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario Outline: cancel-timing varied against a parked delay()
    Given a coroutine "W"
      And a coroutine "K"
     When coroutine "W" runs a cancellable delay of 150 ms
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "W"
     Then counter "delay_ok_W" plus counter "delay_cancelled_W" plus counter "delay_failed_W" equals counter "delay_attempts_W"
      And coroutine "W" is completed
      And coroutine "K" is completed
      And no orphan coroutines

    Examples:
      | ms  |
      | 0   |
      | 5   |
      | 50  |
      | 150 |

  Scenario: many concurrent delays all fire
    # Four coroutines park on timers of different durations; the timer heap
    # is drained under random scheduling. All must fire (none cancelled).
    Given a coroutine "A"
      And a coroutine "B"
      And a coroutine "C"
      And a coroutine "D"
     When coroutine "A" runs a cancellable delay of 10 ms
      And coroutine "B" runs a cancellable delay of 30 ms
      And coroutine "C" runs a cancellable delay of 20 ms
      And coroutine "D" runs a cancellable delay of 5 ms
     Then counter "delay_ok_A" equals 1
      And counter "delay_ok_B" equals 1
      And counter "delay_ok_C" equals 1
      And counter "delay_ok_D" equals 1
      And no orphan coroutines

  Scenario: timeout fires before the work completes
    # work (200 ms) is slower than the timeout (30 ms): await throws
    # OperationCanceledException and the slow job is cancelled.
    Given a coroutine "G"
     When coroutine "G" runs work of 200 ms guarded by timeout 30 ms
     Then counter "tmo_work_ok_G" plus counter "tmo_timed_out_G" plus counter "tmo_cancelled_G" plus counter "tmo_failed_G" equals counter "tmo_attempts_G"
      And counter "tmo_timed_out_G" equals 1
      And coroutine "G" is completed
      And no orphan coroutines

  Scenario: work completes before the timeout — losing timeout must not leak
    # work (10 ms) wins; the pending timeout (500 ms) must be released by
    # await without leaking its libuv watcher (run under ASAN leak detection).
    Given a coroutine "G"
     When coroutine "G" runs work of 10 ms guarded by timeout 500 ms
     Then counter "tmo_work_ok_G" plus counter "tmo_timed_out_G" plus counter "tmo_cancelled_G" plus counter "tmo_failed_G" equals counter "tmo_attempts_G"
      And counter "tmo_work_ok_G" equals 1
      And coroutine "G" is completed
      And no orphan coroutines

  Scenario: the awaiter of a timeout-guarded await is cancelled mid-flight
    # Both the work and the timeout are pending when a killer cancels the
    # guarded coroutine: both watchers must be released.
    Given a coroutine "G"
      And a coroutine "K"
     When coroutine "G" runs work of 300 ms guarded by timeout 500 ms
      And coroutine "K" sleeps 30 ms
      And coroutine "K" cancels coroutine "G"
     Then counter "tmo_work_ok_G" plus counter "tmo_timed_out_G" plus counter "tmo_cancelled_G" plus counter "tmo_failed_G" equals counter "tmo_attempts_G"
      And coroutine "G" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario: a disposed safe scope leaves its delaying child as a zombie (#132)
    # A safe scope is disposed while a child is parked in delay(); the child
    # is not cancelled but becomes a zombie that finishes on its own. Its
    # timer must not leak and the child must settle exactly once.
    Given a coroutine "C"
     When coroutine "C" disposes a safe scope with a child delaying 20 ms
     Then counter "ztimer_attempts_C" equals 1
      And counter "ztimer_child_finished_C" plus counter "ztimer_child_cancelled_C" equals 1
      And counter "ztimer_done_C" equals 1
      And no orphan coroutines
