Feature: I/O chaos — proc_open / proc_close storm + parked-reader race (#144 fixed)

  Drives the reactor's child-process integration via `proc_open()`. Two
  shapes were originally planned:

    (a) reader parks on fread(child stdout) while a killer proc_terminate's
        and proc_close's the same handle — child dies, kernel closes child's
        stdout, reader should observe EOF;
    (b) rapid proc_open + proc_close storm across N coroutines — stresses
        the libuv process_event hashtable / OS-HANDLE-reuse path that
        tests/exec/011-proc_open_handle_reuse_uaf already backstops.

  Shape (a) is **fixed in #144**: the reactor now notifies a parked
  fread() on the child's pipe when the child is externally terminated and
  reaped (commit ef36f8d notifies the parked req on close + early-returns
  on io_closed; commit 7a75c1e pins the stdio stream/data/io lifetime
  across the async SUSPEND so the wakeup path cannot UAF). The scenarios
  below — originally a deterministic deadlock + the ~25-line repro in
  FINDINGS.md — are the regression backstop for that fix.

  Shape (b) is unaffected (no parked reader) and was always shipped.

  Scenario: proc_close races a parked stdout reader (#144 regression)
    # Reader parks in fread() on the child's stdout; killer terminates +
    # reaps the child. Before #144 the reader never woke and the deadlock
    # detector aborted the request. It must now observe EOF cleanly.
    Given a long-lived child process "C" sleeping 200 ms
      And a coroutine "R"
      And a coroutine "K"
     When coroutine "R" reads stdout of child process "C"
      And coroutine "K" sleeps 10 ms
      And coroutine "K" closes child process "C"
     Then counter "proc_read_ok_R" plus counter "proc_read_eof_R" plus counter "proc_read_cancelled_R" plus counter "proc_read_failed_R" equals counter "proc_read_attempts_R"
      And coroutine "R" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario Outline: close-timing varied against a parked stdout reader (#144)
    Given a long-lived child process "C" sleeping 200 ms
      And a coroutine "R"
      And a coroutine "K"
     When coroutine "R" reads stdout of child process "C"
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" closes child process "C"
     Then counter "proc_read_ok_R" plus counter "proc_read_eof_R" plus counter "proc_read_cancelled_R" plus counter "proc_read_failed_R" equals counter "proc_read_attempts_R"
      And coroutine "R" is completed
      And coroutine "K" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 5  |
      | 25 |
      | 60 |

  Scenario: SIGTERM races a parked stdout reader (#144 regression)
    # Like the close race, but the killer only sends SIGTERM (no
    # proc_close). The kernel closes the child's stdout end on exit, so
    # the parked reader must still wake with EOF; the handle is reaped in
    # teardown.
    Given a long-lived child process "C" sleeping 200 ms
      And a coroutine "R"
      And a coroutine "K"
     When coroutine "R" reads stdout of child process "C"
      And coroutine "K" sleeps 10 ms
      And coroutine "K" sends SIGTERM to child process "C"
     Then counter "proc_read_ok_R" plus counter "proc_read_eof_R" plus counter "proc_read_cancelled_R" plus counter "proc_read_failed_R" equals counter "proc_read_attempts_R"
      And counter "proc_term_ok_K" equals 1
      And coroutine "R" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario: cancel reader then proc_close race (#144 regression)
    # The reader is cancelled while parked, then the killer reaps the
    # child. Cancellation and the close-wakeup race; the reader unwinds
    # via exactly one bucket and the handle is reaped without UAF.
    Given a long-lived child process "C" sleeping 200 ms
      And a coroutine "R"
      And a coroutine "K"
     When coroutine "R" reads stdout of child process "C"
      And coroutine "K" sleeps 10 ms
      And coroutine "K" cancels coroutine "R"
      And coroutine "K" closes child process "C"
     Then counter "proc_read_ok_R" plus counter "proc_read_eof_R" plus counter "proc_read_cancelled_R" plus counter "proc_read_failed_R" equals counter "proc_read_attempts_R"
      And coroutine "R" is completed
      And coroutine "K" is completed
      And no orphan coroutines

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
