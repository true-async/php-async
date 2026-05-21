Feature: Coroutine introspection accessors under chaos scheduler

  Coroutine exposes read-only introspection accessors:

    getSpawnFileAndLine()   — [file, line] of the spawn() call site
    getSpawnLocation()      — same as a "file:line" string
    getSuspendFileAndLine() — [file, line] of the last suspend point
    getSuspendLocation()    — same as a string
    getAwaitingInfo()       — array describing what the coroutine awaits
    getContext()            — the coroutine's Async\Context
    isQueued()              — true while enqueued but not yet running
    asHiPriority()          — marks hi-priority, returns the SAME Coroutine

  Spawn location is fixed at creation: it stays a well-formed [file,int]
  pair / "file:line" string for every interleaving and every lifecycle
  phase. Suspend location, awaiting info and the queued flag are sampled
  at one instant — under random scheduling the inspector may land before
  the target starts, while it sleeps, or after it finishes — so we assert
  only that each sample is well-typed (a sum/union invariant), never a
  specific value.

  Invariants for every interleaving:
    <facet>_ok == <facet>_attempts          (stable accessors)
    <facet>_bad == 0                        (never a malformed result)
    queued_true + queued_false == attempts  (isQueued is a strict bool)
    ctx_ok + ctx_null == attempts           (getContext: Context or null)
    hipri_identity_ok == attempts           (asHiPriority returns self)

  Scenario: full-facet inspection of a sleeping coroutine
    Given a coroutine "T"
      And a coroutine "I"
     When coroutine "T" sleeps 50 ms
      And coroutine "I" inspects spawn location of coroutine "T"
      And coroutine "I" inspects suspend location of coroutine "T"
      And coroutine "I" inspects awaiting info of coroutine "T"
      And coroutine "I" inspects queued state of coroutine "T"
      And coroutine "I" inspects context of coroutine "T"
      And coroutine "I" raises priority of coroutine "T"
     Then counter "spawn_loc_attempts_T" equals 1
      And counter "spawn_loc_ok_T" equals 1
      And counter "spawn_loc_bad_T" equals 0
      And counter "suspend_loc_attempts_T" equals 1
      And counter "suspend_loc_ok_T" equals 1
      And counter "suspend_loc_bad_T" equals 0
      And counter "await_info_attempts_T" equals 1
      And counter "await_info_array_T" equals 1
      And counter "await_info_bad_T" equals 0
      And counter "queued_attempts_T" equals 1
      And counter "queued_true_T" plus counter "queued_false_T" equals 1
      And counter "queued_bad_T" equals 0
      And counter "ctx_attempts_T" equals 1
      And counter "ctx_ok_T" plus counter "ctx_null_T" equals 1
      And counter "ctx_bad_T" equals 0
      And counter "hipri_attempts_T" equals 1
      And counter "hipri_identity_ok_T" equals 1
      And counter "hipri_identity_bad_T" equals 0
      And coroutine "T" is completed
      And coroutine "T" has a well-formed spawn location
      And coroutine "T" awaiting info is an array
      And coroutine "T" context is a Context
      And no orphan coroutines

  Scenario: many inspectors race a sleeper — spawn location is stable
    Given a coroutine "T"
      And a coroutine "I1"
      And a coroutine "I2"
      And a coroutine "I3"
      And a coroutine "I4"
     When coroutine "T" sleeps 50 ms
      And coroutine "I1" inspects spawn location of coroutine "T"
      And coroutine "I2" inspects spawn location of coroutine "T"
      And coroutine "I3" inspects spawn location of coroutine "T"
      And coroutine "I4" inspects spawn location of coroutine "T"
     Then counter "spawn_loc_attempts_T" equals 4
      And counter "spawn_loc_ok_T" equals 4
      And counter "spawn_loc_bad_T" equals 0
      And coroutine "T" has a well-formed spawn location
      And no orphan coroutines

  Scenario: inspect a quickly-returning coroutine
    Given a coroutine "T"
      And a coroutine "I"
     When coroutine "T" prints "done"
      And coroutine "I" inspects suspend location of coroutine "T"
      And coroutine "I" inspects awaiting info of coroutine "T"
      And coroutine "I" inspects context of coroutine "T"
     Then counter "suspend_loc_attempts_T" equals 1
      And counter "suspend_loc_ok_T" equals 1
      And counter "suspend_loc_bad_T" equals 0
      And counter "await_info_array_T" equals 1
      And counter "await_info_bad_T" equals 0
      And counter "ctx_ok_T" plus counter "ctx_null_T" equals 1
      And counter "ctx_bad_T" equals 0
      And coroutine "T" is completed
      And coroutine "T" awaiting info is an array
      And no orphan coroutines

  Scenario: inspect a coroutine racing a cancellation
    Given a coroutine "T"
      And a coroutine "I"
      And a coroutine "C"
     When coroutine "T" sleeps 100 ms
      And coroutine "I" inspects awaiting info of coroutine "T"
      And coroutine "I" inspects spawn location of coroutine "T"
      And coroutine "C" cancels coroutine "T"
     Then counter "await_info_attempts_T" equals 1
      And counter "await_info_bad_T" equals 0
      And counter "spawn_loc_ok_T" equals 1
      And counter "spawn_loc_bad_T" equals 0
      And coroutine "T" is completed
      And coroutine "T" is cancelled
      And coroutine "T" has a well-formed spawn location
      And no orphan coroutines

  Scenario: asHiPriority identity holds across many callers
    Given a coroutine "T"
      And a coroutine "I1"
      And a coroutine "I2"
      And a coroutine "I3"
     When coroutine "T" sleeps 20 ms
      And coroutine "I1" raises priority of coroutine "T"
      And coroutine "I2" raises priority of coroutine "T"
      And coroutine "I3" raises priority of coroutine "T"
     Then counter "hipri_attempts_T" equals 3
      And counter "hipri_identity_ok_T" equals 3
      And counter "hipri_identity_bad_T" equals 0
      And coroutine "T" is completed
      And no orphan coroutines

  Scenario Outline: queued-state sampling across varying sleep lengths
    Given a coroutine "T"
      And a coroutine "I"
     When coroutine "T" sleeps <ms> ms
      And coroutine "I" inspects queued state of coroutine "T"
      And coroutine "I" inspects suspend location of coroutine "T"
     Then counter "queued_attempts_T" equals 1
      And counter "queued_true_T" plus counter "queued_false_T" equals 1
      And counter "queued_bad_T" equals 0
      And counter "suspend_loc_ok_T" equals 1
      And counter "suspend_loc_bad_T" equals 0
      And coroutine "T" is completed
      And coroutine "T" has a well-formed spawn location
      And no orphan coroutines

    Examples:
      | ms  |
      | 0   |
      | 5   |
      | 50  |
      | 200 |
