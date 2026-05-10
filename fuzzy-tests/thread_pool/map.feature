Feature: ThreadPool::map distributes work and preserves order

  map(items, callable) runs callable across the pool's workers and returns
  results in the same order as the input. Length must equal the input
  length; the call is synchronous from the caller's perspective.

  Invariants:
    tp_map_attempts_P == 1
    tp_map_succeeded_P == 1
    tp_map_results_P == N

  Scenario Outline: map N items via <workers> workers
    Given a thread pool "P" with <workers> workers
      And a coroutine "A"
     When coroutine "A" maps <n> items via pool "P"
     Then counter "tp_map_attempts_P" equals 1
      And counter "tp_map_succeeded_P" equals 1
      And counter "tp_map_results_P" equals <n>
      And no orphan coroutines

    Examples:
      | workers | n  |
      | 1       | 1  |
      | 2       | 5  |
      | 4       | 10 |
      | 4       | 20 |
