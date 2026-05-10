Feature: TaskGroup respects concurrency limit

  When concurrency=K, at most K coroutines may run simultaneously inside the
  group; the rest wait in the queue. The chaos scheduler must not be able to
  push more than K tasks past the entry counter.

  Invariants:
    tg_max_active_G <= K
    tg_spawned_G + tg_spawn_failed_G == N  (every spawn either succeeded or
        was rejected when the seal raced ahead — both are legal under chaos)
    tg_done_G == tg_spawned_G               (every queued task eventually runs)

  Scenario Outline: concurrency K over N tasks
    Given a task group "G" with concurrency <k>
      And a coroutine "S"
      And a coroutine "A"
     When coroutine "S" spawns <n> tasks into "G" that print "t"
      And coroutine "A" seals group "G"
      And coroutine "A" awaits all of "G"
     Then counter "tg_spawned_G" plus counter "tg_spawn_failed_G" equals <n>
      And counter "tg_done_G" equals counter "tg_spawned_G"
      And counter "tg_max_active_G" is at most <k>
      And group "G" is finished
      And no orphan coroutines

    Examples:
      | k | n  |
      | 1 | 3  |
      | 2 | 5  |
      | 3 | 10 |
      | 4 | 4  |
