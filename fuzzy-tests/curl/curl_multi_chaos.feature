Feature: HTTP chaos — async ext/curl curl_multi against several misbehaving peers

  http_chaos.feature (#136) drove a single-handle curl_exec() against one
  evil HTTP peer. This feature closes the gap for curl_multi: several
  handles attached to one curl_multi_init() run concurrently through the
  reactor's curl_multi_select() integration in ext/curl/curl_async.c —
  the same file the #136 chunked-body bug fix lives in.

  Each scenario fetches N peers in parallel from one coroutine. The
  coroutine drives the standard curl_multi loop:

      do {
          curl_multi_exec($mh, $active);
          if ($active) curl_multi_select($mh, 1.0);   // ← reactor yield
      } while ($active);

  Per-handle outcome (CURLMSG_DONE) bumps curl_multi_handles_done /
  _failed. The coroutine-level outcome (ok / cancelled / failed) sums to
  attempts.

  # Fixed: #145 — a cancel mid-curl_multi_select() used to complete the
  # coroutine while it was still in the runqueue ("still in the queue"
  # warning), corrupting the zend heap so the next coroutine SEGV'd in
  # zend_mm_alloc_small. Fix routes the cancelled select through finally
  # (commit 0dcb62f). The cancel scenarios below are the regression
  # backstop.

  Scenario: cancel mid-multi-select (#145 regression)
    # Two slow, sliced peers keep the curl_multi loop parked inside
    # curl_multi_select(); the killer cancels the fetcher while it is
    # parked. The cancellation must unwind cleanly — no heap corruption,
    # no orphaned coroutine.
    Given an evil HTTP peer "EP1" serving 4096 bytes
      And evil peer "EP1" slices output into 64-byte chunks
      And evil peer "EP1" delays 100 ms between chunks
      And an evil HTTP peer "EP2" serving 4096 bytes
      And evil peer "EP2" slices output into 64-byte chunks
      And evil peer "EP2" delays 100 ms between chunks
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" fetches peers "EP1","EP2" via curl_multi
      And coroutine "K" sleeps 30 ms
      And coroutine "K" cancels coroutine "C"
     Then counter "curl_multi_ok_C" plus counter "curl_multi_cancelled_C" plus counter "curl_multi_failed_C" equals counter "curl_multi_attempts_C"
      And counter "curl_multi_cancelled_C" is at least 1
      And coroutine "C" is completed
      And coroutine "K" is completed
      And no orphan coroutines

  Scenario Outline: cancel-timing varied across the transfer window (#145)
    # Slide the cancel instant across the slow transfer: early (5 ms, often
    # before the first chunk), mid-window (75 ms) and late (200 ms). At all
    # points the fetcher must unwind without corrupting the heap.
    Given an evil HTTP peer "EP1" serving 4096 bytes
      And evil peer "EP1" slices output into 64-byte chunks
      And evil peer "EP1" delays 100 ms between chunks
      And an evil HTTP peer "EP2" serving 4096 bytes
      And evil peer "EP2" slices output into 64-byte chunks
      And evil peer "EP2" delays 100 ms between chunks
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" fetches peers "EP1","EP2" via curl_multi
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "C"
     Then counter "curl_multi_ok_C" plus counter "curl_multi_cancelled_C" plus counter "curl_multi_failed_C" equals counter "curl_multi_attempts_C"
      And coroutine "C" is completed
      And coroutine "K" is completed
      And no orphan coroutines

    Examples:
      | ms  |
      | 5   |
      | 75  |
      | 200 |

  Scenario: three peers, all return intact
    Given an evil HTTP peer "EP1" serving 1024 bytes
      And an evil HTTP peer "EP2" serving 2048 bytes
      And an evil HTTP peer "EP3" serving 4096 bytes
      And a coroutine "C"
     When coroutine "C" fetches peers "EP1","EP2","EP3" via curl_multi
     Then counter "curl_multi_attempts_C" equals 1
      And counter "curl_multi_ok_C" equals 1
      And counter "curl_multi_handles_done_C" equals 3
      And counter "curl_multi_handles_failed_C" equals 0
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: one peer aborts mid-body — others still complete
    # Per-handle failures must not poison the multi handle: the OK peers
    # still complete. Invariant: total per-handle outcomes (done+failed)
    # equals the number of attached handles.
    Given an evil HTTP peer "EP1" serving 2048 bytes
      And an evil HTTP peer "EP2" serving 2048 bytes
      And evil peer "EP2" closes abruptly after 256 bytes
      And an evil HTTP peer "EP3" serving 2048 bytes
      And a coroutine "C"
     When coroutine "C" fetches peers "EP1","EP2","EP3" via curl_multi
     Then counter "curl_multi_attempts_C" equals 1
      And counter "curl_multi_ok_C" equals 1
      And counter "curl_multi_handles_done_C" plus counter "curl_multi_handles_failed_C" equals 3
      And counter "curl_multi_handles_failed_C" is at least 1
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: two coroutines, each owning its own multi handle
    # Two independent fetchers in parallel — each builds its own
    # curl_multi handle. Stresses the reactor's per-multi watcher
    # bookkeeping (one libuv watcher per multi). Both must complete.
    Given an evil HTTP peer "EP1" serving 1024 bytes
      And an evil HTTP peer "EP2" serving 1024 bytes
      And an evil HTTP peer "EP3" serving 1024 bytes
      And an evil HTTP peer "EP4" serving 1024 bytes
      And a coroutine "C1"
      And a coroutine "C2"
     When coroutine "C1" fetches peers "EP1","EP2" via curl_multi
      And coroutine "C2" fetches peers "EP3","EP4" via curl_multi
     Then counter "curl_multi_ok_C1" equals 1
      And counter "curl_multi_handles_done_C1" equals 2
      And counter "curl_multi_ok_C2" equals 1
      And counter "curl_multi_handles_done_C2" equals 2
      And coroutine "C1" is completed
      And coroutine "C2" is completed
      And no orphan coroutines
