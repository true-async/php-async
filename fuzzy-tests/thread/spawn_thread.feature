Feature: spawn_thread result and exception handoff

  Exercises the OS-thread result/exception handoff at the end of
  async_thread_run. A worker thread transfers its result (or exception) into
  the thread event and notifies the parent; the parent's dispose path detaches
  and frees that event. Under the chaos scheduler the awaiting coroutine and
  request teardown race the worker, so this covers both the normal handoff and
  the "parent detached" branch where the worker must release its own result.

  Regression coverage for the shutdown use-after-free where the engine freed
  the thread event while a worker was still inside async_thread_run.

  Invariants:
    thr_spawned_X + thr_spawn_failed_X == N
    thr_completed_X + thr_failed_X == thr_spawned_X   (every awaited thread settles)
    no orphan coroutines

  Scenario Outline: spawn N value-returning threads and await them all
    Given a coroutine "W"
     When coroutine "W" spawns <n> threads returning their index
      And coroutine "W" awaits all threads
     Then counter "thr_spawned_W" plus counter "thr_spawn_failed_W" equals <n>
      And counter "thr_completed_W" plus counter "thr_failed_W" equals counter "thr_spawned_W"
      And no orphan coroutines

    Examples:
      | n  |
      | 1  |
      | 4  |
      | 12 |

  Scenario Outline: spawn N throwing threads and await them all
    Given a coroutine "W"
     When coroutine "W" spawns <n> threads that throw
      And coroutine "W" awaits all threads
     Then counter "thr_spawned_W" plus counter "thr_spawn_failed_W" equals <n>
      And counter "thr_completed_W" plus counter "thr_failed_W" equals counter "thr_spawned_W"
      And no orphan coroutines

    Examples:
      | n |
      | 1 |
      | 5 |
      | 9 |

  Scenario Outline: spawn N detached threads — teardown races the workers
    # Handles are dropped on purpose: the workers are still inside
    # async_thread_run when the harness tears down, so each worker hits the
    # "parent detached" handoff branch. The real assertion is "no crash".
    Given a coroutine "D"
     When coroutine "D" spawns <n> detached threads
     Then counter "thr_spawned_D" plus counter "thr_spawn_failed_D" equals <n>
      And no orphan coroutines

    Examples:
      | n  |
      | 2  |
      | 6  |
      | 16 |

  Scenario Outline: throwing threads surface as Async\RemoteException
    # A thread that throws is delivered to the awaiter wrapped in
    # RemoteException; getRemoteClass() names the original class and
    # getRemoteException() returns the original Throwable.
    Given a coroutine "W"
     When coroutine "W" spawns <n> threads that throw
      And coroutine "W" awaits all threads inspecting remote exceptions
     Then counter "thr_inspect_attempts_W" equals <n>
      And counter "thr_remote_W" equals <n>
      And counter "thr_remote_class_ok_W" equals <n>
      And counter "thr_remote_exc_ok_W" equals <n>
      And counter "thr_inspect_other_W" equals 0
      And no orphan coroutines

    Examples:
      | n |
      | 1 |
      | 4 |
      | 8 |

  Scenario: value-returning and throwing threads from separate coroutines
    Given a coroutine "A"
      And a coroutine "B"
     When coroutine "A" spawns 6 threads returning their index
      And coroutine "B" spawns 6 threads that throw
      And coroutine "A" awaits all threads
      And coroutine "B" awaits all threads
     Then counter "thr_completed_A" plus counter "thr_failed_A" equals counter "thr_spawned_A"
      And counter "thr_completed_B" plus counter "thr_failed_B" equals counter "thr_spawned_B"
      And no orphan coroutines
