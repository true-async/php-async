Feature: TaskGroup::dispose triggers cancellation of the group's scope

  dispose() drops the group's scope, cancelling every running coroutine.
  Pending tasks must not silently leak.

  Invariants:
    tg_dispose_attempts_G == 1
    tg_disposed_G == 1
    no orphan coroutines

  Scenario: dispose with running tasks cancels them all
    Given a task group "G"
      And a coroutine "S"
      And a coroutine "A"
     When coroutine "S" spawns 4 tasks into "G" that print "t"
      And coroutine "A" disposes group "G"
      And coroutine "A" awaits completion of "G"
     Then counter "tg_dispose_attempts_G" equals 1
      And counter "tg_disposed_G" equals 1

  Scenario: dispose an empty group
    Given a task group "G"
      And a coroutine "A"
     When coroutine "A" disposes group "G"
      And coroutine "A" awaits completion of "G"
     Then counter "tg_disposed_G" equals 1
