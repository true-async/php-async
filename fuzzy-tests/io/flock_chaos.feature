Feature: I/O chaos — flock() under N-coroutine contention (cancel blocked on #146)

  `ext/async/tests/io/081-flock_non_blocking_event_loop.phpt` pins one
  deterministic shape: main thread holds LOCK_EX, one waiter parks in the
  reactor's flock thread-pool wait, peer worker still runs. This feature
  fuzzes the contention surface: N coroutines all try LOCK_EX on the same
  file under random scheduling.

  Invariants — true under any interleaving:
    - every coroutine ends in exactly one of {ok, cancelled, failed};
      flock_ok + flock_cancelled + flock_failed == flock_attempts per coro;
    - cumulative `flock_ok_*` equals the number of non-cancelled attempts
      (every non-cancelled coroutine eventually grabs the lock);
    - no orphan coroutines.

  # ----------------------------------------------------------------------
  # Blocked: #146 (flock(): stack-use-after-return when coroutine is
  # cancelled mid-flock). main/streams/plain_wrapper.c:1192 puts the task
  # struct on the caller's stack; the libuv worker keeps writing into it
  # after the coroutine unwinds on cancel. SEGV / ASAN UAF. Reproduced by
  # the two scenarios below; reinstate them by uncomment after #146 fix.
  #
  # Scenario: holder keeps the lock long, two waiters race a killer
  # Scenario Outline: cancel-timing varied against a parked LOCK_EX waiter
  #   Examples: | ms | 5 | 50 | 150 |
  # ----------------------------------------------------------------------

  Scenario: four coroutines contend for one lock
    # Each coroutine fopen()s a fresh fd to the same file, locks LOCK_EX,
    # holds for 5 ms so the others actually contend, unlocks, closes.
    # No cancels — exercises only the contention path (not the cancel
    # UAF in #146).
    Given a shared lock file "L"
      And a coroutine "A"
      And a coroutine "B"
      And a coroutine "C"
      And a coroutine "D"
     When coroutine "A" acquires LOCK_EX on "L" then releases after 5 ms
      And coroutine "B" acquires LOCK_EX on "L" then releases after 5 ms
      And coroutine "C" acquires LOCK_EX on "L" then releases after 5 ms
      And coroutine "D" acquires LOCK_EX on "L" then releases after 5 ms
     Then counter "flock_ok_A" plus counter "flock_cancelled_A" plus counter "flock_failed_A" equals counter "flock_attempts_A"
      And counter "flock_ok_B" plus counter "flock_cancelled_B" plus counter "flock_failed_B" equals counter "flock_attempts_B"
      And counter "flock_ok_C" plus counter "flock_cancelled_C" plus counter "flock_failed_C" equals counter "flock_attempts_C"
      And counter "flock_ok_D" plus counter "flock_cancelled_D" plus counter "flock_failed_D" equals counter "flock_attempts_D"
      And counter "flock_ok_A" equals 1
      And counter "flock_ok_B" equals 1
      And counter "flock_ok_C" equals 1
      And counter "flock_ok_D" equals 1
      And coroutine "A" is completed
      And coroutine "B" is completed
      And coroutine "C" is completed
      And coroutine "D" is completed
      And no orphan coroutines

  Scenario: holder keeps the lock, two waiters queue, all eventually acquire
    # Holder takes LOCK_EX and holds 80 ms. Two waiters park in the
    # flock thread-pool wait. All three eventually acquire and release.
    Given a shared lock file "L"
      And a coroutine "H"
      And a coroutine "W1"
      And a coroutine "W2"
     When coroutine "H" acquires LOCK_EX on "L" then releases after 80 ms
      And coroutine "W1" acquires LOCK_EX on "L" then releases after 5 ms
      And coroutine "W2" acquires LOCK_EX on "L" then releases after 5 ms
     Then counter "flock_ok_H" equals 1
      And counter "flock_ok_W1" equals 1
      And counter "flock_ok_W2" equals 1
      And coroutine "H" is completed
      And coroutine "W1" is completed
      And coroutine "W2" is completed
      And no orphan coroutines
