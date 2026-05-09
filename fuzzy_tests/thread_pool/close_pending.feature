Feature: ThreadPool::close lets running tasks finish but rejects new ones

  close() rejects new submissions but lets queued/running tasks complete.
  Combined with the chaos scheduler this exercises the "close races submit"
  path that frequently shows up in real applications.

  Invariants:
    tp_submitted_P + tp_submit_failed_P == N
    tp_completed_P + tp_failed_P == tp_submitted_P
    tp_closed_P == 1
    pool is closed at the end

  Scenario Outline: submit N, close, submit M more (rejected), await all
    # S1 submits + awaits in the same coroutine to avoid the harness race
    # where the awaiter runs before all submits land.
    Given a thread pool "P" with <workers> workers
      And a coroutine "S1"
      And a coroutine "C"
      And a coroutine "S2"
     When coroutine "S1" submits <n> tasks to pool "P"
      And coroutine "C" closes pool "P"
      And coroutine "S2" submits <m> tasks to pool "P"
      And coroutine "S1" awaits all submissions to pool "P"
     Then counter "tp_closed_P" equals 1
      And counter "tp_submitted_P" plus counter "tp_submit_failed_P" equals <total>
      And no orphan coroutines

    Examples:
      | workers | n | m | total |
      | 2       | 4 | 2 | 6     |
      | 4       | 8 | 4 | 12    |
