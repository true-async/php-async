Feature: ThreadPool::cancel stops pending tasks

  cancel() rejects pending tasks with a ThreadPoolException and stops the
  workers. Already-running tasks may still finish — the runtime guarantees
  no leaks but does not guarantee every submitted Future resolves with a
  value.

  Invariants:
    tp_submitted_P + tp_submit_failed_P == N
    tp_completed_P + tp_failed_P == tp_submitted_P  (every Future settles)
    tp_cancelled_P == 1
    no orphan coroutines

  Scenario: submit then cancel; every Future settles cleanly
    Given a thread pool "P" with 2 workers
      And a coroutine "S"
      And a coroutine "X"
      And a coroutine "A"
     When coroutine "S" submits 8 tasks to pool "P"
      And coroutine "X" cancels pool "P"
      And coroutine "A" awaits all submissions to pool "P"
     Then counter "tp_cancelled_P" equals 1
      And counter "tp_submitted_P" plus counter "tp_submit_failed_P" equals 8
      And counter "tp_completed_P" plus counter "tp_failed_P" equals counter "tp_submitted_P"
      And no orphan coroutines
