Feature: I/O chaos — closing a stream while another coroutine reads it

  A coroutine parked in fread() on a stream must wake — without UAF, hang or
  leaked watcher — when a *different* coroutine closes the write end of the
  same pipe. The race is orthogonal to direct $coro->cancel(): the reader is
  never asked to stop; the producer goes away and the kernel signals EOF.

  Under random scheduling the close can land while the reader is still
  arranging its wait, exactly during the libuv poll, or after EOF has already
  been observed naturally. The invariant is the outcome sum
  (attempts == ok|eof|cancelled|failed), plus liveness: the reader coroutine
  terminates and no coroutine is orphaned.

  Closing the *read* fd while a coroutine is mid-`fread()` on that same fd
  is intentionally not exercised here — that is a user-error path (a freed
  PHP stream pointer is being dereferenced by the reader). Tracking that
  case alongside the per-request-event redesign (php-async#130).

  Scenario: close write end while a reader is parked
    # fread() should observe a clean EOF — never a hang.
    Given a shared pipe "P"
      And a coroutine "R"
      And a coroutine "K"
     When coroutine "R" reads from shared pipe "P"
      And coroutine "K" sleeps 10 ms
      And coroutine "K" closes the write end of shared pipe "P"
     Then counter "io_pread_ok_R" plus counter "io_pread_eof_R" plus counter "io_pread_cancelled_R" plus counter "io_pread_failed_R" equals counter "io_pread_attempts_R"
      And coroutine "R" is completed
      And no orphan coroutines

  Scenario Outline: close-timing varied against a parked reader
    Given a shared pipe "P"
      And a coroutine "R"
      And a coroutine "K"
     When coroutine "R" reads from shared pipe "P"
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" closes the write end of shared pipe "P"
     Then counter "io_pread_ok_R" plus counter "io_pread_eof_R" plus counter "io_pread_cancelled_R" plus counter "io_pread_failed_R" equals counter "io_pread_attempts_R"
      And coroutine "R" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 5  |
      | 25 |
      | 60 |

  Scenario: write then close races the reader
    # Reader may consume the payload (io_pread_ok) or pick up only the EOF
    # after the close — both are admissible. The point is liveness.
    Given a shared pipe "P"
      And a coroutine "R"
      And a coroutine "W"
     When coroutine "R" reads from shared pipe "P"
      And coroutine "W" writes "ping" to shared pipe "P"
      And coroutine "W" sleeps 5 ms
      And coroutine "W" closes the write end of shared pipe "P"
     Then counter "io_pread_ok_R" plus counter "io_pread_eof_R" plus counter "io_pread_cancelled_R" plus counter "io_pread_failed_R" equals counter "io_pread_attempts_R"
      And counter "io_pwrite_ok_W" plus counter "io_pwrite_failed_W" plus counter "io_pwrite_cancelled_W" equals counter "io_pwrite_attempts_W"
      And coroutine "R" is completed
      And coroutine "W" is completed
      And no orphan coroutines

  Scenario: cancel reader and close-writer race
    # Two distinct termination paths converge: $coro->cancel() injects an
    # AsyncCancellation into the read wait, while the close-writer drops the
    # producer and the kernel signals EOF. Either may win on any given
    # interleaving — the reader must wake exactly once and terminate cleanly.
    Given a shared pipe "P"
      And a coroutine "R"
      And a coroutine "K"
    Any of:
      - coroutine "K" sleeps 3 ms
      - coroutine "K" sleeps 12 ms
     When coroutine "R" reads from shared pipe "P"
      And coroutine "K" cancels coroutine "R"
      And coroutine "K" closes the write end of shared pipe "P"
     Then counter "io_pread_ok_R" plus counter "io_pread_eof_R" plus counter "io_pread_cancelled_R" plus counter "io_pread_failed_R" equals counter "io_pread_attempts_R"
      And coroutine "R" is completed
      And no orphan coroutines
