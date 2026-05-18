Feature: TaskGroup::cancel stops every task and closes the group

  Calling cancel() on a TaskGroup must:
    - implicitly close the group (no further spawns)
    - cancel running coroutines
    - leave queued tasks unstarted

  Invariants under chaos scheduling:
    tg_cancel_attempts_G == 1
    tg_cancelled_G == 1
    tg_done_G + tg_active_G(at end) <= tg_spawned_G   (some may not have run)
    group is closed after cancel

  Scenario: spawn N then cancel; group ends closed and not all tasks run
    Given a task group "G"
      And a coroutine "S"
      And a coroutine "A"
     When coroutine "S" spawns 5 tasks into "G" that print "t"
      And coroutine "A" cancels group "G"
      And coroutine "A" awaits completion of "G"
     Then counter "tg_cancel_attempts_G" equals 1
      And counter "tg_cancelled_G" equals 1
      And group "G" is closed
      And no orphan coroutines

  Scenario: cancel an empty group is a no-op
    Given a task group "G"
      And a coroutine "A"
     When coroutine "A" cancels group "G"
      And coroutine "A" awaits completion of "G"
     Then counter "tg_cancelled_G" equals 1
      And group "G" is closed
      And no orphan coroutines
