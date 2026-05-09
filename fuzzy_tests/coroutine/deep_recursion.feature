Feature: Deep recursive coroutine spawning

  Each level spawns a child coroutine and awaits it before returning. The
  test asserts that arbitrary depth is handled without stack overflow,
  uncaught cancellation, or orphaned coroutines.

  Invariants in every interleaving:
    rec_depth == depth + 1   (initial coroutine + N descendants)
    no orphan coroutines

  Scenario Outline: spawn-and-await chain to depth N
    Given a coroutine "Driver"
     When coroutine "Driver" recursively spawns to depth <depth>
     Then counter "rec_depth" equals <expected>
      And no orphan coroutines

    Examples:
      | depth | expected |
      | 0     | 1        |
      | 1     | 2        |
      | 5     | 6        |
      | 16    | 17       |
      | 32    | 33       |
