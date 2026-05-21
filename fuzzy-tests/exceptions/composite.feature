Feature: CompositeException accumulation under chaos scheduler

  CompositeException collects several Throwables behind one exception:

    addException(Throwable)  — append one
    getExceptions()          — return every accumulated Throwable

  Scenario Outline: addException accumulates, getExceptions returns all
    Given a coroutine "C"
     When coroutine "C" builds a composite exception with <n> parts
     Then counter "composite_attempts" equals 1
      And counter "composite_ok" equals 1
      And counter "composite_bad" equals 0
      And counter "composite_count" equals <n>
      And no orphan coroutines

    Examples:
      | n |
      | 1 |
      | 2 |
      | 5 |

  Scenario: several coroutines build composites independently
    Given a coroutine "A"
      And a coroutine "B"
      And a coroutine "C"
     When coroutine "A" builds a composite exception with 3 parts
      And coroutine "B" builds a composite exception with 3 parts
      And coroutine "C" builds a composite exception with 3 parts
     Then counter "composite_attempts" equals 3
      And counter "composite_ok" equals 3
      And counter "composite_bad" equals 0
      And counter "composite_count" equals 9
      And no orphan coroutines
