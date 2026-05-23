Feature: Database chaos — async mysqli against a Toxiproxy-fronted server

  The mysqli half of the #136 database coverage. The mysqli extension reaches
  the same real MySQL server through the same Toxiproxy proxy as the PDO MySQL
  suite — connect / query / transaction all go through the libuv reactor — but
  exercises a different driver surface (mysqli's own connection object,
  result iteration and prepared-statement API). mysqli has no connection
  pool, so every chaos query opens and closes its own connection.

  Opt-in by design: every scenario needs ext/mysqli, a reachable MySQL server
  (CHAOS_MYSQL) and a running Toxiproxy; the generated .phpt carry a
  --SKIPIF-- probe for all three.

  Invariants, decidable regardless of interleaving:
    - a non-truncating toxic leaves the result set intact — the query still
      returns its five rows;
    - a dropped connection surfaces as a clean mysqli_sql_exception, never a
      hang;
    - a cancel mid-query leaves the coroutine completed and unorphaned, and
      the outcome buckets sum to the attempt count.

  Scenario: a query through a clean proxy returns every row
    Given a MySQLi database "DB"
      And a coroutine "C"
     When coroutine "C" queries via mysqli "DB"
     Then counter "mysqli_query_attempts_C" equals 1
      And counter "mysqli_query_ok_C" equals 1
      And counter "mysqli_query_rows_C" equals 5
      And no orphan coroutines

  Scenario Outline: latency does not corrupt the result set
    Given a MySQLi database "DB"
      And Toxiproxy adds <latency> ms latency to database "DB"
      And a coroutine "C"
     When coroutine "C" queries via mysqli "DB"
     Then counter "mysqli_query_ok_C" equals 1
      And counter "mysqli_query_rows_C" equals 5
      And no orphan coroutines

    Examples:
      | latency |
      | 1       |
      | 5       |
      | 20      |

  Scenario: a bandwidth-throttled query still returns every row
    Given a MySQLi database "DB"
      And Toxiproxy throttles database "DB" to 64 KB/s
      And a coroutine "C"
     When coroutine "C" queries via mysqli "DB"
     Then counter "mysqli_query_ok_C" equals 1
      And counter "mysqli_query_rows_C" equals 5
      And no orphan coroutines

  Scenario Outline: a TCP-sliced wire stream is reassembled exactly
    Given a MySQLi database "DB"
      And Toxiproxy slices database "DB" into <segment>-byte TCP segments
      And a coroutine "C"
     When coroutine "C" queries via mysqli "DB"
     Then counter "mysqli_query_ok_C" equals 1
      And counter "mysqli_query_rows_C" equals 5
      And no orphan coroutines

    Examples:
      | segment |
      | 1       |
      | 64      |
      | 512     |

  Scenario: an RST mid-query surfaces as a clean error
    Given a MySQLi database "DB"
      And Toxiproxy resets database "DB" after 200 ms
      And a coroutine "C"
     When coroutine "C" runs a slow query via mysqli "DB"
     Then counter "mysqli_slow_query_attempts_C" equals 1
      And counter "mysqli_slow_query_failed_C" equals 1
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario Outline: cancel a coroutine mid-query
    # A killer cancels the coroutine while mysqli is parked in the reactor on
    # the DB socket. Only the liveness sum and no-hang / no-orphan are
    # decidable under the random scheduler.
    Given a MySQLi database "DB"
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" runs a slow query via mysqli "DB"
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "C"
     Then counter "mysqli_slow_query_ok_C" plus counter "mysqli_slow_query_cancelled_C" plus counter "mysqli_slow_query_failed_C" plus counter "mysqli_slow_query_no_db_C" equals counter "mysqli_slow_query_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

    Examples:
      | ms  |
      | 0   |
      | 50  |
      | 300 |
      | 900 |

  Scenario: many concurrent mysqli queries under latency
    Given a MySQLi database "DB"
      And Toxiproxy adds 5 ms latency to database "DB"
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
     When coroutine "C1" queries via mysqli "DB"
      And coroutine "C2" queries via mysqli "DB"
      And coroutine "C3" queries via mysqli "DB"
     Then counter "mysqli_query_ok_C1" equals 1
      And counter "mysqli_query_ok_C2" equals 1
      And counter "mysqli_query_ok_C3" equals 1
      And counter "mysqli_query_rows_C2" equals 5
      And no orphan coroutines

  Scenario: a transaction commits through a latency-toxic'd connection
    Given a MySQLi database "DB"
      And Toxiproxy adds 5 ms latency to database "DB"
      And a coroutine "C"
     When coroutine "C" runs a transaction via mysqli "DB"
     Then counter "mysqli_txn_attempts_C" equals 1
      And counter "mysqli_txn_ok_C" equals 1
      And counter "mysqli_txn_committed_C" equals 1
      And no orphan coroutines

  Scenario: an RST mid-transaction surfaces cleanly
    # reset_peer drops the connection 150 ms in; the transaction must fail
    # with a clean mysqli_sql_exception and the coroutine must complete.
    Given a MySQLi database "DB"
      And Toxiproxy resets database "DB" after 150 ms
      And a coroutine "C"
     When coroutine "C" runs a transaction via mysqli "DB"
     Then counter "mysqli_txn_ok_C" plus counter "mysqli_txn_cancelled_C" plus counter "mysqli_txn_failed_C" plus counter "mysqli_txn_no_db_C" equals counter "mysqli_txn_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: mysqli toxics crossed with logic and scheduler chaos
    Given a MySQLi database "DB"
    One of:
      - Toxiproxy adds 3 ms latency to database "DB"
      - Toxiproxy throttles database "DB" to 128 KB/s
      - Toxiproxy slices database "DB" into random:64-byte TCP segments
      And a coroutine "C"
      And a coroutine "N"
     When coroutine "C" queries via mysqli "DB"
    Any of:
      - coroutine "N" sleeps 2 ms
      - coroutine "N" sleeps 6 ms
     Then counter "mysqli_query_ok_C" equals 1
      And counter "mysqli_query_rows_C" equals 5
      And no orphan coroutines
