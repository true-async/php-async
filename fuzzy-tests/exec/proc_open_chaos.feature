Feature: I/O chaos — proc_open / proc_close storm + parked-reader race (mostly blocked on #144)

  Drives the reactor's child-process integration via `proc_open()`. Two
  shapes were originally planned:

    (a) reader parks on fread(child stdout) while a killer proc_terminate's
        and proc_close's the same handle — child dies, kernel closes child's
        stdout, reader should observe EOF;
    (b) rapid proc_open + proc_close storm across N coroutines — stresses
        the libuv process_event hashtable / OS-HANDLE-reuse path that
        tests/exec/011-proc_open_handle_reuse_uaf already backstops.

  Shape (a) is **blocked on php-async#144**: the reactor does not wake a
  parked fread() on the child's pipe when the child is externally
  terminated and reaped. The deadlock detector eventually aborts the
  request. Reproduced outside the harness in ~25 lines while drafting
  this feature; see FINDINGS.md. The scenarios are kept here, commented
  out under `# Blocked: #144`, so reinstating them after the fix is a
  one-line uncomment.

  Shape (b) is unaffected (no parked reader) and is shipped.

  # ----------------------------------------------------------------------
  # Blocked: #144 (reactor does not wake parked fread on terminated child)
  # ----------------------------------------------------------------------
  # Scenario: proc_close races a parked stdout reader
  #   Given a long-lived child process "C" sleeping 200 ms
  #     And a coroutine "R"
  #     And a coroutine "K"
  #    When coroutine "R" reads stdout of child process "C"
  #     And coroutine "K" sleeps 10 ms
  #     And coroutine "K" closes child process "C"
  #    Then counter "proc_read_ok_R" plus counter "proc_read_eof_R" plus counter "proc_read_cancelled_R" plus counter "proc_read_failed_R" equals counter "proc_read_attempts_R"
  #     And coroutine "R" is completed
  #     And coroutine "K" is completed
  #     And no orphan coroutines
  #
  # Scenario Outline: close-timing varied against a parked stdout reader
  #   Given a long-lived child process "C" sleeping 200 ms
  #     And a coroutine "R"
  #     And a coroutine "K"
  #    When coroutine "R" reads stdout of child process "C"
  #     And coroutine "K" sleeps <ms> ms
  #     And coroutine "K" closes child process "C"
  #    Then counter "proc_read_ok_R" plus counter "proc_read_eof_R" plus counter "proc_read_cancelled_R" plus counter "proc_read_failed_R" equals counter "proc_read_attempts_R"
  #     And coroutine "R" is completed
  #     And coroutine "K" is completed
  #     And no orphan coroutines
  #   Examples:
  #     | ms | 0 | 5 | 25 | 60 |
  #
  # Scenario: SIGTERM races a parked stdout reader
  # Scenario: cancel reader and proc_close race
  # ----------------------------------------------------------------------

  Scenario: rapid proc_open / proc_close storm — #011 UAF backstop
    # Four coroutines each open + close N short-lived children in a loop.
    # The OS recycles process HANDLEs aggressively, so the reactor's
    # process_events hashtable is stressed: a stale entry left after
    # dispose would crash on the next ZEND_ASYNC_EVENT_ADD_REF. Each
    # iteration ends with Async\suspend() so peer coroutines interleave
    # their open/close cycles. Invariant: every cycle either succeeds or
    # fails cleanly, and no coroutine is orphaned.
    Given a coroutine "S0"
      And a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "S3"
     When coroutine "S0" runs proc_open + proc_close 5 times
      And coroutine "S1" runs proc_open + proc_close 5 times
      And coroutine "S2" runs proc_open + proc_close 5 times
      And coroutine "S3" runs proc_open + proc_close 5 times
     Then counter "proc_storm_ok_S0" plus counter "proc_storm_failed_S0" equals counter "proc_storm_attempts_S0"
      And counter "proc_storm_ok_S1" plus counter "proc_storm_failed_S1" equals counter "proc_storm_attempts_S1"
      And counter "proc_storm_ok_S2" plus counter "proc_storm_failed_S2" equals counter "proc_storm_attempts_S2"
      And counter "proc_storm_ok_S3" plus counter "proc_storm_failed_S3" equals counter "proc_storm_attempts_S3"
      And counter "proc_storm_attempts_S0" equals 5
      And counter "proc_storm_attempts_S1" equals 5
      And counter "proc_storm_attempts_S2" equals 5
      And counter "proc_storm_attempts_S3" equals 5
      And coroutine "S0" is completed
      And coroutine "S1" is completed
      And coroutine "S2" is completed
      And coroutine "S3" is completed
      And no orphan coroutines
