Feature: I/O chaos — stream_select() under cancel + write race

  Drives the reactor's `network_async_stream_select()` (multi-fd poll
  watcher) by calling `stream_select()` over a set of shared pipes.
  A separate coroutine writes to one or two of the pipes on a delay;
  a third may cancel the selecting coroutine. Outcomes (woke /
  timeout / cancelled / failed) must always bucket, the multi-fd
  watcher must be released without leaks, and the selecting coroutine
  must terminate cleanly with no orphans.

  Today's `ext/async/tests/stream/` covers stream_select happy paths;
  this is the first chaos coverage of the multi-fd poll watcher.

  Scenario: select wakes when a peer writes one of the watched pipes
    Given a shared pipe "P1"
      And a shared pipe "P2"
      And a shared pipe "P3"
      And a coroutine "S"
      And a coroutine "W"
     When coroutine "S" stream_selects on shared pipes "P1","P2","P3" for 500 ms
      And coroutine "W" sleeps 20 ms
      And coroutine "W" writes "ping" to shared pipe "P2"
     Then counter "select_woke_S" plus counter "select_timeout_S" plus counter "select_cancelled_S" plus counter "select_failed_S" equals counter "select_attempts_S"
      And counter "select_woke_S" is at least 1
      And coroutine "S" is completed
      And coroutine "W" is completed
      And no orphan coroutines

  Scenario: short tv elapses with no writer — clean timeout
    # No writer fires before tv expires. The multi-fd watcher must
    # release cleanly on timeout and return 0; the selecting coroutine
    # must then terminate without leaking the watcher.
    Given a shared pipe "P1"
      And a shared pipe "P2"
      And a coroutine "S"
     When coroutine "S" stream_selects on shared pipes "P1","P2" for 30 ms
     Then counter "select_woke_S" plus counter "select_timeout_S" plus counter "select_cancelled_S" plus counter "select_failed_S" equals counter "select_attempts_S"
      And counter "select_timeout_S" is at least 1
      And coroutine "S" is completed
      And no orphan coroutines

  Scenario: cancel during select
    # tv is well above the cancel delay so the cancel always wins;
    # AsyncCancellation must release the multi-fd watcher mid-wait.
    Given a shared pipe "P1"
      And a shared pipe "P2"
      And a shared pipe "P3"
      And a coroutine "S"
      And a coroutine "K"
     When coroutine "S" stream_selects on shared pipes "P1","P2","P3" for 5000 ms
      And coroutine "K" sleeps 10 ms
      And coroutine "K" cancels coroutine "S"
     Then counter "select_woke_S" plus counter "select_timeout_S" plus counter "select_cancelled_S" plus counter "select_failed_S" equals counter "select_attempts_S"
      And counter "select_cancelled_S" is at least 1
      And coroutine "S" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario Outline: write delay varied across the tv window
    # Write delay sweeps from before-select-likely-parks (0/2 ms) to
    # comfortably inside the tv window (50 ms) to past-tv (250 ms,
    # tv=200 → forces timeout outcome). Every interleaving lands in
    # exactly one bucket.
    Given a shared pipe "P1"
      And a shared pipe "P2"
      And a coroutine "S"
      And a coroutine "W"
     When coroutine "S" stream_selects on shared pipes "P1","P2" for 200 ms
      And coroutine "W" sleeps <ms> ms
      And coroutine "W" writes "x" to shared pipe "P1"
     Then counter "select_woke_S" plus counter "select_timeout_S" plus counter "select_cancelled_S" plus counter "select_failed_S" equals counter "select_attempts_S"
      And coroutine "S" is completed
      And coroutine "W" is completed
      And no orphan coroutines

    Examples:
      | ms  |
      | 0   |
      | 2   |
      | 50  |
      | 250 |

  Scenario: two selectors share two pipes — a single write wakes one
    # Two coroutines select on overlapping fd sets. The writer hits
    # one pipe; whichever selector observed P1 in its set may wake.
    # Both selectors must terminate (the loser via timeout) — a
    # watcher leak on one selector's release path would hang the
    # other.
    Given a shared pipe "P1"
      And a shared pipe "P2"
      And a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "W"
     When coroutine "S1" stream_selects on shared pipes "P1","P2" for 200 ms
      And coroutine "S2" stream_selects on shared pipes "P1","P2" for 200 ms
      And coroutine "W" sleeps 15 ms
      And coroutine "W" writes "y" to shared pipe "P1"
     Then counter "select_woke_S1" plus counter "select_timeout_S1" plus counter "select_cancelled_S1" plus counter "select_failed_S1" equals counter "select_attempts_S1"
      And counter "select_woke_S2" plus counter "select_timeout_S2" plus counter "select_cancelled_S2" plus counter "select_failed_S2" equals counter "select_attempts_S2"
      And coroutine "S1" is completed
      And coroutine "S2" is completed
      And coroutine "W" is completed
      And no orphan coroutines
