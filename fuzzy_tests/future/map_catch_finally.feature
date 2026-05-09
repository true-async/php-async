Feature: Future::map / catch / finally chaining

  Chained transformations on a Future:
    map(fn)     — runs only when source completes successfully
    catch(fn)   — runs only when source errors, swallows the exception
    finally(fn) — runs in both cases

  Invariants in every interleaving:
    success path: map_K == 1, catch_K == 0, finally_K == 1
    error   path: map_K == 0, catch_K == 1, finally_K == 1

  Scenario: success path runs map and finally, not catch
    Given a future "F"
      And a coroutine "P"
      And a coroutine "Cm"
      And a coroutine "Cc"
      And a coroutine "Cf"
     When coroutine "P" completes future "F" with 7
      And coroutine "Cm" maps future "F" to counter "K"
      And coroutine "Cc" catches future "F" to counter "K"
      And coroutine "Cf" finallies future "F" to counter "K"
     Then counter "completed_F" equals 1
      And counter "map_K" equals 1
      And counter "catch_K" equals 0
      And counter "finally_K" equals 1
      And no orphan coroutines

  Scenario: error path runs catch and finally, not map
    Given a future "F"
      And a coroutine "P"
      And a coroutine "Cm"
      And a coroutine "Cc"
      And a coroutine "Cf"
     When coroutine "P" fails future "F" with "boom"
      And coroutine "Cm" maps future "F" to counter "K"
      And coroutine "Cc" catches future "F" to counter "K"
      And coroutine "Cf" finallies future "F" to counter "K"
     Then counter "errored_F" equals 1
      And counter "map_K" equals 0
      And counter "catch_K" equals 1
      And counter "finally_K" equals 1
      And no orphan coroutines
