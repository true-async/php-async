Feature: I/O chaos — cancelling a coroutine parked in TCP connect

  A coroutine doing stream_socket_client() against an unrouted address
  (192.0.2.1:81 — RFC 5737 TEST-NET-1) suspends in the reactor's
  connect-watcher (network_async_await_stream_socket via xp_socket open).
  A second coroutine cancels it mid-wait. The reactor must release the
  connect poll request without UAF and without leaking the watcher; the
  cancelled coroutine must terminate cleanly with no orphans.

  Outcomes are bucketed via the io_connect_* family. Under random
  scheduling the cancel can land before the connect starts, exactly
  while it is parked in poll, or after the kernel synchronously
  returned a network-unreachable error — every interleaving must hit
  exactly one outcome. The connect timeout is set far above any test
  ChaosNet delay so the timeout path stays out of these scenarios (5 s
  vs sub-100 ms chaos windows); the separate connect_with_timeout.feature
  covers that path.

  Scenario: a parked connect is cancelled mid-wait
    # The cancel fires before the 5s timeout could possibly elapse, so the
    # cancellation path MUST be the actual outcome — turning the sum-only
    # invariant into a "this code path was really exercised" check.
    Given a coroutine "C"
      And a coroutine "K"
     When coroutine "C" connects to TCP blackhole "192.0.2.1:81" with timeout 5000 ms
      And coroutine "K" sleeps 10 ms
      And coroutine "K" cancels coroutine "C"
     Then counter "io_connect_ok_C" plus counter "io_connect_timeout_C" plus counter "io_connect_cancelled_C" plus counter "io_connect_failed_C" equals counter "io_connect_attempts_C"
      And counter "io_connect_cancelled_C" is at least 1
      And coroutine "C" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario Outline: cancel timing varied against a parked connect
    # The kill delay sweeps the chaos window: 0 ms races the connect's
    # own scheduling, larger values let the connect-watcher park first.
    # Any interleaving must land in exactly one outcome bucket.
    Given a coroutine "C"
      And a coroutine "K"
     When coroutine "C" connects to TCP blackhole "192.0.2.1:81" with timeout 5000 ms
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "C"
     Then counter "io_connect_ok_C" plus counter "io_connect_timeout_C" plus counter "io_connect_cancelled_C" plus counter "io_connect_failed_C" equals counter "io_connect_attempts_C"
      And counter "io_connect_cancelled_C" is at least 1
      And coroutine "C" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 5  |
      | 25 |
      | 75 |

  Scenario: two parked connects, cancel one
    # Two distinct connect requests share the reactor. Cancelling one
    # must not perturb the watcher of the other. The survivor either
    # times out far later (won't happen — timeout is 5s) or completes
    # in some other way; the test just asserts both terminate.
    Given a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "K"
     When coroutine "C1" connects to TCP blackhole "192.0.2.1:81" with timeout 5000 ms
      And coroutine "C2" connects to TCP blackhole "192.0.2.1:81" with timeout 200 ms
      And coroutine "K" sleeps 15 ms
      And coroutine "K" cancels coroutine "C1"
     Then counter "io_connect_ok_C1" plus counter "io_connect_timeout_C1" plus counter "io_connect_cancelled_C1" plus counter "io_connect_failed_C1" equals counter "io_connect_attempts_C1"
      And counter "io_connect_ok_C2" plus counter "io_connect_timeout_C2" plus counter "io_connect_cancelled_C2" plus counter "io_connect_failed_C2" equals counter "io_connect_attempts_C2"
      And counter "io_connect_cancelled_C1" is at least 1
      And coroutine "C1" is completed
      And coroutine "C2" is completed
      And no orphan coroutines
