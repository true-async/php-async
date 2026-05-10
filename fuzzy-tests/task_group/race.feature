Feature: TaskGroup::race returns the first task to settle

  race() resolves with the first task to finish (success or error). Remaining
  tasks continue running in the background; the awaiter must not block
  waiting for them.

  Invariants:
    tg_race_attempts_G == 1
    tg_race_succeeded_G + tg_race_failed_G == 1
    no orphan coroutines after the group is sealed and drained

  Scenario Outline: N tasks, awaiter sees one of them via race()
    Given a task group "G"
      And a coroutine "S"
      And a coroutine "A"
     When coroutine "S" spawns <n> tasks into "G" that print "t"
      And coroutine "A" awaits race of "G"
      And coroutine "A" seals group "G"
      And coroutine "A" awaits all of "G"
     Then counter "tg_race_attempts_G" equals 1
      And counter "tg_race_succeeded_G" plus counter "tg_race_failed_G" equals 1
      And counter "tg_done_G" equals <n>
      And group "G" is finished
      And no orphan coroutines

    Examples:
      | n |
      | 1 |
      | 3 |
      | 5 |
