Feature: I/O chaos — connect-watcher under AF_INET6

  Same race surface as cancel_during_connect / connect_with_timeout,
  but on an AF_INET6 socket. Exercises the IPv6 branch in xp_socket
  open() while sharing network_async_await_stream_socket() with the
  IPv4 path — a regression that only affects v6 socket creation but
  not the watcher itself would slip past the v4 features. The address
  is [::ffff:192.0.2.1]:81 (v4-mapped TEST-NET-1): forces AF_INET6
  while still routing through a real network stack so SYN goes out
  and the connect actually parks. A SKIPIF probe checks the host
  genuinely engages the connect-watcher (a host with no v6 egress
  fast-fails ENETUNREACH and is skipped — no false-green coverage).

  Scenario: cancel parked v6 connect
    Given a coroutine "C"
      And a coroutine "K"
     When coroutine "C" connects to IPv6 blackhole "[::ffff:192.0.2.1]:81" with timeout 5000 ms
      And coroutine "K" sleeps 10 ms
      And coroutine "K" cancels coroutine "C"
     Then counter "io_connect_ok_C" plus counter "io_connect_timeout_C" plus counter "io_connect_cancelled_C" plus counter "io_connect_failed_C" equals counter "io_connect_attempts_C"
      And counter "io_connect_cancelled_C" is at least 1
      And coroutine "C" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario: v6 connect timeout fires
    Given a coroutine "C"
     When coroutine "C" connects to IPv6 blackhole "[::ffff:192.0.2.1]:81" with timeout 50 ms
     Then counter "io_connect_ok_C" plus counter "io_connect_timeout_C" plus counter "io_connect_cancelled_C" plus counter "io_connect_failed_C" equals counter "io_connect_attempts_C"
      And counter "io_connect_timeout_C" is at least 1
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: v4 and v6 parked connects share the reactor
    # Both families park in the same connect-watcher pool. A bug
    # particular to one family's release path would leave the other
    # hung or vice versa.
    Given a coroutine "C4"
      And a coroutine "C6"
     When coroutine "C4" connects to TCP blackhole "192.0.2.1:81" with timeout 100 ms
      And coroutine "C6" connects to IPv6 blackhole "[::ffff:192.0.2.1]:81" with timeout 100 ms
     Then counter "io_connect_ok_C4" plus counter "io_connect_timeout_C4" plus counter "io_connect_cancelled_C4" plus counter "io_connect_failed_C4" equals counter "io_connect_attempts_C4"
      And counter "io_connect_ok_C6" plus counter "io_connect_timeout_C6" plus counter "io_connect_cancelled_C6" plus counter "io_connect_failed_C6" equals counter "io_connect_attempts_C6"
      And counter "io_connect_timeout_C4" is at least 1
      And counter "io_connect_timeout_C6" is at least 1
      And coroutine "C4" is completed
      And coroutine "C6" is completed
      And no orphan coroutines
