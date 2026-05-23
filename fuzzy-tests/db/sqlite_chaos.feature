Feature: Database chaos — async PDO SQLite (pool-focused)

  SQLite is a local file: no Toxiproxy, no network toxics, and pdo_sqlite
  operations do not yield to the reactor (there is no socket to poll on).
  Cancellation mid-query and transport-level chaos therefore do not apply.

  What the chaos suite verifies here is the PDO connection pool itself:
  per-coroutine sqlite3* slots over one shared file, acquire / release /
  slot reuse under many coroutines, and the same multi-statement
  transaction body that surfaced the cancellation UAF on the network
  drivers.

  Schema is seeded per-scenario (the .phpt runs in its own process); the
  scenario file lives under sys_get_temp_dir() and is removed in tearDown.

  Scenario: a query through a pooled SQLite handle returns every row
    Given a pooled SQLite database "DB"
      And a coroutine "C"
     When coroutine "C" queries database "DB"
     Then counter "db_query_ok_C" equals 1
      And counter "db_query_rows_C" equals 5
      And no orphan coroutines

  Scenario: many coroutines share one pooled SQLite handle
    Given a pooled SQLite database "DB" with 4 connections
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
      And a coroutine "C4"
     When coroutine "C1" queries database "DB"
      And coroutine "C2" queries database "DB"
      And coroutine "C3" queries database "DB"
      And coroutine "C4" queries database "DB"
     Then counter "db_query_ok_C1" equals 1
      And counter "db_query_ok_C2" equals 1
      And counter "db_query_ok_C3" equals 1
      And counter "db_query_ok_C4" equals 1
      And counter "db_query_rows_C1" equals 5
      And counter "db_query_rows_C4" equals 5
      And no orphan coroutines

  Scenario: a transaction commits on a pooled SQLite handle
    Given a pooled SQLite database "DB"
      And a coroutine "C"
     When coroutine "C" runs a transaction on database "DB"
     Then counter "db_txn_ok_C" equals 1
      And counter "db_txn_committed_C" equals 1
      And no orphan coroutines

  Scenario: concurrent transactions all commit
    Given a pooled SQLite database "DB" with 4 connections
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
      And a coroutine "C4"
     When coroutine "C1" runs a transaction on database "DB"
      And coroutine "C2" runs a transaction on database "DB"
      And coroutine "C3" runs a transaction on database "DB"
      And coroutine "C4" runs a transaction on database "DB"
     Then counter "db_txn_committed_C1" equals 1
      And counter "db_txn_committed_C2" equals 1
      And counter "db_txn_committed_C3" equals 1
      And counter "db_txn_committed_C4" equals 1
      And no orphan coroutines

  Scenario: a reader and a writer share the pool
    Given a pooled SQLite database "DB" with 2 connections
      And a coroutine "W"
      And a coroutine "R"
     When coroutine "W" runs a transaction on database "DB"
      And coroutine "R" queries database "DB"
     Then counter "db_txn_committed_W" equals 1
      And counter "db_query_ok_R" equals 1
      And counter "db_query_rows_R" equals 5
      And no orphan coroutines

  Scenario: a transaction/query storm on an undersized SQLite pool
    Given a pooled SQLite database "DB" with 3 connections
      And a coroutine "T1"
      And a coroutine "Q1"
      And a coroutine "T2"
      And a coroutine "Q2"
      And a coroutine "T3"
     When coroutine "T1" runs a transaction on database "DB"
      And coroutine "Q1" queries database "DB"
      And coroutine "T2" runs a transaction on database "DB"
      And coroutine "Q2" queries database "DB"
      And coroutine "T3" runs a transaction on database "DB"
     Then counter "db_txn_committed_T1" equals 1
      And counter "db_txn_committed_T2" equals 1
      And counter "db_txn_committed_T3" equals 1
      And counter "db_query_rows_Q1" equals 5
      And counter "db_query_rows_Q2" equals 5
      And no orphan coroutines
