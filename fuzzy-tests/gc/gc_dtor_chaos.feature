Feature: GC destructor + suspend/spawn chaos

  When a destructor runs from inside zend_gc's sweep, calling
  Async\suspend() or Async\spawn() re-enters ext/async's scheduler from
  an unusual stack — exactly the path tests/gc/001-014 pin
  deterministically. This feature crosses those into chaos by varying
  object count, cycle topology, and overlaying killer-cancel pressure on
  the host coroutine while GC is running.

  Liveness / safety invariants (must hold under ANY interleaving):
    gc_obj_created_X >= gc_dtor_suspends_X      (a dtor cannot run
                                                  for an object that
                                                  was never created)
    gc_dtor_spawn_child_finished_X
        + gc_dtor_spawn_child_cancelled_X       (every spawned child
        equals counter gc_dtor_spawns_X         (settles)
    no orphan coroutines

  Scenario Outline: N objects, each destructor suspends once
    Given a coroutine "G"
     When coroutine "G" creates and unsets <n> objects with suspending destructor
     Then counter "gc_obj_created_G" is at most <n>
      And counter "gc_dtor_threw_G" equals 0
      And no orphan coroutines

    Examples:
      | n  |
      | 1  |
      | 3  |
      | 8  |
      | 20 |

  Scenario Outline: N objects, destructors each spawn a child coroutine
    # Mirrors tests/gc/002 — the dtor reaches into the scheduler from
    # inside the GC sweep. Every spawned child must settle before the
    # host coroutine returns or the no-orphans invariant fails.
    Given a coroutine "G"
     When coroutine "G" creates and unsets <n> objects whose destructor spawns a coroutine
     Then counter "gc_obj_created_G" is at most <n>
      And counter "gc_dtor_spawn_child_finished_G" plus counter "gc_dtor_spawn_child_cancelled_G" equals counter "gc_dtor_spawns_G"
      And counter "gc_dtor_threw_G" equals 0
      And no orphan coroutines

    Examples:
      | n |
      | 1 |
      | 3 |
      | 6 |

  Scenario Outline: N reference cycles — gc_collect_cycles must dispatch every dtor
    # Each pair (A↔B) is a closed cycle reachable only from the cycle
    # collector. zend_gc + ext/async must dispatch both destructors and
    # let each suspend without re-entrancy corruption.
    Given a coroutine "G"
     When coroutine "G" creates and unsets <n> reference cycles with suspending destructor
     Then counter "gc_obj_created_G" equals <doubled>
      And counter "gc_dtor_threw_G" equals 0
      And no orphan coroutines

    Examples:
      | n | doubled |
      | 1 | 2       |
      | 3 | 6       |
      | 5 | 10      |

  Scenario Outline: cancel the host coroutine while its destructors are sweeping
    # Killer cancels the host before/during the gc_collect_cycles() call.
    # The cancel must not corrupt the dtor sweep — a dtor that has
    # already started either runs to completion or is recorded as threw.
    Given a coroutine "G"
      And a coroutine "K"
     When coroutine "G" creates and unsets <n> objects whose destructor spawns a coroutine
      And coroutine "K" sleeps <delay> ms
      And coroutine "K" cancels coroutine "G"
     Then counter "gc_obj_created_G" is at most <n>
      And counter "gc_dtor_spawn_child_finished_G" plus counter "gc_dtor_spawn_child_cancelled_G" equals counter "gc_dtor_spawns_G"
      And no orphan coroutines

    Examples:
      | n | delay |
      | 3 | 0     |
      | 6 | 1     |
      | 8 | 5     |

  Scenario: two coroutines run dtor storms concurrently
    Given a coroutine "A"
      And a coroutine "B"
     When coroutine "A" creates and unsets 5 objects with suspending destructor
      And coroutine "B" creates and unsets 5 reference cycles with suspending destructor
     Then counter "gc_obj_created_A" is at most 5
      And counter "gc_obj_created_B" is at most 10
      And counter "gc_dtor_threw_A" equals 0
      And counter "gc_dtor_threw_B" equals 0
      And no orphan coroutines
