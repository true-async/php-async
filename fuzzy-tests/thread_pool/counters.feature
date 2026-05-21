Feature: ThreadPool live counters under chaos scheduler

  ThreadPool exposes four counters:

    getWorkerCount()    — number of OS worker threads (fixed at construction)
    getPendingCount()   — tasks queued, not yet picked up by a worker
    getRunningCount()   — tasks currently executing on a worker
    getCompletedCount() — tasks finished (success or failure)

  Each is a non-negative int at every instant. Submit and await live in one
  coroutine, so the inspection runs after every Future has resolved — the
  pool is then drained:

    getWorkerCount   == configured workers
    getPendingCount  == 0
    getRunningCount  == 0
    getCompletedCount == number of submitted tasks

  Scenario Outline: counters reach a drained snapshot after awaiting all
    Given a thread pool "P" with <workers> workers
      And a coroutine "A"
     When coroutine "A" submits <n> tasks to pool "P"
      And coroutine "A" awaits all submissions to pool "P"
      And coroutine "A" inspects counters of pool "P"
     Then counter "tp_counters_attempts_P" equals 1
      And counter "tp_counters_ok_P" equals 1
      And counter "tp_counters_bad_P" equals 0
      And counter "tp_seen_pending_P" equals 0
      And counter "tp_seen_running_P" equals 0
      And counter "tp_seen_completed_P" equals <n>
      And counter "tp_seen_workers_P" equals <workers>
      And no orphan coroutines

    Examples:
      | workers | n  |
      | 1       | 1  |
      | 2       | 4  |
      | 4       | 8  |
      | 4       | 16 |

  Scenario: an idle pool reports zero pending/running and full worker count
    Given a thread pool "P" with 3 workers
      And a coroutine "A"
     When coroutine "A" inspects counters of pool "P"
     Then counter "tp_counters_ok_P" equals 1
      And counter "tp_counters_bad_P" equals 0
      And counter "tp_seen_pending_P" equals 0
      And counter "tp_seen_running_P" equals 0
      And counter "tp_seen_completed_P" equals 0
      And counter "tp_seen_workers_P" equals 3
      And no orphan coroutines
