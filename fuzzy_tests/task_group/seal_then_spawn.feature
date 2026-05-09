Feature: spawning into a sealed TaskGroup is rejected

  After seal() the group accepts no further tasks; spawn() must throw and
  the chaos invariants count the failure.

  Invariants:
    tg_sealed_G == 1
    tg_spawned_G + tg_spawn_failed_G == total attempts
    group is sealed and finished after the runs complete

  Scenario: spawn 3, seal, attempt 2 more — second batch fails
    # S1 spawns then seals in the same coroutine (sequential within a body),
    # so the seal definitely lands AFTER its three spawns. S2 starts after
    # the seal and every one of its spawns must raise.
    Given a task group "G"
      And a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "A"
     When coroutine "S1" spawns 3 tasks into "G" that print "t"
      And coroutine "S1" seals group "G"
      And coroutine "S2" spawns 2 tasks into "G" that print "t"
      And coroutine "A" awaits all of "G"
     Then counter "tg_sealed_G" equals 1
      And counter "tg_spawned_G" plus counter "tg_spawn_failed_G" equals 5
      And counter "tg_done_G" equals counter "tg_spawned_G"
      And group "G" is finished
      And no orphan coroutines
