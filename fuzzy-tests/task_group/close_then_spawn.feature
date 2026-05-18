Feature: spawning into a closed TaskGroup is rejected

  After close() the group accepts no further tasks; spawn() must throw and
  the chaos invariants count the failure.

  Invariants:
    tg_closed_G == 1
    tg_spawned_G + tg_spawn_failed_G == total attempts
    group is closed and finished after the runs complete

  Scenario: spawn 3, close, attempt 2 more — second batch fails
    # S1 spawns then closes in the same coroutine (sequential within a body),
    # so the close definitely lands AFTER its three spawns. S2 starts after
    # the close and every one of its spawns must raise.
    Given a task group "G"
      And a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "A"
     When coroutine "S1" spawns 3 tasks into "G" that print "t"
      And coroutine "S1" closes group "G"
      And coroutine "S2" spawns 2 tasks into "G" that print "t"
      And coroutine "A" awaits all of "G"
     Then counter "tg_closed_G" equals 1
      And counter "tg_spawned_G" plus counter "tg_spawn_failed_G" equals 5
      And counter "tg_done_G" equals counter "tg_spawned_G"
      And group "G" is finished
      And no orphan coroutines
