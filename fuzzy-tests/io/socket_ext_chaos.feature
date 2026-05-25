Feature: I/O chaos — ext/sockets (POSIX socket_*) under cancel

  `ext/async/tests/socket/` has only 4 deterministic happy-path tests
  (001–004, all IPv6). The POSIX socket API (`socket_connect`,
  `socket_recvfrom`, `socket_accept`) goes through `xp_socket.c` /
  `network_async.c` along its own path — distinct from the streams
  layer covered by io/`cancel_during_connect`, `udp_chaos`,
  `cancel_during_io`. This feature exercises the cancel surface on
  each verb to backstop the ext/sockets reactor integration.

  Invariants — true under any interleaving:
    - sock_<verb>_ok + cancelled + failed == attempts;
    - no orphan coroutines; the parked verb releases its watcher.

  Scenario: cancel socket_connect to a TCP blackhole
    # Same blackhole pattern as io/cancel_during_connect.feature but via
    # the ext/sockets API. socket_connect to a routable but unreachable
    # address parks in the connect-watcher; killer cancels it.
    Given a coroutine "C"
      And a coroutine "K"
     When coroutine "C" socket_connects to TCP blackhole "192.0.2.1:81"
      And coroutine "K" sleeps 30 ms
      And coroutine "K" cancels coroutine "C"
     Then counter "sock_connect_ok_C" plus counter "sock_connect_cancelled_C" plus counter "sock_connect_failed_C" equals counter "sock_connect_attempts_C"
      And coroutine "C" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario Outline: cancel-timing varied for socket_connect blackhole
    Given a coroutine "C"
      And a coroutine "K"
     When coroutine "C" socket_connects to TCP blackhole "192.0.2.1:81"
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "C"
     Then counter "sock_connect_ok_C" plus counter "sock_connect_cancelled_C" plus counter "sock_connect_failed_C" equals counter "sock_connect_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 5  |
      | 50 |

  Scenario: cancel socket_recvfrom on a fresh UDP socket
    # UDP socket bound to a loopback ephemeral port; no datagrams ever
    # arrive. socket_recvfrom parks in the reactor; killer cancels it.
    Given a coroutine "R"
      And a coroutine "K"
     When coroutine "R" socket_recvfroms on a fresh UDP socket
      And coroutine "K" sleeps 20 ms
      And coroutine "K" cancels coroutine "R"
     Then counter "sock_recv_ok_R" plus counter "sock_recv_cancelled_R" plus counter "sock_recv_failed_R" equals counter "sock_recv_attempts_R"
      And coroutine "R" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario: cancel socket_accept on a fresh TCP listener
    # ext/sockets accept loop bound to loopback ephemeral; no client ever
    # connects. socket_accept parks; killer cancels it.
    Given a coroutine "A"
      And a coroutine "K"
     When coroutine "A" socket_accepts on a fresh TCP listener
      And coroutine "K" sleeps 20 ms
      And coroutine "K" cancels coroutine "A"
     Then counter "sock_accept_ok_A" plus counter "sock_accept_cancelled_A" plus counter "sock_accept_failed_A" equals counter "sock_accept_attempts_A"
      And coroutine "A" is completed
      And coroutine "K" is completed
      And no orphan coroutines
