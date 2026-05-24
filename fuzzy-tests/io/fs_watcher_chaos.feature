Feature: I/O chaos — Async\FileSystemWatcher under cancel + close race

  Drives the reactor's filesystem-watcher event loop via
  `Async\FileSystemWatcher`. An iterator coroutine `foreach`es the
  watcher; sibling coroutines touch files in the watched directory
  on staggered delays; a third coroutine may close() the watcher or
  cancel the iterator. The iterator must terminate cleanly, the
  watcher's libuv handle must be released without leaks, and the
  event count must hold the sum invariant.

  ext/async/tests/fs_watcher/ covers the happy paths and one
  cancellation scenario; this feature crosses producers (file
  touches) × terminators (close / cancel) under ChaosNet scheduling.

  Scenario: one touch produces at least one event, then close stops iterator
    Given a filesystem watcher "W" on a fresh temp directory
      And a coroutine "I"
      And a coroutine "T"
      And a coroutine "C"
     When coroutine "I" iterates filesystem watcher "W"
      And coroutine "T" sleeps 30 ms
      And coroutine "T" touches file "a.txt" in filesystem watcher "W" directory
      And coroutine "C" sleeps 200 ms
      And coroutine "C" closes filesystem watcher "W"
     Then counter "fsw_iter_done_I" plus counter "fsw_iter_cancelled_I" plus counter "fsw_iter_failed_I" equals counter "fsw_iter_attempts_I"
      And counter "fsw_iter_done_I" is at least 1
      And counter "fsw_events_I" is at least 1
      And counter "fsw_bad_event_I" equals 0
      And coroutine "I" is completed
      And coroutine "T" is completed
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: close races several producers
    # Multiple file touches at staggered delays; close fires after the
    # last touch is queued. The iterator must end via fsw_iter_done
    # (not cancelled / failed); fsw_events sums whatever the coalesce
    # buffer kept.
    Given a filesystem watcher "W" on a fresh temp directory
      And a coroutine "I"
      And a coroutine "T1"
      And a coroutine "T2"
      And a coroutine "T3"
      And a coroutine "C"
     When coroutine "I" iterates filesystem watcher "W"
      And coroutine "T1" sleeps 30 ms
      And coroutine "T1" touches file "a.txt" in filesystem watcher "W" directory
      And coroutine "T2" sleeps 60 ms
      And coroutine "T2" touches file "b.txt" in filesystem watcher "W" directory
      And coroutine "T3" sleeps 90 ms
      And coroutine "T3" touches file "c.txt" in filesystem watcher "W" directory
      And coroutine "C" sleeps 250 ms
      And coroutine "C" closes filesystem watcher "W"
     Then counter "fsw_iter_done_I" plus counter "fsw_iter_cancelled_I" plus counter "fsw_iter_failed_I" equals counter "fsw_iter_attempts_I"
      And counter "fsw_iter_done_I" is at least 1
      And counter "fsw_events_I" is at least 1
      And counter "fsw_bad_event_I" equals 0
      And coroutine "I" is completed
      And coroutine "T1" is completed
      And coroutine "T2" is completed
      And coroutine "T3" is completed
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: cancel the iterator instead of closing the watcher
    # The iterator is cancelled mid-foreach; the watcher's libuv
    # handle must still be released cleanly via teardown.
    Given a filesystem watcher "W" on a fresh temp directory
      And a coroutine "I"
      And a coroutine "T"
      And a coroutine "K"
     When coroutine "I" iterates filesystem watcher "W"
      And coroutine "T" sleeps 30 ms
      And coroutine "T" touches file "a.txt" in filesystem watcher "W" directory
      And coroutine "K" sleeps 120 ms
      And coroutine "K" cancels coroutine "I"
     Then counter "fsw_iter_done_I" plus counter "fsw_iter_cancelled_I" plus counter "fsw_iter_failed_I" equals counter "fsw_iter_attempts_I"
      And counter "fsw_iter_cancelled_I" is at least 1
      And counter "fsw_bad_event_I" equals 0
      And coroutine "I" is completed
      And coroutine "T" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario: close immediately, no events
    # close() happens before any touch. The iterator's foreach exits
    # via the close path (fsw_iter_done) with zero events. Exercises
    # the close-on-empty release path.
    Given a filesystem watcher "W" on a fresh temp directory
      And a coroutine "I"
      And a coroutine "C"
     When coroutine "I" iterates filesystem watcher "W"
      And coroutine "C" sleeps 30 ms
      And coroutine "C" closes filesystem watcher "W"
     Then counter "fsw_iter_done_I" plus counter "fsw_iter_cancelled_I" plus counter "fsw_iter_failed_I" equals counter "fsw_iter_attempts_I"
      And counter "fsw_iter_done_I" is at least 1
      And counter "fsw_bad_event_I" equals 0
      And coroutine "I" is completed
      And coroutine "C" is completed
      And no orphan coroutines
