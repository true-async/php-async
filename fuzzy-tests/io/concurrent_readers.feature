Feature: I/O chaos — several coroutines reading one shared pipe

  Several coroutines block in fread() on the same stream while a separate
  coroutine pushes a bounded payload and closes the writer. Only one reader
  can consume any given byte; the rest must observe a clean EOF once the
  writer is gone. Cancelling half of the readers must leave the survivors
  unaffected — the cached poll proxy is shared across all readers and a
  cancel that leaks an extra stop decrement would tear the proxy down for
  everyone (php-async ABI < 0.18 bug, see FINDINGS.md).

  Invariants per reader: attempts == ok|eof|cancelled|failed; every
  coroutine terminates; no orphan coroutines.

  Scenario: three readers, one writer
    Given a shared pipe "P"
      And a coroutine "R1"
      And a coroutine "R2"
      And a coroutine "R3"
      And a coroutine "W"
     When coroutine "R1" reads from shared pipe "P"
      And coroutine "R2" reads from shared pipe "P"
      And coroutine "R3" reads from shared pipe "P"
      And coroutine "W" sleeps 5 ms
      And coroutine "W" writes "ping" to shared pipe "P"
      And coroutine "W" closes the write end of shared pipe "P"
     Then counter "io_pread_ok_R1" plus counter "io_pread_eof_R1" plus counter "io_pread_cancelled_R1" plus counter "io_pread_failed_R1" equals counter "io_pread_attempts_R1"
      And counter "io_pread_ok_R2" plus counter "io_pread_eof_R2" plus counter "io_pread_cancelled_R2" plus counter "io_pread_failed_R2" equals counter "io_pread_attempts_R2"
      And counter "io_pread_ok_R3" plus counter "io_pread_eof_R3" plus counter "io_pread_cancelled_R3" plus counter "io_pread_failed_R3" equals counter "io_pread_attempts_R3"
      And coroutine "R1" is completed
      And coroutine "R2" is completed
      And coroutine "R3" is completed
      And coroutine "W" is completed
      And no orphan coroutines

  Scenario: four readers, cancel half
    # Two readers are killed mid-wait; the surviving two must still wake on
    # the writer's payload or its close. The cancels must not damage the
    # cached poll proxy that R1/R3 are still parked on.
    Given a shared pipe "P"
      And a coroutine "R1"
      And a coroutine "R2"
      And a coroutine "R3"
      And a coroutine "R4"
      And a coroutine "W"
      And a coroutine "K"
     When coroutine "R1" reads from shared pipe "P"
      And coroutine "R2" reads from shared pipe "P"
      And coroutine "R3" reads from shared pipe "P"
      And coroutine "R4" reads from shared pipe "P"
      And coroutine "K" sleeps 8 ms
      And coroutine "K" cancels coroutine "R2"
      And coroutine "K" cancels coroutine "R4"
      And coroutine "W" sleeps 15 ms
      And coroutine "W" writes "ping" to shared pipe "P"
      And coroutine "W" closes the write end of shared pipe "P"
     Then counter "io_pread_ok_R1" plus counter "io_pread_eof_R1" plus counter "io_pread_cancelled_R1" plus counter "io_pread_failed_R1" equals counter "io_pread_attempts_R1"
      And counter "io_pread_ok_R2" plus counter "io_pread_eof_R2" plus counter "io_pread_cancelled_R2" plus counter "io_pread_failed_R2" equals counter "io_pread_attempts_R2"
      And counter "io_pread_ok_R3" plus counter "io_pread_eof_R3" plus counter "io_pread_cancelled_R3" plus counter "io_pread_failed_R3" equals counter "io_pread_attempts_R3"
      And counter "io_pread_ok_R4" plus counter "io_pread_eof_R4" plus counter "io_pread_cancelled_R4" plus counter "io_pread_failed_R4" equals counter "io_pread_attempts_R4"
      And coroutine "R1" is completed
      And coroutine "R2" is completed
      And coroutine "R3" is completed
      And coroutine "R4" is completed
      And coroutine "W" is completed
      And no orphan coroutines

  Scenario Outline: cancel timing varied against three readers
    Given a shared pipe "P"
      And a coroutine "R1"
      And a coroutine "R2"
      And a coroutine "R3"
      And a coroutine "W"
      And a coroutine "K"
     When coroutine "R1" reads from shared pipe "P"
      And coroutine "R2" reads from shared pipe "P"
      And coroutine "R3" reads from shared pipe "P"
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "R2"
      And coroutine "W" sleeps 20 ms
      And coroutine "W" writes "ping" to shared pipe "P"
      And coroutine "W" closes the write end of shared pipe "P"
     Then counter "io_pread_ok_R1" plus counter "io_pread_eof_R1" plus counter "io_pread_cancelled_R1" plus counter "io_pread_failed_R1" equals counter "io_pread_attempts_R1"
      And counter "io_pread_ok_R2" plus counter "io_pread_eof_R2" plus counter "io_pread_cancelled_R2" plus counter "io_pread_failed_R2" equals counter "io_pread_attempts_R2"
      And counter "io_pread_ok_R3" plus counter "io_pread_eof_R3" plus counter "io_pread_cancelled_R3" plus counter "io_pread_failed_R3" equals counter "io_pread_attempts_R3"
      And coroutine "R1" is completed
      And coroutine "R2" is completed
      And coroutine "R3" is completed
      And coroutine "W" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 10 |
      | 25 |

  Scenario: writer closes without writing — all readers see EOF
    Given a shared pipe "P"
      And a coroutine "R1"
      And a coroutine "R2"
      And a coroutine "R3"
      And a coroutine "W"
     When coroutine "R1" reads from shared pipe "P"
      And coroutine "R2" reads from shared pipe "P"
      And coroutine "R3" reads from shared pipe "P"
      And coroutine "W" sleeps 10 ms
      And coroutine "W" closes the write end of shared pipe "P"
     Then counter "io_pread_ok_R1" plus counter "io_pread_eof_R1" plus counter "io_pread_cancelled_R1" plus counter "io_pread_failed_R1" equals counter "io_pread_attempts_R1"
      And counter "io_pread_ok_R2" plus counter "io_pread_eof_R2" plus counter "io_pread_cancelled_R2" plus counter "io_pread_failed_R2" equals counter "io_pread_attempts_R2"
      And counter "io_pread_ok_R3" plus counter "io_pread_eof_R3" plus counter "io_pread_cancelled_R3" plus counter "io_pread_failed_R3" equals counter "io_pread_attempts_R3"
      And coroutine "R1" is completed
      And coroutine "R2" is completed
      And coroutine "R3" is completed
      And coroutine "W" is completed
      And no orphan coroutines

  Scenario: write-then-immediate-close, no cancels
    Given a shared pipe "P"
      And a coroutine "R1"
      And a coroutine "R2"
      And a coroutine "R3"
      And a coroutine "W"
    Any of:
      - coroutine "W" sleeps 0 ms
      - coroutine "W" sleeps 4 ms
     When coroutine "R1" reads from shared pipe "P"
      And coroutine "R2" reads from shared pipe "P"
      And coroutine "R3" reads from shared pipe "P"
      And coroutine "W" writes "ping" to shared pipe "P"
      And coroutine "W" closes the write end of shared pipe "P"
     Then counter "io_pread_ok_R1" plus counter "io_pread_eof_R1" plus counter "io_pread_cancelled_R1" plus counter "io_pread_failed_R1" equals counter "io_pread_attempts_R1"
      And counter "io_pread_ok_R2" plus counter "io_pread_eof_R2" plus counter "io_pread_cancelled_R2" plus counter "io_pread_failed_R2" equals counter "io_pread_attempts_R2"
      And counter "io_pread_ok_R3" plus counter "io_pread_eof_R3" plus counter "io_pread_cancelled_R3" plus counter "io_pread_failed_R3" equals counter "io_pread_attempts_R3"
      And coroutine "R1" is completed
      And coroutine "R2" is completed
      And coroutine "R3" is completed
      And coroutine "W" is completed
      And no orphan coroutines
