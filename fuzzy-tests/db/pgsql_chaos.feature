Feature: Database chaos — async PDO PgSQL against a Toxiproxy-fronted server

  The PostgreSQL half of the #136 database coverage. Identical chaos model to
  the PDO MySQL suite — Toxiproxy between the async PDO client and a real
  server, every connect / query / transaction through the libuv reactor — but
  exercising the pdo_pgsql driver and the libpq wire protocol.

    client coroutine ──▶ Toxiproxy proxy ──▶ real PostgreSQL server
                              └── latency / bandwidth / slicer / reset_peer

  Opt-in: every scenario needs ext/pdo_pgsql, a reachable PostgreSQL server
  (CHAOS_PGSQL, default 127.0.0.1:5432, schema seeded with a five-row `items`
  table) and a running Toxiproxy; the generated .phpt carry a --SKIPIF--
  probe for all three.

  Invariants, decidable regardless of interleaving:
    - a non-truncating toxic leaves the result set intact — the query still
      returns its five rows;
    - a dropped connection surfaces as a clean PDOException, never a hang;
    - a cancel mid-query leaves the coroutine completed and unorphaned, and
      the outcome buckets sum to the attempt count;
    - the connection pool fails every slot cleanly when the server
      connection is lost.

  Scenario: a query through a clean proxy returns every row
    Given a PgSQL database "DB"
      And a coroutine "C"
     When coroutine "C" queries database "DB"
     Then counter "db_query_attempts_C" equals 1
      And counter "db_query_ok_C" equals 1
      And counter "db_query_rows_C" equals 5
      And no orphan coroutines

  Scenario Outline: latency does not corrupt the result set
    Given a PgSQL database "DB"
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
    Given a PgSQL database "DB"
      And Toxiproxy throttles database "DB" to 64 KB/s
      And a coroutine "C"
     When coroutine "C" queries database "DB"
     Then counter "db_query_ok_C" equals 1
      And counter "db_query_rows_C" equals 5
      And no orphan coroutines

  Scenario Outline: a TCP-sliced wire stream is reassembled exactly
    # Toxiproxy chops the libpq wire protocol into tiny TCP segments — the
    # driver must reassemble the message frames whatever the fragmentation.
    Given a PgSQL database "DB"
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
    Given a PgSQL database "DB"
      And Toxiproxy resets database "DB" after 200 ms
      And a coroutine "C"
     When coroutine "C" runs a slow query on database "DB"
     Then counter "db_slow_query_attempts_C" equals 1
      And counter "db_slow_query_failed_C" equals 1
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario Outline: cancel a coroutine mid-query
    Given a PgSQL database "DB"
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
    Given a pooled PgSQL database "PDB" with 4 connections
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
    Given a pooled PgSQL database "PDB" with 3 connections
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
    Given a pooled PgSQL database "PDB" with 4 connections
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
    Given a PgSQL database "DB"
      And Toxiproxy adds 5 ms latency to database "DB"
      And a coroutine "C"
     When coroutine "C" runs a transaction on database "DB"
     Then counter "db_txn_attempts_C" equals 1
      And counter "db_txn_ok_C" equals 1
      And counter "db_txn_committed_C" equals 1
      And no orphan coroutines

  Scenario: an RST mid-transaction surfaces cleanly and wedges nothing
    Given a pooled PgSQL database "PDB"
      And Toxiproxy resets database "PDB" after 150 ms
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" runs a transaction on database "PDB"
      And coroutine "K" runs a slow query on database "PDB"
     Then counter "db_txn_ok_C" plus counter "db_txn_cancelled_C" plus counter "db_txn_failed_C" plus counter "db_txn_no_db_C" equals counter "db_txn_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: database toxics crossed with logic and scheduler chaos
    Given a PgSQL database "DB"
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
