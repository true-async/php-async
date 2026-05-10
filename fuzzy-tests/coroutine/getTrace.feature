Feature: Coroutine getTrace() under chaos scheduler

  Coroutine::getTrace() returns:
    - null if the coroutine is not currently suspended (not yet started,
      already finished, or running on the active stack)
    - a non-empty PHP backtrace array if the coroutine is suspended

  Under randomised scheduling the inspector coroutine and the target
  coroutine race; the inspector may observe the target before its body
  starts, while it is sleeping in delay(), or after it has finished.
  Each call to getTrace() therefore lands in exactly one of two buckets:
  array (suspended at that instant) or null (any other state). After
  Context::run() returns every coroutine has terminated and getTrace()
  must return null.

  Invariants for every interleaving:
    trace_inspect_attempts_T == trace_was_array_T + trace_was_null_T
    trace_was_other_T == 0
    coroutine T final trace is null

  Scenario: inspect a sleeping coroutine — sum invariant holds
    Given a coroutine "T"
      And a coroutine "I"
     When coroutine "T" sleeps 50 ms
      And coroutine "I" inspects trace of coroutine "T"
     Then counter "trace_inspect_attempts_T" equals 1
      And counter "trace_was_array_T" plus counter "trace_was_null_T" equals 1
      And counter "trace_was_other_T" equals 0
      And coroutine "T" final trace is null
      And no orphan coroutines

  Scenario: inspect a quickly-returning coroutine — typically null
    Given a coroutine "T"
      And a coroutine "I"
     When coroutine "T" prints "done"
      And coroutine "I" inspects trace of coroutine "T"
     Then counter "trace_inspect_attempts_T" equals 1
      And counter "trace_was_array_T" plus counter "trace_was_null_T" equals 1
      And counter "trace_was_other_T" equals 0
      And coroutine "T" final trace is null
      And no orphan coroutines

  Scenario: many inspectors on one sleeper
    Given a coroutine "T"
      And a coroutine "I1"
      And a coroutine "I2"
      And a coroutine "I3"
      And a coroutine "I4"
     When coroutine "T" sleeps 50 ms
      And coroutine "I1" inspects trace of coroutine "T"
      And coroutine "I2" inspects trace of coroutine "T"
      And coroutine "I3" inspects trace of coroutine "T"
      And coroutine "I4" inspects trace of coroutine "T"
     Then counter "trace_inspect_attempts_T" equals 4
      And counter "trace_was_array_T" plus counter "trace_was_null_T" equals 4
      And counter "trace_was_other_T" equals 0
      And coroutine "T" final trace is null
      And no orphan coroutines

  Scenario Outline: vary sleep length
    Given a coroutine "T"
      And a coroutine "I"
     When coroutine "T" sleeps <ms> ms
      And coroutine "I" inspects trace of coroutine "T"
     Then counter "trace_inspect_attempts_T" equals 1
      And counter "trace_was_array_T" plus counter "trace_was_null_T" equals 1
      And counter "trace_was_other_T" equals 0
      And coroutine "T" final trace is null
      And no orphan coroutines

    Examples:
      | ms  |
      | 0   |
      | 5   |
      | 50  |
      | 200 |

  Scenario: inspect a coroutine that throws — final trace is still null
    Given a scope "S"
      And scope "S" has an exception handler
      And a coroutine "T" in scope "S"
      And a coroutine "I"
     When coroutine "I" inspects trace of coroutine "T"
      And coroutine "T" throws
     Then counter "trace_inspect_attempts_T" equals 1
      And counter "trace_was_other_T" equals 0
      And coroutine "T" final trace is null
      And no orphan coroutines
