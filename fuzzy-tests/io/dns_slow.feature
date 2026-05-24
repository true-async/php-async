Feature: I/O chaos — async DNS resolve under cancel

  Drives `php_network_getaddrinfo_async()` (libuv worker thread) by
  doing `stream_socket_client('tcp://HOST.invalid:80')`. The
  hostname always NXDOMAINs, so the dominant outcome with no cancel
  is `dns_failed`; the chaos value is the cancel-during-async-resolve
  path — the libuv DNS work handle must be released cleanly without
  leaking, and the cancelled coroutine must terminate via
  AsyncCancellation.

  A SKIPIF probe (`dns-async-engages`) runs a synthetic .invalid
  resolve and skips the scenario if the host returns in <20 ms —
  some musl resolvers / fully-cached NSS environments collapse
  NXDOMAIN to microseconds and the async path never engages, which
  would silently fake coverage.

  Scenario: a single resolve fails with NXDOMAIN
    # Smoke. With no cancel, .invalid always NXDOMAINs — confirms
    # the async path is active and the outcome buckets.
    Given a coroutine "R"
     When coroutine "R" resolves nonexistent hostname "smoke-fuzzy.invalid" with timeout 2000 ms
     Then counter "dns_ok_R" plus counter "dns_failed_R" plus counter "dns_cancelled_R" plus counter "dns_timeout_R" equals counter "dns_attempts_R"
      And counter "dns_failed_R" is at least 1
      And coroutine "R" is completed
      And no orphan coroutines

  Scenario: cancel during resolve
    # The killer cancels R parked in async getaddrinfo; the libuv
    # DNS work handle must be released without leaks. The cancel
    # delay sits inside the typical .invalid resolve window (~30 ms
    # on glibc systems past the SKIPIF gate).
    Given a coroutine "R"
      And a coroutine "K"
     When coroutine "R" resolves nonexistent hostname "cancel-fuzzy.invalid" with timeout 2000 ms
      And coroutine "K" sleeps 5 ms
      And coroutine "K" cancels coroutine "R"
     Then counter "dns_ok_R" plus counter "dns_failed_R" plus counter "dns_cancelled_R" plus counter "dns_timeout_R" equals counter "dns_attempts_R"
      And coroutine "R" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario Outline: cancel timing varied across the resolve window
    # 0 ms races the resolve's own scheduling; mid-window values
    # land while libuv is in the worker thread; the largest value
    # often lands after the resolve already failed. Every
    # interleaving must bucket.
    Given a coroutine "R"
      And a coroutine "K"
     When coroutine "R" resolves nonexistent hostname "sweep-fuzzy-<ms>.invalid" with timeout 2000 ms
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "R"
     Then counter "dns_ok_R" plus counter "dns_failed_R" plus counter "dns_cancelled_R" plus counter "dns_timeout_R" equals counter "dns_attempts_R"
      And coroutine "R" is completed
      And coroutine "K" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 2  |
      | 10 |
      | 80 |

  Scenario: many concurrent resolves
    # Stresses several DNS requests parked in the reactor at once.
    # Each must independently bucket; a shared-state bug in the DNS
    # release path would leak handles or mis-bucket outcomes.
    Given a coroutine "R1"
      And a coroutine "R2"
      And a coroutine "R3"
      And a coroutine "R4"
     When coroutine "R1" resolves nonexistent hostname "conc1-fuzzy.invalid" with timeout 2000 ms
      And coroutine "R2" resolves nonexistent hostname "conc2-fuzzy.invalid" with timeout 2000 ms
      And coroutine "R3" resolves nonexistent hostname "conc3-fuzzy.invalid" with timeout 2000 ms
      And coroutine "R4" resolves nonexistent hostname "conc4-fuzzy.invalid" with timeout 2000 ms
     Then counter "dns_ok_R1" plus counter "dns_failed_R1" plus counter "dns_cancelled_R1" plus counter "dns_timeout_R1" equals counter "dns_attempts_R1"
      And counter "dns_ok_R2" plus counter "dns_failed_R2" plus counter "dns_cancelled_R2" plus counter "dns_timeout_R2" equals counter "dns_attempts_R2"
      And counter "dns_ok_R3" plus counter "dns_failed_R3" plus counter "dns_cancelled_R3" plus counter "dns_timeout_R3" equals counter "dns_attempts_R3"
      And counter "dns_ok_R4" plus counter "dns_failed_R4" plus counter "dns_cancelled_R4" plus counter "dns_timeout_R4" equals counter "dns_attempts_R4"
      And coroutine "R1" is completed
      And coroutine "R2" is completed
      And coroutine "R3" is completed
      And coroutine "R4" is completed
      And no orphan coroutines
