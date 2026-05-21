Feature: TaskSet joinAll / joinNext / joinAny under chaos scheduler

  TaskSet is a mutable task collection with auto-cleanup: a task entry is
  removed once its result has been consumed via joinNext/joinAny/joinAll.

    joinAll(ignoreErrors) — Future of every result; set drains to empty.
                            The set must be closed first.
    joinNext()            — Future of the first settled task (success or
                            error); that one entry is removed.
    joinAny()             — Future of the first SUCCESSFUL task, skipping
                            errors. If every task fails it rejects with
                            CompositeException.

  A single manager coroutine drives the lifecycle, so the join calls are
  sequenced; the chaos is in the tasks, each of which suspends. Invariants
  hold for every interleaving:

    joinAll  — results count == spawned count; set empty afterwards
    joinNext — ok + err == number of joinNext calls
    joinAny  — exactly one success, or a CompositeException of all errors

  Scenario: joinAll drains a closed set
    Given a task set "T"
      And a coroutine "M"
     When coroutine "M" spawns 4 tasks into set "T"
      And coroutine "M" closes set "T"
      And coroutine "M" joins all of set "T"
     Then counter "ts_spawned_T" equals 4
      And counter "ts_done_T" equals 4
      And counter "ts_joinall_succeeded_T" equals 1
      And counter "ts_joinall_results_T" equals 4
      And counter "ts_joinall_failed_T" equals 0
      And set "T" count equals 0
      And set "T" is finished
      And no orphan coroutines

  Scenario: joinNext delivers every task one at a time
    Given a task set "T"
      And a coroutine "M"
     When coroutine "M" spawns 3 tasks into set "T"
      And coroutine "M" joins 3 times from set "T"
     Then counter "ts_joinnext_attempts_T" equals 3
      And counter "ts_joinnext_ok_T" equals 3
      And counter "ts_joinnext_err_T" equals 0
      And set "T" count equals 0
      And no orphan coroutines

  Scenario: joinNext surfaces task errors
    Given a task set "T"
      And a coroutine "M"
     When coroutine "M" spawns 2 tasks into set "T"
      And coroutine "M" spawns 2 failing tasks into set "T"
      And coroutine "M" joins 4 times from set "T"
     Then counter "ts_joinnext_attempts_T" equals 4
      And counter "ts_joinnext_ok_T" equals 2
      And counter "ts_joinnext_err_T" equals 2
      And counter "ts_joinnext_ok_T" plus counter "ts_joinnext_err_T" equals 4
      And set "T" count equals 0
      And no orphan coroutines

  Scenario: joinAny returns the first successful task
    Given a task set "T"
      And a coroutine "M"
     When coroutine "M" spawns 3 tasks into set "T"
      And coroutine "M" joins any from set "T"
     Then counter "ts_joinany_attempts_T" equals 1
      And counter "ts_joinany_succeeded_T" equals 1
      And counter "ts_joinany_composite_T" equals 0
      And counter "ts_joinany_failed_T" equals 0
      And no orphan coroutines

  Scenario: joinAny skips a failure and still finds a success
    Given a task set "T"
      And a coroutine "M"
     When coroutine "M" spawns 1 failing tasks into set "T"
      And coroutine "M" spawns 2 tasks into set "T"
      And coroutine "M" joins any from set "T"
     Then counter "ts_joinany_succeeded_T" equals 1
      And counter "ts_joinany_composite_T" equals 0
      And counter "ts_joinany_failed_T" equals 0
      And no orphan coroutines

  Scenario: joinAny rejects with CompositeException when every task fails
    Given a task set "T"
      And a coroutine "M"
     When coroutine "M" spawns 3 failing tasks into set "T"
      And coroutine "M" closes set "T"
      And coroutine "M" joins any from set "T"
     Then counter "ts_joinany_attempts_T" equals 1
      And counter "ts_joinany_succeeded_T" equals 0
      And counter "ts_joinany_composite_T" equals 1
      And counter "ts_joinany_composite_count_T" equals 3
      And counter "ts_joinany_failed_T" equals 0
      And no orphan coroutines

  Scenario Outline: joinAll result count tracks the task count
    Given a task set "T"
      And a coroutine "M"
     When coroutine "M" spawns <n> tasks into set "T"
      And coroutine "M" closes set "T"
      And coroutine "M" joins all of set "T"
     Then counter "ts_joinall_results_T" equals <n>
      And counter "ts_done_T" equals <n>
      And set "T" count equals 0
      And set "T" is finished
      And no orphan coroutines

    Examples:
      | n |
      | 1 |
      | 2 |
      | 7 |
