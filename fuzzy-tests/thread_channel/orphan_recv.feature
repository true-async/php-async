Feature: ThreadChannel orphan recv workers disconnect at shutdown (#162)

  A worker started with Async\spawn_thread() and parked on ThreadChannel::recv()
  must not keep the process alive when the owning side finishes without close().
  The owner here is a coroutine that spawns the workers, never sends, never
  closes, never awaits — its only channel reference drops when it returns. The
  process-wide registry close_all() at shutdown must wake every parked worker so
  the process exits cleanly.

  Invariants for every interleaving (and every worker count):
    every worker was spawned (no spawn failure)
    no orphan coroutines on the owner's side
    the process exits cleanly (a hang fails the run via timeout; a use-after-
    free in the shutdown disconnect fails it under ASAN)

  Scenario Outline: N workers park on recv, the owner finishes without close
    Given a coroutine "M"
     When coroutine "M" spawns <n> orphan workers parked on recv of a fresh thread channel
     Then counter "tc_orphan_spawned" equals <n>
      And counter "tc_orphan_spawn_failed" equals 0
      And no orphan coroutines

    Examples:
      | n  |
      | 1  |
      | 2  |
      | 4  |
      | 8  |
      | 16 |
