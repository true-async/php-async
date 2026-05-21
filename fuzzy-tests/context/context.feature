Feature: Context get/set/has/find/unset under chaos scheduler

  Async\Context is a key/value store with two layers:

    coroutine_context()  — per-coroutine, isolated from every sibling
    current_context()    — the scope context; child scopes inherit it

  Accessors come in inheriting and local-only forms:

    get / find / has        — walk the scope chain (current + parents)
    getLocal / findLocal /  — consult only the local layer
      hasLocal
    set(key, value, replace) — write; replace=false throws AsyncException
                               if the key already exists locally
    unset(key)              — delete the local entry

  Under the random scheduler coroutines mutate and read their contexts
  interleaved. The invariants below hold for EVERY interleaving:

    - per-coroutine isolation: coroutine_context() never leaks across
      coroutines (iso_bad == 0)
    - inheritance is one-way: a child sees a seeded parent value via
      get/find/has, never via *Local (inherit_miss == 0, local_present == 0)
    - a local set() shadows the parent for that key (override_bad == 0)
    - replace=false collision throws; unset removes (crud_bad == 0)
    - distinct keys written into one shared scope context all round-trip
      (shared_bad == 0)

  Seeded scope values are written in a synchronous prep-phase before any
  user coroutine runs, so inheritance reads never race the writer.

  Scenario: per-coroutine context isolation — three independent owners
    Given a coroutine "A"
      And a coroutine "B"
      And a coroutine "C"
     When coroutine "A" sets coroutine-context "v" to "alpha"
      And coroutine "B" sets coroutine-context "v" to "bravo"
      And coroutine "C" sets coroutine-context "v" to "charlie"
      And coroutine "A" verifies coroutine-context "v" is "alpha"
      And coroutine "B" verifies coroutine-context "v" is "bravo"
      And coroutine "C" verifies coroutine-context "v" is "charlie"
     Then counter "iso_attempts_A" equals 1
      And counter "iso_ok_A" equals 1
      And counter "iso_bad_A" equals 0
      And counter "iso_ok_B" equals 1
      And counter "iso_bad_B" equals 0
      And counter "iso_ok_C" equals 1
      And counter "iso_bad_C" equals 0
      And no orphan coroutines

  Scenario: inheritance — child-scope readers see the seeded parent value
    Given a scope "P"
      And scope "P" seeded with context "shared" = "from_parent"
      And a child scope "C" of "P"
      And a coroutine "R1" in scope "C"
      And a coroutine "R2" in scope "C"
     When coroutine "R1" reads inherited context "shared" expecting "from_parent"
      And coroutine "R2" reads inherited context "shared" expecting "from_parent"
     Then counter "inherit_attempts_R1" equals 1
      And counter "inherit_hit_R1" equals 1
      And counter "inherit_miss_R1" equals 0
      And counter "local_absent_R1" equals 1
      And counter "local_present_R1" equals 0
      And counter "inherit_hit_R2" equals 1
      And counter "inherit_miss_R2" equals 0
      And counter "local_absent_R2" equals 1
      And counter "local_present_R2" equals 0
      And no orphan coroutines

  Scenario: child overrides an inherited key with a local value
    Given a scope "P"
      And scope "P" seeded with context "color" = "parent_red"
      And a child scope "C" of "P"
      And a coroutine "O" in scope "C"
     When coroutine "O" overrides context "color" with local "child_blue"
     Then counter "override_attempts_O" equals 1
      And counter "override_ok_O" equals 1
      And counter "override_bad_O" equals 0
      And no orphan coroutines

  Scenario: a reader and an overrider in sibling child scopes do not interfere
    Given a scope "P"
      And scope "P" seeded with context "shared" = "from_parent"
      And a child scope "C1" of "P"
      And a child scope "C2" of "P"
      And a coroutine "R" in scope "C1"
      And a coroutine "O" in scope "C2"
     When coroutine "O" overrides context "shared" with local "from_child"
      And coroutine "R" reads inherited context "shared" expecting "from_parent"
     Then counter "override_ok_O" equals 1
      And counter "override_bad_O" equals 0
      And counter "inherit_hit_R" equals 1
      And counter "inherit_miss_R" equals 0
      And counter "local_present_R" equals 0
      And no orphan coroutines

  Scenario: single-coroutine replace-collision and unset
    Given a coroutine "X"
     When coroutine "X" exercises context replace and unset on "k"
     Then counter "crud_attempts_X" equals 1
      And counter "crud_ok_X" equals 1
      And counter "crud_bad_X" equals 0
      And no orphan coroutines

  Scenario: many coroutines exercise replace/unset on isolated contexts
    Given a coroutine "X1"
      And a coroutine "X2"
      And a coroutine "X3"
     When coroutine "X1" exercises context replace and unset on "k"
      And coroutine "X2" exercises context replace and unset on "k"
      And coroutine "X3" exercises context replace and unset on "k"
     Then counter "crud_ok_X1" equals 1
      And counter "crud_bad_X1" equals 0
      And counter "crud_ok_X2" equals 1
      And counter "crud_bad_X2" equals 0
      And counter "crud_ok_X3" equals 1
      And counter "crud_bad_X3" equals 0
      And no orphan coroutines

  Scenario: concurrent writers on one shared scope context — distinct keys
    Given a scope "S"
      And a coroutine "W1" in scope "S"
      And a coroutine "W2" in scope "S"
      And a coroutine "W3" in scope "S"
      And a coroutine "W4" in scope "S"
     When coroutine "W1" writes shared context "key_1" value "one"
      And coroutine "W2" writes shared context "key_2" value "two"
      And coroutine "W3" writes shared context "key_3" value "three"
      And coroutine "W4" writes shared context "key_4" value "four"
     Then counter "shared_attempts_W1" equals 1
      And counter "shared_ok_W1" equals 1
      And counter "shared_bad_W1" equals 0
      And counter "shared_ok_W2" equals 1
      And counter "shared_bad_W2" equals 0
      And counter "shared_ok_W3" equals 1
      And counter "shared_bad_W3" equals 0
      And counter "shared_ok_W4" equals 1
      And counter "shared_bad_W4" equals 0
      And no orphan coroutines

  Scenario Outline: inherited value round-trips for varied payloads
    Given a scope "P"
      And scope "P" seeded with context "k" = "<val>"
      And a child scope "C" of "P"
      And a coroutine "R" in scope "C"
     When coroutine "R" reads inherited context "k" expecting "<val>"
     Then counter "inherit_hit_R" equals 1
      And counter "inherit_miss_R" equals 0
      And counter "local_absent_R" equals 1
      And counter "local_present_R" equals 0
      And no orphan coroutines

    Examples:
      | val         |
      | x           |
      | hello       |
      | 12345       |
      | with spaces |
