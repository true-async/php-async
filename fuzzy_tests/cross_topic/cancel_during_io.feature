Feature: Cancel a coroutine that is blocked on real I/O

  When a coroutine is suspended inside stream_socket_accept() or fread()
  on a libuv-driven stream, calling its handle's cancel() must deliver
  Async\AsyncCancellation into the I/O wait. The reactor must release the
  underlying request (no UAF, no leaked fd) and the coroutine must
  terminate via its catch block.

  Hand-written baseline: tests/stream/045-accept_cancel_uaf.

  Note: under random scheduling cancel() can fire BEFORE the I/O body
  reaches the try block (during socket setup, which is itself a yield
  point). In that case neither attempts nor outcome counters are bumped.
  The invariant therefore is "attempts equals sum of outcome buckets",
  not "attempts equals 1".

  Invariants for every interleaving:
    io_accept_attempts_$X == io_accept_ok_$X + io_accept_cancelled_$X
                           + io_accept_timeout_$X + io_accept_failed_$X
    coroutine "X" is completed
    no orphan coroutines

  Scenario: cancel a coroutine blocked on TCP accept
    Given a coroutine "S"
      And a coroutine "K"
     When coroutine "S" listens for one connection on a fresh socket
      And coroutine "K" cancels coroutine "S"
     Then counter "io_accept_ok_S" plus counter "io_accept_cancelled_S" plus counter "io_accept_failed_S" plus counter "io_accept_timeout_S" equals counter "io_accept_attempts_S"
      And coroutine "S" is completed
      And no orphan coroutines

  Scenario: cancel a coroutine blocked on pipe read
    Given a coroutine "R"
      And a coroutine "K"
     When coroutine "R" reads from a fresh pipe
      And coroutine "K" cancels coroutine "R"
     Then counter "io_read_ok_R" plus counter "io_read_cancelled_R" plus counter "io_read_failed_R" plus counter "io_read_eof_R" equals counter "io_read_attempts_R"
      And coroutine "R" is completed
      And no orphan coroutines

  Scenario: cancel after a small delay — body is in accept by then
    Given a coroutine "S"
      And a coroutine "K"
     When coroutine "S" listens for one connection on a fresh socket
      And coroutine "K" sleeps 10 ms
      And coroutine "K" cancels coroutine "S"
     Then counter "io_accept_ok_S" plus counter "io_accept_cancelled_S" plus counter "io_accept_failed_S" plus counter "io_accept_timeout_S" equals counter "io_accept_attempts_S"
      And coroutine "S" is completed
      And no orphan coroutines

  Scenario: many accepters cancelled together
    Given a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "S3"
      And a coroutine "K"
     When coroutine "S1" listens for one connection on a fresh socket
      And coroutine "S2" listens for one connection on a fresh socket
      And coroutine "S3" listens for one connection on a fresh socket
      And coroutine "K" cancels coroutine "S1"
      And coroutine "K" cancels coroutine "S2"
      And coroutine "K" cancels coroutine "S3"
     Then counter "io_accept_ok_S1" plus counter "io_accept_cancelled_S1" plus counter "io_accept_failed_S1" plus counter "io_accept_timeout_S1" equals counter "io_accept_attempts_S1"
      And counter "io_accept_ok_S2" plus counter "io_accept_cancelled_S2" plus counter "io_accept_failed_S2" plus counter "io_accept_timeout_S2" equals counter "io_accept_attempts_S2"
      And counter "io_accept_ok_S3" plus counter "io_accept_cancelled_S3" plus counter "io_accept_failed_S3" plus counter "io_accept_timeout_S3" equals counter "io_accept_attempts_S3"
      And coroutine "S1" is completed
      And coroutine "S2" is completed
      And coroutine "S3" is completed
      And no orphan coroutines

  Scenario Outline: vary cancel delay against accept
    Given a coroutine "S"
      And a coroutine "K"
     When coroutine "S" listens for one connection on a fresh socket
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "S"
     Then counter "io_accept_ok_S" plus counter "io_accept_cancelled_S" plus counter "io_accept_failed_S" plus counter "io_accept_timeout_S" equals counter "io_accept_attempts_S"
      And coroutine "S" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 5  |
      | 50 |
