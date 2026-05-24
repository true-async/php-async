Feature: I/O chaos — TLS handshake under cancel + concurrency

  stream_socket_client('ssl://…') drives the full TCP connect AND TLS
  handshake — both phases yield to the reactor. The TCP part shares
  network_async_await_stream_socket() with the plain-TCP features;
  the crypto retry loop adds a second poll surface on top. Chaos:
  cancel a client mid-handshake (cancellation must release the connect
  watcher AND any in-flight crypto poll state without leaks); many
  concurrent clients against one accept loop; server torn down while
  a client is mid-handshake.

  In-process echo server: binds an ssl:// listener with the project's
  test cert (ext/async/tests/stream/ssl_test_cert.pem) and serves N
  accepts, each handshake + 2-byte payload + close.

  Scenario: single client handshakes successfully
    # Smoke test. Confirms the harness's TLS server + client glue
    # actually completes a handshake and that io_tls_ok fires.
    Given a coroutine "SRV"
      And a coroutine "C"
      And TLS server "S" listening on "SRV" accepting up to 1 clients
     When coroutine "C" connects via TLS to server "S" with timeout 3000 ms
     Then counter "io_tls_ok_C" plus counter "io_tls_timeout_C" plus counter "io_tls_cancelled_C" plus counter "io_tls_failed_C" equals counter "io_tls_attempts_C"
      And counter "io_tls_ok_C" is at least 1
      And counter "tls_accept_ok_S" is at least 1
      And coroutine "C" is completed
      And coroutine "SRV" is completed
      And no orphan coroutines

  Scenario: four concurrent clients, server accepts all
    # Stresses parallel handshakes against one accept loop — multiple
    # crypto-poll states alive in the reactor at the same time.
    Given a coroutine "SRV"
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
      And a coroutine "C4"
      And TLS server "S" listening on "SRV" accepting up to 4 clients
     When coroutine "C1" connects via TLS to server "S" with timeout 3000 ms
      And coroutine "C2" connects via TLS to server "S" with timeout 3000 ms
      And coroutine "C3" connects via TLS to server "S" with timeout 3000 ms
      And coroutine "C4" connects via TLS to server "S" with timeout 3000 ms
     Then counter "io_tls_ok_C1" plus counter "io_tls_timeout_C1" plus counter "io_tls_cancelled_C1" plus counter "io_tls_failed_C1" equals counter "io_tls_attempts_C1"
      And counter "io_tls_ok_C2" plus counter "io_tls_timeout_C2" plus counter "io_tls_cancelled_C2" plus counter "io_tls_failed_C2" equals counter "io_tls_attempts_C2"
      And counter "io_tls_ok_C3" plus counter "io_tls_timeout_C3" plus counter "io_tls_cancelled_C3" plus counter "io_tls_failed_C3" equals counter "io_tls_attempts_C3"
      And counter "io_tls_ok_C4" plus counter "io_tls_timeout_C4" plus counter "io_tls_cancelled_C4" plus counter "io_tls_failed_C4" equals counter "io_tls_attempts_C4"
      And counter "tls_accept_ok_S" is at least 4
      And coroutine "C1" is completed
      And coroutine "C2" is completed
      And coroutine "C3" is completed
      And coroutine "C4" is completed
      And coroutine "SRV" is completed
      And no orphan coroutines

  Scenario Outline: cancel timing varied against a handshaking client
    # Sweeps the cancel delay across the handshake window. The cancel
    # can land during TCP connect, during the crypto exchange, or after
    # the handshake already completed. Every interleaving must bucket
    # into exactly one outcome; the connect/poll watcher and crypto
    # state must be released cleanly.
    Given a coroutine "SRV"
      And a coroutine "C"
      And a coroutine "K"
      And TLS server "S" listening on "SRV" accepting up to 1 clients
     When coroutine "C" connects via TLS to server "S" with timeout 3000 ms
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "C"
     Then counter "io_tls_ok_C" plus counter "io_tls_timeout_C" plus counter "io_tls_cancelled_C" plus counter "io_tls_failed_C" equals counter "io_tls_attempts_C"
      And coroutine "C" is completed
      And coroutine "K" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 8  |
      | 20 |
