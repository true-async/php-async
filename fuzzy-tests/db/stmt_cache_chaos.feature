Feature: DB chaos — PDO pool stmt cache LRU under concurrent prepare + cancel

  Backstop for the recent PDO::ATTR_POOL_STMT_CACHE_SIZE feature.
  Each pooled connection keeps its own LRU of prepared statements
  (HashTable insertion order; configured via the new attribute).
  Many coroutines preparing different SQL strings against the same
  shared pool drive cache fill + eviction; a cancel mid-prepare must
  not corrupt the LRU or leak a half-prepared statement handle, and
  the cache-evicted statement object must be freed cleanly. SQLite
  is used so the test runs without Toxiproxy / a network DB — the
  stmt cache itself lives in `ext/pdo/pdo_pool.c` and is driver-
  agnostic.

  Per-coroutine invariant: db_storm_ok + db_storm_cancelled +
  db_storm_failed == db_storm_attempts. Liveness: every coroutine
  terminates, no orphans.

  Scenario: cache-storm of N > cache capacity — drives LRU eviction
    Given a pooled SQLite database "DB" with 4 connections and stmt cache 4
      And a coroutine "S"
     When coroutine "S" runs cache-storm of 32 statements on database "DB"
     Then counter "db_storm_ok_S" plus counter "db_storm_cancelled_S" plus counter "db_storm_failed_S" equals counter "db_storm_attempts_S"
      And counter "db_storm_ok_S" is at least 1
      And coroutine "S" is completed
      And no orphan coroutines

  Scenario: four coroutines share one pool — concurrent cache churn
    # All four coroutines hit the same shared pool. Each prepares a
    # disjoint set of SQL strings, so the per-connection LRUs see
    # interleaving inserts as the pool dispatches slots. A
    # cross-coroutine corruption would surface as db_storm_failed.
    Given a pooled SQLite database "DB" with 2 connections and stmt cache 3
      And a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "S3"
      And a coroutine "S4"
     When coroutine "S1" runs cache-storm of 12 statements on database "DB"
      And coroutine "S2" runs cache-storm of 12 statements on database "DB"
      And coroutine "S3" runs cache-storm of 12 statements on database "DB"
      And coroutine "S4" runs cache-storm of 12 statements on database "DB"
     Then counter "db_storm_ok_S1" plus counter "db_storm_cancelled_S1" plus counter "db_storm_failed_S1" equals counter "db_storm_attempts_S1"
      And counter "db_storm_ok_S2" plus counter "db_storm_cancelled_S2" plus counter "db_storm_failed_S2" equals counter "db_storm_attempts_S2"
      And counter "db_storm_ok_S3" plus counter "db_storm_cancelled_S3" plus counter "db_storm_failed_S3" equals counter "db_storm_attempts_S3"
      And counter "db_storm_ok_S4" plus counter "db_storm_cancelled_S4" plus counter "db_storm_failed_S4" equals counter "db_storm_attempts_S4"
      And coroutine "S1" is completed
      And coroutine "S2" is completed
      And coroutine "S3" is completed
      And coroutine "S4" is completed
      And no orphan coroutines

  Scenario: cancel mid-storm
    # Cancel lands in the middle of the storm; the partially-stormed
    # cache state must remain consistent for the surviving coroutine.
    # The cancelled coroutine must release any in-flight prepare
    # cleanly — a leak here would surface as db_storm_failed on the
    # survivor or as an orphan-coroutine assertion failure.
    Given a pooled SQLite database "DB" with 2 connections and stmt cache 4
      And a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "K"
     When coroutine "S1" runs cache-storm of 40 statements on database "DB"
      And coroutine "S2" runs cache-storm of 40 statements on database "DB"
      And coroutine "K" sleeps 5 ms
      And coroutine "K" cancels coroutine "S1"
     Then counter "db_storm_ok_S1" plus counter "db_storm_cancelled_S1" plus counter "db_storm_failed_S1" equals counter "db_storm_attempts_S1"
      And counter "db_storm_ok_S2" plus counter "db_storm_cancelled_S2" plus counter "db_storm_failed_S2" equals counter "db_storm_attempts_S2"
      And counter "db_storm_cancelled_S1" is at least 1
      And counter "db_storm_ok_S2" is at least 1
      And coroutine "S1" is completed
      And coroutine "S2" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario Outline: cache capacity sweep against fixed storm size
    # Sweeps capacity from undersized (severe eviction) through equal
    # (steady state) to oversized (no eviction). The invariant must
    # hold across the whole range; uneven LRU behaviour would surface
    # as different ok/failed proportions per row.
    Given a pooled SQLite database "DB" with 2 connections and stmt cache <cap>
      And a coroutine "S"
     When coroutine "S" runs cache-storm of 20 statements on database "DB"
     Then counter "db_storm_ok_S" plus counter "db_storm_cancelled_S" plus counter "db_storm_failed_S" equals counter "db_storm_attempts_S"
      And counter "db_storm_ok_S" is at least 1
      And coroutine "S" is completed
      And no orphan coroutines

    Examples:
      | cap |
      | 1   |
      | 5   |
      | 20  |
      | 50  |
