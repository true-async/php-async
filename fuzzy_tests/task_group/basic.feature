Feature: TaskGroup basic spawn + await

  TaskGroup is a task pool with optional concurrency limit. spawn() adds a
  callable; all() returns a Future resolving with every result.

  Invariants:
    every spawned task either runs to completion or is cancelled
    tg_done_G == tg_spawned_G  when no cancellation occurs
    group is finished after all() awaits

  Scenario Outline: spawn N tasks, await all, every result delivered
    Given a task group "G"
      And a coroutine "S"
      And a coroutine "A"
     When coroutine "S" spawns <n> tasks into "G" that print "t"
      And coroutine "A" seals group "G"
      And coroutine "A" awaits all of "G"
     Then counter "tg_spawned_G" equals <n>
      And counter "tg_done_G" equals <n>
      And counter "tg_await_all_succeeded_G" equals 1
      And counter "tg_await_all_results_G" equals <n>
      And group "G" is finished
      And no orphan coroutines

    Examples:
      | n  |
      | 1  |
      | 3  |
      | 10 |

  Scenario: empty group — sealing then awaiting all returns empty result
    Given a task group "G"
      And a coroutine "A"
     When coroutine "A" seals group "G"
      And coroutine "A" awaits all of "G"
     Then counter "tg_await_all_succeeded_G" equals 1
      And counter "tg_await_all_results_G" equals 0
      And group "G" is finished
      And no orphan coroutines
