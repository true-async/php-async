Feature: Database chaos — concurrent transactions and heterogeneous workloads

  The per-driver suites (mysql_chaos / pgsql_chaos / mysqli_chaos) drive one
  kind of work at a time. This feature targets the harder surface: many
  coroutines doing *different* things at once against the same database —
  one running a transaction while another reads, several transactions
  committing concurrently, a transaction cancelled while a sibling keeps
  working — all interleaved by the random scheduler.

  The transaction body is multi-statement (BEGIN → INSERT → read-back SELECT
  → COMMIT), so each transaction yields to the reactor several times and a
  sibling coroutine can be scheduled between any two of its statements. The
  PDO connection pool gives every coroutine its own slot, so concurrent
  transactions never share a connection — but the pool's acquire / release /
  in-transaction bookkeeping is exercised hard.

  Invariants, decidable regardless of interleaving:
    - every transaction either fully commits (db_txn_committed) or does not;
      the outcome buckets sum to the attempt count;
    - a reader querying the five seed rows (ids 1..5) always sees exactly
      five — a concurrent writer only ever appends rows with id > 5, so the
      reader's result is interleaving-independent;
    - cancelling or faulting one coroutine never disturbs a sibling;
    - no coroutine hangs or is orphaned, and the pool never wedges.

  Scenario: concurrent transactions all commit
    Given a pooled MySQL database "PDB" with 4 connections
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
      And a coroutine "C4"
     When coroutine "C1" runs a transaction on database "PDB"
      And coroutine "C2" runs a transaction on database "PDB"
      And coroutine "C3" runs a transaction on database "PDB"
      And coroutine "C4" runs a transaction on database "PDB"
     Then counter "db_txn_ok_C1" equals 1
      And counter "db_txn_ok_C2" equals 1
      And counter "db_txn_ok_C3" equals 1
      And counter "db_txn_ok_C4" equals 1
      And counter "db_txn_committed_C1" equals 1
      And counter "db_txn_committed_C4" equals 1
      And no orphan coroutines

  Scenario: a reader and a writer run side by side
    # The writer commits a transaction (appending a row with id > 5) while the
    # reader queries the five seed rows. The reader must see exactly five rows
    # regardless of how the two interleave.
    Given a pooled MySQL database "PDB" with 2 connections
      And a coroutine "W"
      And a coroutine "R"
     When coroutine "W" runs a transaction on database "PDB"
      And coroutine "R" queries database "PDB"
     Then counter "db_txn_ok_W" equals 1
      And counter "db_txn_committed_W" equals 1
      And counter "db_query_ok_R" equals 1
      And counter "db_query_rows_R" equals 5
      And no orphan coroutines

  Scenario: a mixed workload — query, transaction and slow query together
    # Three coroutines doing three different things at once, through one
    # latency-toxic'd pool. None of the work truncates, so all three complete.
    Given a pooled MySQL database "PDB" with 3 connections
      And Toxiproxy adds 5 ms latency to database "PDB"
      And a coroutine "Q"
      And a coroutine "T"
      And a coroutine "S"
     When coroutine "Q" queries database "PDB"
      And coroutine "T" runs a transaction on database "PDB"
      And coroutine "S" runs a slow query on database "PDB"
     Then counter "db_query_ok_Q" equals 1
      And counter "db_query_rows_Q" equals 5
      And counter "db_txn_ok_T" equals 1
      And counter "db_slow_query_ok_S" equals 1
      And coroutine "S" is completed
      And no orphan coroutines

  Scenario: concurrent transactions under latency
    Given a pooled MySQL database "PDB" with 3 connections
      And Toxiproxy adds 5 ms latency to database "PDB"
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
     When coroutine "C1" runs a transaction on database "PDB"
      And coroutine "C2" runs a transaction on database "PDB"
      And coroutine "C3" runs a transaction on database "PDB"
     Then counter "db_txn_committed_C1" equals 1
      And counter "db_txn_committed_C2" equals 1
      And counter "db_txn_committed_C3" equals 1
      And no orphan coroutines

  Scenario Outline: a transaction cancelled while a sibling keeps working
    # A killer cancels the transaction coroutine; the reader sibling is a
    # different coroutine and must be wholly unaffected — it still returns its
    # five rows. The transaction's own outcome is interleaving-dependent, so
    # only its liveness sum is decidable.
    Given a pooled MySQL database "PDB" with 3 connections
      And a coroutine "T"
      And a coroutine "R"
      And a coroutine "K"
     When coroutine "T" runs a transaction on database "PDB"
      And coroutine "R" queries database "PDB"
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "T"
     Then counter "db_txn_ok_T" plus counter "db_txn_cancelled_T" plus counter "db_txn_failed_T" plus counter "db_txn_no_db_T" equals counter "db_txn_attempts_T"
      And counter "db_query_ok_R" equals 1
      And counter "db_query_rows_R" equals 5
      And coroutine "T" is completed
      And coroutine "R" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 2  |
      | 8  |

  Scenario: concurrent transactions, one cancelled
    # Three transactions run together; the killer cancels exactly one. The
    # other two are independent coroutines and must still commit.
    Given a pooled MySQL database "PDB" with 4 connections
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
      And a coroutine "K"
     When coroutine "C1" runs a transaction on database "PDB"
      And coroutine "C2" runs a transaction on database "PDB"
      And coroutine "C3" runs a transaction on database "PDB"
      And coroutine "K" sleeps 2 ms
      And coroutine "K" cancels coroutine "C2"
     Then counter "db_txn_ok_C1" equals 1
      And counter "db_txn_ok_C3" equals 1
      And counter "db_txn_ok_C2" plus counter "db_txn_cancelled_C2" plus counter "db_txn_failed_C2" plus counter "db_txn_no_db_C2" equals counter "db_txn_attempts_C2"
      And coroutine "C1" is completed
      And coroutine "C2" is completed
      And coroutine "C3" is completed
      And no orphan coroutines

  Scenario: a heterogeneous workload fails cleanly under a connection reset
    # reset_peer drops every connection. A transaction, a query and a slow
    # query all run together — each must surface a clean error, complete, and
    # leave nothing wedged. Liveness sums hold for every interleaving.
    Given a pooled MySQL database "PDB" with 3 connections
      And Toxiproxy resets database "PDB" after 200 ms
      And a coroutine "T"
      And a coroutine "Q"
      And a coroutine "S"
     When coroutine "T" runs a transaction on database "PDB"
      And coroutine "Q" queries database "PDB"
      And coroutine "S" runs a slow query on database "PDB"
     Then counter "db_txn_ok_T" plus counter "db_txn_cancelled_T" plus counter "db_txn_failed_T" plus counter "db_txn_no_db_T" equals counter "db_txn_attempts_T"
      And counter "db_query_ok_Q" plus counter "db_query_cancelled_Q" plus counter "db_query_failed_Q" plus counter "db_query_no_db_Q" equals counter "db_query_attempts_Q"
      And coroutine "T" is completed
      And coroutine "Q" is completed
      And coroutine "S" is completed
      And no orphan coroutines

  Scenario: a transaction/query storm on an undersized pool
    # Six coroutines — three transactions, three reads — share a pool of only
    # four connections, so two coroutines must wait for a slot to free. Every
    # coroutine still completes its work: the pool serialises acquisition
    # without losing or corrupting a transaction.
    Given a pooled MySQL database "PDB" with 4 connections
      And a coroutine "T1"
      And a coroutine "Q1"
      And a coroutine "T2"
      And a coroutine "Q2"
      And a coroutine "T3"
      And a coroutine "Q3"
     When coroutine "T1" runs a transaction on database "PDB"
      And coroutine "Q1" queries database "PDB"
      And coroutine "T2" runs a transaction on database "PDB"
      And coroutine "Q2" queries database "PDB"
      And coroutine "T3" runs a transaction on database "PDB"
      And coroutine "Q3" queries database "PDB"
     Then counter "db_txn_committed_T1" equals 1
      And counter "db_txn_committed_T2" equals 1
      And counter "db_txn_committed_T3" equals 1
      And counter "db_query_rows_Q1" equals 5
      And counter "db_query_rows_Q2" equals 5
      And counter "db_query_rows_Q3" equals 5
      And no orphan coroutines

  Scenario: reader and writer crossed with transport and scheduler chaos
    # A fixed writer/reader pair around a non-truncating toxic and a scheduler
    # perturbation — the writer always commits, the reader always sees its
    # five rows, whatever the cross-product.
    Given a pooled MySQL database "PDB" with 3 connections
    One of:
      - Toxiproxy adds 3 ms latency to database "PDB"
      - Toxiproxy throttles database "PDB" to 128 KB/s
      - Toxiproxy slices database "PDB" into random:64-byte TCP segments
    Given a coroutine "W"
      And a coroutine "R"
      And a coroutine "N"
     When coroutine "W" runs a transaction on database "PDB"
      And coroutine "R" queries database "PDB"
    Any of:
      - coroutine "N" sleeps 2 ms
      - coroutine "N" sleeps 6 ms
     Then counter "db_txn_ok_W" equals 1
      And counter "db_txn_committed_W" equals 1
      And counter "db_query_ok_R" equals 1
      And counter "db_query_rows_R" equals 5
      And no orphan coroutines
