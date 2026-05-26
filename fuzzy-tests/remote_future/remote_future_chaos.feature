Feature: RemoteFuture (cross-thread FutureState transfer) chaos

  Async\FutureState created on the main thread is transferred into a
  spawn_thread() worker via closure-capture. The worker is then the only
  side allowed to complete()/error() it; the main thread loses write
  access, and any further spawn_thread() that re-captures the state
  throws at transfer time. Awaiters on the corresponding Future see the
  worker's outcome — possibly racing the completion.

  Hand-written backstops: ext/async/tests/remote_future/{001,002,005,006,
  009,010,013}.phpt pin one deterministic shape each (clean transfer +
  complete, transfer + error, source-loses-control, multi-transfer
  rejection, multiple awaiters, thread crashes without complete, thread
  returns without complete). This feature crosses those into chaos by
  varying awaiter count, transfer policy, and worker outcome under the
  random scheduler.

  Liveness / safety invariants (must hold under ANY interleaving):
    rf_xfer_attempts_F == rf_xfer_ok_F + rf_xfer_failed_F
    rf_await_attempts_F == rf_await_ok_F + rf_await_failed_F + rf_await_cancelled_F
    no orphan coroutines

  Scenario Outline: N awaiters race the worker's complete()
    # Mirrors tests/remote_future/013 (multiple awaiters get the same
    # result) — every awaiter must either see ok or be cancelled / fail;
    # nobody hangs.
    Given a future "F"
      And a coroutine "S"
      And a coroutine "A1"
      And a coroutine "A2"
      And a coroutine "A3"
     When coroutine "S" runs a thread that completes remote future "F" with 42
      And coroutine "A1" awaits remote future "F"
      And coroutine "A2" awaits remote future "F"
      And coroutine "A3" awaits remote future "F"
      And coroutine "S" awaits the source thread of remote future "F"
     Then counter "rf_xfer_attempts_F" equals 1
      And counter "rf_xfer_ok_F" equals 1
      And counter "rf_await_attempts_F" equals 3
      And counter "rf_await_ok_F" plus counter "rf_await_failed_F" plus counter "rf_await_cancelled_F" equals 3
      And counter "rf_thread_completed_F" plus counter "rf_thread_threw_F" plus counter "rf_thread_silent_F" plus counter "rf_thread_other_F" equals 1
      And no orphan coroutines

    Examples:
      | dummy |
      | _     |

  Scenario: worker errors the future — every awaiter surfaces an exception
    # Mirrors tests/remote_future/002 — error() propagates to every
    # awaiter; the worker handle await also returns cleanly (the worker
    # itself did not throw).
    Given a future "F"
      And a coroutine "S"
      And a coroutine "A1"
      And a coroutine "A2"
     When coroutine "S" runs a thread that errors remote future "F" with "remote-boom"
      And coroutine "A1" awaits remote future "F"
      And coroutine "A2" awaits remote future "F"
      And coroutine "S" awaits the source thread of remote future "F"
     Then counter "rf_xfer_ok_F" equals 1
      And counter "rf_await_attempts_F" equals 2
      And counter "rf_await_ok_F" plus counter "rf_await_failed_F" plus counter "rf_await_cancelled_F" equals 2
      And counter "rf_await_ok_F" equals 0
      And no orphan coroutines

  Scenario: worker crashes before completing — RemoteException surfaces, future stays uncompleted
    # Mirrors tests/remote_future/009. The worker throws *before* calling
    # complete(): joining the thread surfaces RemoteException, the
    # FutureState is left uncompleted, and ignore() releases it.
    Given a future "F"
      And a coroutine "S"
      And a coroutine "J"
     When coroutine "S" runs a thread that crashes after transferring remote future "F"
      And coroutine "S" awaits the source thread of remote future "F"
      And coroutine "J" ignores remote future "F"
     Then counter "rf_xfer_ok_F" equals 1
      And counter "rf_thread_threw_F" equals 1
      And counter "rf_thread_completed_F" equals 0
      And counter "rf_ignore_F" equals 1
      And no orphan coroutines

  Scenario: second transfer of the same FutureState is rejected
    # Mirrors tests/remote_future/006. After the first spawn_thread takes
    # ownership, any further spawn_thread that captures the same state
    # must throw at transfer; the first transfer's completion still
    # propagates to a single awaiter cleanly.
    Given a future "F"
      And a coroutine "S"
      And a coroutine "D"
      And a coroutine "A"
     When coroutine "S" runs a thread that completes remote future "F" with 7
      And coroutine "D" attempts a second transfer of remote future "F"
      And coroutine "A" awaits remote future "F"
      And coroutine "S" awaits the source thread of remote future "F"
     Then counter "rf_xfer_ok_F" equals 1
      And counter "rf_double_xfer_attempts_F" equals 1
      And counter "rf_double_xfer_blocked_F" equals 1
      And counter "rf_double_xfer_allowed_F" equals 0
      And counter "rf_await_attempts_F" equals 1
      And counter "rf_await_ok_F" plus counter "rf_await_failed_F" plus counter "rf_await_cancelled_F" equals 1
      And no orphan coroutines

  Scenario: main-thread complete() after transfer is rejected
    # Mirrors tests/remote_future/005. After spawn_thread takes ownership
    # the main thread loses write access — complete() on the same state
    # throws, while the worker's complete() is delivered to the awaiter.
    Given a future "F"
      And a coroutine "S"
      And a coroutine "M"
      And a coroutine "A"
     When coroutine "S" runs a thread that completes remote future "F" with 11
      And coroutine "M" attempts main-thread completion of remote future "F" with 99
      And coroutine "A" awaits remote future "F"
      And coroutine "S" awaits the source thread of remote future "F"
     Then counter "rf_xfer_ok_F" equals 1
      And counter "rf_main_complete_attempts_F" equals 1
      And counter "rf_main_complete_blocked_F" plus counter "rf_main_complete_allowed_F" equals 1
      And counter "rf_await_attempts_F" equals 1
      And no orphan coroutines

  Scenario Outline: bulk fan-in — many parallel transfers, each awaited
    # Independent remote futures each transferred to a separate worker
    # then awaited; no cross-contamination between them.
    Given a future "F1"
      And a future "F2"
      And a future "F3"
      And a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "S3"
      And a coroutine "A1"
      And a coroutine "A2"
      And a coroutine "A3"
     When coroutine "S1" runs a thread that completes remote future "F1" with <v1>
      And coroutine "S1" awaits the source thread of remote future "F1"
      And coroutine "S2" runs a thread that completes remote future "F2" with <v2>
      And coroutine "S2" awaits the source thread of remote future "F2"
      And coroutine "S3" runs a thread that completes remote future "F3" with <v3>
      And coroutine "S3" awaits the source thread of remote future "F3"
      And coroutine "A1" awaits remote future "F1"
      And coroutine "A2" awaits remote future "F2"
      And coroutine "A3" awaits remote future "F3"
     Then counter "rf_xfer_ok_F1" equals 1
      And counter "rf_xfer_ok_F2" equals 1
      And counter "rf_xfer_ok_F3" equals 1
      And counter "rf_await_ok_F1" plus counter "rf_await_failed_F1" plus counter "rf_await_cancelled_F1" equals 1
      And counter "rf_await_ok_F2" plus counter "rf_await_failed_F2" plus counter "rf_await_cancelled_F2" equals 1
      And counter "rf_await_ok_F3" plus counter "rf_await_failed_F3" plus counter "rf_await_cancelled_F3" equals 1
      And no orphan coroutines

    Examples:
      | v1 | v2 | v3 |
      | 1  | 2  | 3  |
      | 10 | 20 | 30 |

  Scenario: cancel an awaiter mid-wait, others still see the result
    # Killer cancels one of three awaiters before the worker completes;
    # cancellation lands on the awaiter alone, the other two still see ok
    # (or are also cancelled by interleaving, but never hang).
    Given a future "F"
      And a coroutine "S"
      And a coroutine "K"
      And a coroutine "A1"
      And a coroutine "A2"
      And a coroutine "A3"
     When coroutine "S" sleeps 5 ms
      And coroutine "S" runs a thread that completes remote future "F" with 1
      And coroutine "A1" awaits remote future "F"
      And coroutine "A2" awaits remote future "F"
      And coroutine "A3" awaits remote future "F"
      And coroutine "K" cancels coroutine "A2"
      And coroutine "S" awaits the source thread of remote future "F"
     Then counter "rf_xfer_ok_F" equals 1
      And counter "cancel_attempts" equals 1
      And counter "rf_await_ok_F" plus counter "rf_await_failed_F" plus counter "rf_await_cancelled_F" equals counter "rf_await_attempts_F"
      And no orphan coroutines
