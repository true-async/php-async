Feature: I/O chaos — SSL accept-side under concurrent handshakes + cancel

  `ext/async/tests/stream/027-ssl_concurrent_accept.phpt` pins the
  deterministic "three SSL servers each accept one client" shape, the
  regression backstop for `network_async_accept_incoming()` event-loop
  conflicts. This feature crosses it into chaos:

    - several acceptors active in parallel, each on its own listener,
      under random scheduling;
    - one acceptor parked, killer cancels it mid-accept — must release
      the watcher without leaking;
    - many clients hit one acceptor simultaneously, exercising the
      handshake-queue and the per-connection crypto state.

  The existing `tls_connect.feature` (#138) covers the *client* side
  (cancel mid-handshake on the connect-watcher). This feature is the
  mirror on the *server* side (accept-watcher + server-side handshake).

  Scenario: three SSL servers each accept one client (chaos-driven 027)
    # Three independent ssl:// listeners; one client per server. Each
    # server handshake completes, sends "ok", closes. Mirror of the
    # deterministic 027 but under random scheduling.
    Given TLS server "S1" listening on "SRV1" accepting up to 1 clients
      And TLS server "S2" listening on "SRV2" accepting up to 1 clients
      And TLS server "S3" listening on "SRV3" accepting up to 1 clients
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
     When coroutine "C1" connects via TLS to server "S1" with timeout 5000 ms
      And coroutine "C2" connects via TLS to server "S2" with timeout 5000 ms
      And coroutine "C3" connects via TLS to server "S3" with timeout 5000 ms
     Then counter "tls_accept_ok_S1" equals 1
      And counter "tls_accept_ok_S2" equals 1
      And counter "tls_accept_ok_S3" equals 1
      And counter "io_tls_ok_C1" equals 1
      And counter "io_tls_ok_C2" equals 1
      And counter "io_tls_ok_C3" equals 1
      And coroutine "SRV1" is completed
      And coroutine "SRV2" is completed
      And coroutine "SRV3" is completed
      And coroutine "C1" is completed
      And coroutine "C2" is completed
      And coroutine "C3" is completed
      And no orphan coroutines

  Scenario: one server fans in four concurrent TLS clients
    # One ssl:// listener accepts up to 4 clients in its loop; four
    # client coroutines hit it concurrently. Stresses the accept queue
    # + per-connection server-side handshake state. Every client must
    # complete the handshake.
    Given TLS server "S" listening on "SRV" accepting up to 4 clients
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
      And a coroutine "C4"
     When coroutine "C1" connects via TLS to server "S" with timeout 5000 ms
      And coroutine "C2" connects via TLS to server "S" with timeout 5000 ms
      And coroutine "C3" connects via TLS to server "S" with timeout 5000 ms
      And coroutine "C4" connects via TLS to server "S" with timeout 5000 ms
     Then counter "tls_accept_ok_S" equals 4
      And counter "io_tls_ok_C1" equals 1
      And counter "io_tls_ok_C2" equals 1
      And counter "io_tls_ok_C3" equals 1
      And counter "io_tls_ok_C4" equals 1
      And coroutine "SRV" is completed
      And no orphan coroutines

  Scenario: cancel acceptor while it's parked on stream_socket_accept
    # Server is bound but no clients connect; the accept loop parks in
    # stream_socket_accept(). Killer cancels SRV — the accept-watcher
    # must release without UAF or leaked listener. tls_accept_attempts
    # may be 0 (cancel landed before the first accept) or N>=1; the
    # cancelled bucket carries it. Server's `accepting up to 5` cap
    # is high enough that the loop would never naturally exit before
    # the cancel.
    Given TLS server "S" listening on "SRV" accepting up to 5 clients
      And a coroutine "K"
     When coroutine "K" sleeps 20 ms
      And coroutine "K" cancels coroutine "SRV"
     Then counter "tls_accept_ok_S" plus counter "tls_accept_cancelled_S" plus counter "tls_accept_failed_S" equals counter "tls_accept_attempts_S"
      And coroutine "SRV" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario Outline: cancel-timing varied against a parked acceptor
    Given TLS server "S" listening on "SRV" accepting up to 5 clients
      And a coroutine "K"
     When coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "SRV"
     Then counter "tls_accept_ok_S" plus counter "tls_accept_cancelled_S" plus counter "tls_accept_failed_S" equals counter "tls_accept_attempts_S"
      And coroutine "SRV" is completed
      And coroutine "K" is completed
      And no orphan coroutines

    Examples:
      | ms  |
      | 0   |
      | 5   |
      | 50  |
      | 200 |
