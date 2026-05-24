Feature: DB chaos — mixed workload + mid-flight TCP reset

  Stresses a pooled PDO handle running a mixed transaction + query
  workload while a Toxiproxy `reset_peer` toxic kills the connection
  mid-flight. The pool must:
    1. Surface the reset cleanly as a per-coroutine PDOException
       (caught into db_*_failed by the harness);
    2. Reclaim slots from failed coroutines;
    3. Leave no orphan coroutines or wedged slots.

  Per-coroutine invariant: `db_*_ok + db_*_cancelled + db_*_failed +
  db_*_no_db == db_*_attempts`. Liveness: every coroutine
  terminates, no orphans. Existing `mysql_chaos.feature` exercises
  `reset_peer` over a single work type (queries or transactions); this
  feature is the cross-product: a bounded pool with a mixed
  transaction + query workload, all meeting a TCP RST.

  Notes on pool sizing: coroutine count is capped at the pool size to
  avoid the queue-wait-forever deadlock — `reset_peer` is a sticky
  toxic that RSTs every new connection, so a waiter parked on a pool
  whose only path forward is "open a new connection" cannot ever
  acquire one. That edge case is real but outside the scope of this
  backstop and is tracked separately.

  Scenario: four coroutines, mixed workload, reset at 30 ms
    # Two transactions and two queries against a max=4 pool — each
    # coroutine acquires its own slot. The reset_peer toxic fires
    # 30 ms in, mid-work for whoever holds a slot at that moment.
    # Every coroutine must bucket; the pool must not wedge.
    Given a pooled MySQL database "PDB" with 4 connections
      And Toxiproxy resets database "PDB" after 30 ms
      And a coroutine "T1"
      And a coroutine "T2"
      And a coroutine "Q1"
      And a coroutine "Q2"
     When coroutine "T1" runs a transaction on database "PDB"
      And coroutine "T2" runs a transaction on database "PDB"
      And coroutine "Q1" queries database "PDB"
      And coroutine "Q2" queries database "PDB"
     Then counter "db_txn_ok_T1" plus counter "db_txn_cancelled_T1" plus counter "db_txn_failed_T1" plus counter "db_txn_no_db_T1" equals counter "db_txn_attempts_T1"
      And counter "db_txn_ok_T2" plus counter "db_txn_cancelled_T2" plus counter "db_txn_failed_T2" plus counter "db_txn_no_db_T2" equals counter "db_txn_attempts_T2"
      And counter "db_query_ok_Q1" plus counter "db_query_cancelled_Q1" plus counter "db_query_failed_Q1" plus counter "db_query_no_db_Q1" equals counter "db_query_attempts_Q1"
      And counter "db_query_ok_Q2" plus counter "db_query_cancelled_Q2" plus counter "db_query_failed_Q2" plus counter "db_query_no_db_Q2" equals counter "db_query_attempts_Q2"
      And coroutine "T1" is completed
      And coroutine "T2" is completed
      And coroutine "Q1" is completed
      And coroutine "Q2" is completed
      And no orphan coroutines

  Scenario Outline: reset-timing varied against a mixed workload
    # Reset delay sweeps the chaos window. Early resets (5 ms) land
    # while pool slots are being acquired; mid-window (60 ms) lands
    # mid-query; late (200 ms) often lands after the storm cleared.
    # Every interleaving must still bucket cleanly.
    Given a pooled MySQL database "PDB" with 4 connections
      And Toxiproxy resets database "PDB" after <ms> ms
      And a coroutine "T1"
      And a coroutine "T2"
      And a coroutine "Q1"
      And a coroutine "Q2"
     When coroutine "T1" runs a transaction on database "PDB"
      And coroutine "T2" runs a transaction on database "PDB"
      And coroutine "Q1" queries database "PDB"
      And coroutine "Q2" queries database "PDB"
     Then counter "db_txn_ok_T1" plus counter "db_txn_cancelled_T1" plus counter "db_txn_failed_T1" plus counter "db_txn_no_db_T1" equals counter "db_txn_attempts_T1"
      And counter "db_txn_ok_T2" plus counter "db_txn_cancelled_T2" plus counter "db_txn_failed_T2" plus counter "db_txn_no_db_T2" equals counter "db_txn_attempts_T2"
      And counter "db_query_ok_Q1" plus counter "db_query_cancelled_Q1" plus counter "db_query_failed_Q1" plus counter "db_query_no_db_Q1" equals counter "db_query_attempts_Q1"
      And counter "db_query_ok_Q2" plus counter "db_query_cancelled_Q2" plus counter "db_query_failed_Q2" plus counter "db_query_no_db_Q2" equals counter "db_query_attempts_Q2"
      And coroutine "T1" is completed
      And coroutine "T2" is completed
      And coroutine "Q1" is completed
      And coroutine "Q2" is completed
      And no orphan coroutines

    Examples:
      | ms  |
      | 5   |
      | 60  |
      | 200 |
