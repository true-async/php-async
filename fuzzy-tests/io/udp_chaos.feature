Feature: I/O chaos — UDP recvfrom under cancel + concurrent senders

  Drives the reactor's `sock_async_poll(PHP_POLLREADABLE)` via
  `stream_socket_recvfrom()` on a bound UDP socket. Senders fire
  fixed payloads at varying delays; a killer may cancel the receiver
  mid-wait. The async poll watcher must be released without leaks on
  every interleaving and the receiver must terminate cleanly.

  UDP is connectionless and the recvfrom hook is shared with TCP
  (xp_socket's sock_recvfrom → sock_async_poll), so this is the
  first chaos coverage of the UDP recv path. Today's
  `ext/async/tests/` covers TCP/SSL but no async UDP.

  Scenario: receive a single payload
    Given a UDP endpoint "U"
      And a coroutine "R"
      And a coroutine "S"
     When coroutine "R" recvs from UDP endpoint "U"
      And coroutine "S" sleeps 20 ms
      And coroutine "S" sends "ping" to UDP endpoint "U"
     Then counter "udp_recv_ok_R" plus counter "udp_recv_cancelled_R" plus counter "udp_recv_failed_R" equals counter "udp_recv_attempts_R"
      And counter "udp_recv_ok_R" is at least 1
      And counter "udp_send_ok_S" is at least 1
      And coroutine "R" is completed
      And coroutine "S" is completed
      And no orphan coroutines

  Scenario: cancel the receiver mid-recv
    # No sender. The killer cancels R parked in recvfrom; the async
    # poll watcher must be released cleanly.
    Given a UDP endpoint "U"
      And a coroutine "R"
      And a coroutine "K"
     When coroutine "R" recvs from UDP endpoint "U"
      And coroutine "K" sleeps 15 ms
      And coroutine "K" cancels coroutine "R"
     Then counter "udp_recv_ok_R" plus counter "udp_recv_cancelled_R" plus counter "udp_recv_failed_R" equals counter "udp_recv_attempts_R"
      And counter "udp_recv_cancelled_R" is at least 1
      And coroutine "R" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario: two senders, one receiver — recv wakes on one of them
    # Two sends fire near-simultaneously. The first datagram to land
    # wakes recvfrom; the second is left queued in the socket buffer
    # (and dropped on socket close in teardown). Both sends are
    # fire-and-forget, so both must report udp_send_ok.
    Given a UDP endpoint "U"
      And a coroutine "R"
      And a coroutine "S1"
      And a coroutine "S2"
     When coroutine "R" recvs from UDP endpoint "U"
      And coroutine "S1" sleeps 20 ms
      And coroutine "S1" sends "one" to UDP endpoint "U"
      And coroutine "S2" sleeps 25 ms
      And coroutine "S2" sends "two" to UDP endpoint "U"
     Then counter "udp_recv_ok_R" plus counter "udp_recv_cancelled_R" plus counter "udp_recv_failed_R" equals counter "udp_recv_attempts_R"
      And counter "udp_recv_ok_R" is at least 1
      And counter "udp_send_ok_S1" is at least 1
      And counter "udp_send_ok_S2" is at least 1
      And coroutine "R" is completed
      And coroutine "S1" is completed
      And coroutine "S2" is completed
      And no orphan coroutines

  Scenario Outline: send delay vs cancel delay race
    # Sweep the timing of the send vs the cancel. The receiver must
    # always bucket: either recv_ok (send won) or recv_cancelled
    # (cancel won). The watcher releases cleanly in both outcomes.
    Given a UDP endpoint "U"
      And a coroutine "R"
      And a coroutine "S"
      And a coroutine "K"
     When coroutine "R" recvs from UDP endpoint "U"
      And coroutine "S" sleeps <sms> ms
      And coroutine "S" sends "z" to UDP endpoint "U"
      And coroutine "K" sleeps <kms> ms
      And coroutine "K" cancels coroutine "R"
     Then counter "udp_recv_ok_R" plus counter "udp_recv_cancelled_R" plus counter "udp_recv_failed_R" equals counter "udp_recv_attempts_R"
      And coroutine "R" is completed
      And coroutine "S" is completed
      And coroutine "K" is completed
      And no orphan coroutines

    Examples:
      | sms | kms |
      | 5   | 30  |
      | 30  | 5   |
      | 15  | 15  |
