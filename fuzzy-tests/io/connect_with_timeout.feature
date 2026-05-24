Feature: I/O chaos — TCP connect timeout releases the connect-watcher

  stream_socket_client() with an explicit short timeout against an
  unrouted address (192.0.2.1:81 — RFC 5737 TEST-NET-1) must trigger
  the timeout path in the reactor's connect-watcher
  (network_async_await_stream_socket). The watcher must be released —
  no leaked libuv poll handle, no orphan coroutine — and the result
  must be a clean failure (false with a "timed out" errstr), not a
  hang.

  Outcome bucketing is identical to cancel_during_connect.feature; in
  these scenarios no killer runs and the tcp-blackhole SKIPIF probe has
  already proven the connect actually suspends past 50 ms on this host,
  so the dominant bucket MUST be io_connect_timeout — never
  io_connect_cancelled, and io_connect_failed only in exceptional kernel
  races. The "at least 1" assertion turns this into a real check that
  the connect-watcher's timeout path actually fired.

  Scenario: single connect, short timeout
    Given a coroutine "C"
     When coroutine "C" connects to TCP blackhole "192.0.2.1:81" with timeout 50 ms
     Then counter "io_connect_ok_C" plus counter "io_connect_timeout_C" plus counter "io_connect_cancelled_C" plus counter "io_connect_failed_C" equals counter "io_connect_attempts_C"
      And counter "io_connect_timeout_C" is at least 1
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario Outline: timeout duration varied
    # Sweeps the timeout value across orders of magnitude. The shorter
    # values stress the timer-firing-while-poll-pending race; the
    # longer values give the kernel time to surface a synchronous
    # network-unreachable. Every interleaving lands in exactly one
    # bucket.
    Given a coroutine "C"
     When coroutine "C" connects to TCP blackhole "192.0.2.1:81" with timeout <ms> ms
     Then counter "io_connect_ok_C" plus counter "io_connect_timeout_C" plus counter "io_connect_cancelled_C" plus counter "io_connect_failed_C" equals counter "io_connect_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

    Examples:
      | ms  |
      | 10  |
      | 50  |
      | 200 |

  Scenario: many concurrent connects, all time out
    # Stresses multiple simultaneous connect-watchers driven by the
    # same reactor. Each must release its own watcher independently.
    Given a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
      And a coroutine "C4"
     When coroutine "C1" connects to TCP blackhole "192.0.2.1:81" with timeout 50 ms
      And coroutine "C2" connects to TCP blackhole "192.0.2.1:81" with timeout 50 ms
      And coroutine "C3" connects to TCP blackhole "192.0.2.1:81" with timeout 50 ms
      And coroutine "C4" connects to TCP blackhole "192.0.2.1:81" with timeout 50 ms
     Then counter "io_connect_ok_C1" plus counter "io_connect_timeout_C1" plus counter "io_connect_cancelled_C1" plus counter "io_connect_failed_C1" equals counter "io_connect_attempts_C1"
      And counter "io_connect_ok_C2" plus counter "io_connect_timeout_C2" plus counter "io_connect_cancelled_C2" plus counter "io_connect_failed_C2" equals counter "io_connect_attempts_C2"
      And counter "io_connect_ok_C3" plus counter "io_connect_timeout_C3" plus counter "io_connect_cancelled_C3" plus counter "io_connect_failed_C3" equals counter "io_connect_attempts_C3"
      And counter "io_connect_ok_C4" plus counter "io_connect_timeout_C4" plus counter "io_connect_cancelled_C4" plus counter "io_connect_failed_C4" equals counter "io_connect_attempts_C4"
      And counter "io_connect_timeout_C1" is at least 1
      And counter "io_connect_timeout_C2" is at least 1
      And counter "io_connect_timeout_C3" is at least 1
      And counter "io_connect_timeout_C4" is at least 1
      And coroutine "C1" is completed
      And coroutine "C2" is completed
      And coroutine "C3" is completed
      And coroutine "C4" is completed
      And no orphan coroutines
