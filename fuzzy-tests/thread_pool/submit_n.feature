Feature: ThreadPool::submit completes every task

  Submit N self-contained tasks; await every Future. Tasks return their
  index — the test runs N tasks across the pool's workers and verifies that
  every Future resolves successfully.

  Invariants:
    tp_submitted_P + tp_submit_failed_P == N
    tp_completed_P == tp_submitted_P
    tp_await_succeeded_P == 1

  Scenario Outline: <workers> workers, <n> tasks
    # Submit + await live in the same coroutine to make sure the await sees
    # every future in the harness's $threadPoolFutures slot. Using two
    # separate coroutines races: A may run after S has submitted only K of
    # the N tasks (when the queue back-pressures S past slot K).
    Given a thread pool "P" with <workers> workers
      And a coroutine "A"
     When coroutine "A" submits <n> tasks to pool "P"
      And coroutine "A" awaits all submissions to pool "P"
     Then counter "tp_submitted_P" plus counter "tp_submit_failed_P" equals <n>
      And counter "tp_completed_P" plus counter "tp_failed_P" equals counter "tp_submitted_P"
      And counter "tp_await_succeeded_P" equals 1
      And no orphan coroutines

    Examples:
      | workers | n  |
      | 1       | 1  |
      | 2       | 4  |
      | 4       | 8  |
      | 4       | 20 |
