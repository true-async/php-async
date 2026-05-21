Feature: TaskGroup result accessors under chaos scheduler

  Beyond all()/race()/any(), a TaskGroup exposes:

    spawnWithKey(key, task) — spawn under an explicit result key;
                              a duplicate key throws AsyncException
    getResults()            — successful results, keyed by task key
    getErrors()             — Throwables of failed tasks; marks handled
    suppressErrors()        — mark all current errors handled
    getIterator()           — foreach yields key => [result, error]
                              as tasks settle; marks errors handled

  A single manager coroutine drives the group lifecycle (spawn → close →
  awaitCompletion → read), so the accessor calls are sequenced after the
  group has settled — their return values are then exact. The chaos lives
  in the tasks themselves: each suspends, so the scheduler interleaves
  them freely. The invariants below hold for every interleaving:

    getResults() count == number of successful tasks
    getErrors()  count == number of failed tasks
    getResults + getErrors partition the task set
    the iterator delivers every task exactly once (ok + error == total)
    spawnWithKey on a duplicate key always throws AsyncException

  Scenario: keyed tasks — getResults returns every result
    Given a task group "G"
      And a coroutine "M"
     When coroutine "M" spawns 4 keyed tasks into "G"
      And coroutine "M" closes group "G"
      And coroutine "M" awaits completion of "G"
      And coroutine "M" reads results of "G"
     Then counter "tg_kspawned_G" equals 4
      And counter "tg_kdone_G" equals 4
      And counter "tg_results_count_G" equals 4
      And counter "tg_results_bad_G" equals 0
      And group "G" is finished
      And no orphan coroutines

  Scenario: mixed success and failure — getResults / getErrors partition
    Given a task group "G"
      And a coroutine "M"
     When coroutine "M" spawns 3 keyed tasks into "G"
      And coroutine "M" spawns 2 failing tasks into "G"
      And coroutine "M" closes group "G"
      And coroutine "M" awaits completion of "G"
      And coroutine "M" reads results of "G"
      And coroutine "M" reads errors of "G"
     Then counter "tg_results_count_G" equals 3
      And counter "tg_errors_count_G" equals 2
      And counter "tg_errors_throwable_G" equals 2
      And counter "tg_results_count_G" plus counter "tg_errors_count_G" equals 5
      And group "G" is finished
      And no orphan coroutines

  Scenario: suppressErrors keeps a failing group quiet
    Given a task group "G"
      And a coroutine "M"
     When coroutine "M" spawns 3 failing tasks into "G"
      And coroutine "M" closes group "G"
      And coroutine "M" awaits completion of "G"
      And coroutine "M" suppresses errors of "G"
     Then counter "tg_fspawned_G" equals 3
      And counter "tg_fran_G" equals 3
      And counter "tg_suppressed_G" equals 1
      And counter "tg_suppress_failed_G" equals 0
      And group "G" is finished
      And no orphan coroutines

  Scenario: getIterator delivers every task outcome exactly once
    Given a task group "G"
      And a coroutine "M"
     When coroutine "M" spawns 3 keyed tasks into "G"
      And coroutine "M" spawns 2 failing tasks into "G"
      And coroutine "M" closes group "G"
      And coroutine "M" iterates "G" collecting outcomes
     Then counter "tg_iter_total_G" equals 5
      And counter "tg_iter_ok_G" equals 3
      And counter "tg_iter_error_G" equals 2
      And counter "tg_iter_ok_G" plus counter "tg_iter_error_G" equals 5
      And group "G" is finished
      And no orphan coroutines

  Scenario: spawnWithKey rejects a duplicate key
    Given a task group "G"
      And a coroutine "M"
     When coroutine "M" spawns a duplicate-key task into "G"
      And coroutine "M" closes group "G"
      And coroutine "M" awaits completion of "G"
     Then counter "tg_dupkey_first_ok_G" equals 1
      And counter "tg_dupkey_threw_G" equals 1
      And counter "tg_dupkey_other_throw_G" equals 0
      And group "G" is finished
      And no orphan coroutines

  Scenario: concurrency-limited group still delivers every keyed result
    Given a task group "G" with concurrency 2
      And a coroutine "M"
     When coroutine "M" spawns 6 keyed tasks into "G"
      And coroutine "M" closes group "G"
      And coroutine "M" awaits completion of "G"
      And coroutine "M" reads results of "G"
     Then counter "tg_kspawned_G" equals 6
      And counter "tg_kdone_G" equals 6
      And counter "tg_results_count_G" equals 6
      And group "G" is finished
      And no orphan coroutines

  Scenario: calling getIterator() directly always throws
    Given a task group "G"
      And a coroutine "M"
     When coroutine "M" spawns 2 keyed tasks into "G"
      And coroutine "M" calls getIterator on "G" directly
      And coroutine "M" closes group "G"
      And coroutine "M" iterates "G" collecting outcomes
     Then counter "tg_get_iterator_attempts_G" equals 1
      And counter "tg_get_iterator_threw_G" equals 1
      And counter "tg_get_iterator_no_throw_G" equals 0
      And counter "tg_get_iterator_other_throw_G" equals 0
      And counter "tg_iter_total_G" equals 2
      And group "G" is finished
      And no orphan coroutines

  Scenario Outline: getResults count tracks keyed-task count
    Given a task group "G"
      And a coroutine "M"
     When coroutine "M" spawns <n> keyed tasks into "G"
      And coroutine "M" closes group "G"
      And coroutine "M" awaits completion of "G"
      And coroutine "M" reads results of "G"
     Then counter "tg_results_count_G" equals <n>
      And counter "tg_kdone_G" equals <n>
      And counter "tg_results_bad_G" equals 0
      And group "G" is finished
      And no orphan coroutines

    Examples:
      | n |
      | 1 |
      | 3 |
      | 8 |
