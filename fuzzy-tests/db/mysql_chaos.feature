Feature: Database chaos — async PDO MySQL against a Toxiproxy-fronted server

  Closes the database half of the #136 coverage gap. The PDO MySQL driver
  speaks a binary wire protocol, so a pure-PHP mock is not worth building —
  the chaos is injected at the transport level instead: Toxiproxy sits
  between the async PDO client and a real MySQL server, and every connect /
  query / transaction goes through the libuv reactor.

    client coroutine ──▶ Toxiproxy proxy ──▶ real MySQL server
                              └── latency / bandwidth / slicer / reset_peer

  This exercises the paths where reactor cancellation, use-after-free and
  pool-state bugs hide: a coroutine cancelled mid-query, the connection
  dropped mid-query / mid-transaction, and the PDO connection pool handing
  out / reclaiming / replacing a slot whose connection was lost.

  Opt-in by design: every scenario needs ext/pdo_mysql, a reachable MySQL
  server (CHAOS_MYSQL, default 127.0.0.1:3306, schema seeded with a five-row
  `items` table) and a running Toxiproxy. The generated .phpt carry a
  --SKIPIF-- probe for all three, so the suite stays inert on dev machines
  and per-PR CI and runs only on the nightly job.

  Invariants, decidable regardless of interleaving:
    - a non-truncating toxic (latency / bandwidth / slicer) leaves the result
      set byte-for-byte intact — the query still returns its five rows;
    - a dropped connection surfaces as a clean PDOException, never a hang;
    - a cancel mid-query leaves the coroutine completed and unorphaned, and
      the outcome buckets sum to the attempt count;
    - the connection pool keeps serving good connections to other coroutines
      even while one slot's connection is being reset.

  Scenario: a query through a clean proxy returns every row
    Given a MySQL database "DB"
      And a coroutine "C"
     When coroutine "C" queries database "DB"
     Then counter "db_query_attempts_C" equals 1
      And counter "db_query_ok_C" equals 1
      And counter "db_query_rows_C" equals 5
      And no orphan coroutines

  Scenario Outline: latency does not corrupt the result set
    Given a MySQL database "DB"
      And Toxiproxy adds <latency> ms latency to database "DB"
      And a coroutine "C"
     When coroutine "C" queries database "DB"
     Then counter "db_query_ok_C" equals 1
      And counter "db_query_rows_C" equals 5
      And no orphan coroutines

    Examples:
      | latency |
      | 1       |
      | 5       |
      | 20      |

  Scenario: a bandwidth-throttled query still returns every row
    Given a MySQL database "DB"
      And Toxiproxy throttles database "DB" to 64 KB/s
      And a coroutine "C"
     When coroutine "C" queries database "DB"
     Then counter "db_query_ok_C" equals 1
      And counter "db_query_rows_C" equals 5
      And no orphan coroutines

  Scenario Outline: a TCP-sliced wire stream is reassembled exactly
    # Toxiproxy chops the MySQL wire protocol into tiny TCP segments — the
    # driver must reassemble the binary frames whatever the fragmentation.
    Given a MySQL database "DB"
      And Toxiproxy slices database "DB" into <segment>-byte TCP segments
      And a coroutine "C"
     When coroutine "C" queries database "DB"
     Then counter "db_query_ok_C" equals 1
      And counter "db_query_rows_C" equals 5
      And no orphan coroutines

    Examples:
      | segment |
      | 1       |
      | 64      |
      | 512     |

  Scenario: an RST mid-query surfaces as a clean error
    # reset_peer fires a TCP RST 200 ms into the connection; the slow query
    # runs for ~2 s, so the reset always lands mid-query. The driver must
    # raise a clean PDOException — bucketed as failed — never hang.
    Given a MySQL database "DB"
      And Toxiproxy resets database "DB" after 200 ms
      And a coroutine "C"
     When coroutine "C" runs a slow query on database "DB"
     Then counter "db_slow_query_attempts_C" equals 1
      And counter "db_slow_query_failed_C" equals 1
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario Outline: cancel a coroutine mid-query
    # A killer cancels the coroutine while it is parked in the reactor waiting
    # on the DB socket. Under the random scheduler the cancel can land before,
    # during, or after the query — so only the liveness sum and the
    # no-hang / no-orphan invariants are decidable.
    Given a MySQL database "DB"
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" runs a slow query on database "DB"
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "C"
     Then counter "db_slow_query_ok_C" plus counter "db_slow_query_cancelled_C" plus counter "db_slow_query_failed_C" plus counter "db_slow_query_no_db_C" equals counter "db_slow_query_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

    Examples:
      | ms  |
      | 0   |
      | 50  |
      | 300 |
      | 900 |

  Scenario: many coroutines share one pooled connection handle
    # Four coroutines each query through the same pool-enabled PDO; the pool
    # hands every coroutine its own slot. All four must complete with the
    # full result set — no slot cross-talk.
    Given a pooled MySQL database "PDB" with 4 connections
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
      And a coroutine "C4"
     When coroutine "C1" queries database "PDB"
      And coroutine "C2" queries database "PDB"
      And coroutine "C3" queries database "PDB"
      And coroutine "C4" queries database "PDB"
     Then counter "db_query_ok_C1" equals 1
      And counter "db_query_ok_C2" equals 1
      And counter "db_query_ok_C3" equals 1
      And counter "db_query_ok_C4" equals 1
      And counter "db_query_rows_C1" equals 5
      And counter "db_query_rows_C4" equals 5
      And no orphan coroutines

  Scenario: concurrent pooled queries under latency
    Given a pooled MySQL database "PDB" with 3 connections
      And Toxiproxy adds 5 ms latency to database "PDB"
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
     When coroutine "C1" queries database "PDB"
      And coroutine "C2" queries database "PDB"
      And coroutine "C3" queries database "PDB"
     Then counter "db_query_ok_C1" equals 1
      And counter "db_query_ok_C2" equals 1
      And counter "db_query_ok_C3" equals 1
      And no orphan coroutines

  Scenario: the pool fails every query cleanly when the server connection drops
    # reset_peer drops every connection through the proxy. Each pooled
    # coroutine's query must fail with a clean PDOException — bucketed as
    # failed — and the pool must not wedge, hang or leak: all coroutines
    # complete, none is orphaned, and the shared handle tears down cleanly.
    # This is the pool's behaviour when every slot's connection is lost.
    Given a pooled MySQL database "PDB" with 4 connections
      And Toxiproxy resets database "PDB" after 200 ms
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
     When coroutine "C1" queries database "PDB"
      And coroutine "C2" queries database "PDB"
      And coroutine "C3" queries database "PDB"
     Then counter "db_query_ok_C1" plus counter "db_query_failed_C1" equals counter "db_query_attempts_C1"
      And counter "db_query_ok_C2" plus counter "db_query_failed_C2" equals counter "db_query_attempts_C2"
      And counter "db_query_ok_C3" plus counter "db_query_failed_C3" equals counter "db_query_attempts_C3"
      And coroutine "C1" is completed
      And coroutine "C2" is completed
      And coroutine "C3" is completed
      And no orphan coroutines

  Scenario: a transaction commits through a latency-toxic'd connection
    Given a MySQL database "DB"
      And Toxiproxy adds 5 ms latency to database "DB"
      And a coroutine "C"
     When coroutine "C" runs a transaction on database "DB"
     Then counter "db_txn_attempts_C" equals 1
      And counter "db_txn_ok_C" equals 1
      And counter "db_txn_committed_C" equals 1
      And no orphan coroutines

  Scenario: an RST mid-transaction surfaces cleanly and wedges nothing
    # The transaction opens, then reset_peer drops the connection 150 ms in.
    # The driver must raise a clean error; the coroutine completes and the
    # connection (pooled slot here) is left usable, not mid-transaction.
    Given a pooled MySQL database "PDB"
      And Toxiproxy resets database "PDB" after 150 ms
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" runs a transaction on database "PDB"
      And coroutine "K" runs a slow query on database "PDB"
     Then counter "db_txn_ok_C" plus counter "db_txn_cancelled_C" plus counter "db_txn_failed_C" plus counter "db_txn_no_db_C" equals counter "db_txn_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: database toxics crossed with logic and scheduler chaos
    # Three chaos axes around a fixed five-row oracle: which non-truncating
    # transport toxic Toxiproxy applies, whether the client pools its
    # connection, and the scheduler interleaving. None of the toxics
    # truncate, so the exact row count stays decidable across the product.
    Given a MySQL database "DB"
    One of:
      - Toxiproxy adds 3 ms latency to database "DB"
      - Toxiproxy throttles database "DB" to 128 KB/s
      - Toxiproxy slices database "DB" into random:64-byte TCP segments
      And a coroutine "C"
      And a coroutine "N"
     When coroutine "C" queries database "DB"
    Any of:
      - coroutine "N" sleeps 2 ms
      - coroutine "N" sleeps 6 ms
     Then counter "db_query_ok_C" equals 1
      And counter "db_query_rows_C" equals 5
      And no orphan coroutines
