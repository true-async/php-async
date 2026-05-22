Feature: I/O chaos — write-path back-pressure against a slow / dead reader

  The download features exercise the reactor's READ path. This one exercises
  the WRITE path: a consume-mode EvilPeer reads from the client slowly, or
  not at all. When the client's send buffer fills, fwrite() must suspend on
  the reactor's write-wait hook — never busy-spin, never silently drop bytes.

  No external peer and no ext/sockets: the loopback send buffer autotunes up
  to a few MiB, so the uploads here are deliberately larger than that ceiling
  (6 MiB drain / 8 MiB stall). A peer that drains slowly is then guaranteed
  to suspend the writer at least once; a peer that never reads parks it for
  good.

  Invariants:
    - a peer that eventually drains everything: every byte the client sent
      was read by the peer, and the upload coroutine finishes;
    - a peer that abandons the connection: the client sees a clean broken
      pipe (io_upload_failed), never a hang;
    - cancelling a writer blocked on a full send buffer: the cancellation is
      delivered into the fwrite() wait, the coroutine terminates via its
      catch block, and the reactor leaves no orphan.

  Scenario: a slow-draining peer eventually absorbs the whole upload
    # 6 MiB exceeds the autotuned send buffer; the peer reads 256 KiB at a
    # time with a drip delay, so the client's fwrite() is forced to suspend
    # and resume repeatedly. Every byte must still arrive.
    Given an evil peer "EP" that reads 262144 bytes at a time
      And evil peer "EP" delays 1 ms between reads
      And a coroutine "C"
     When coroutine "C" uploads 6291456 bytes to peer "EP"
     Then counter "io_upload_attempts_C" equals 1
      And counter "io_upload_ok_C" equals 1
      And counter "io_sent_bytes_C" equals 6291456
      And counter "evil_peer_served_EP" equals 1
      And counter "evil_peer_read_bytes_EP" equals 6291456
      And no orphan coroutines

  Scenario Outline: the upload drains whatever granularity the peer reads at
    Given an evil peer "EP" that reads <rate> bytes at a time
      And a coroutine "C"
     When coroutine "C" uploads 6291456 bytes to peer "EP"
     Then counter "io_upload_ok_C" equals 1
      And counter "io_sent_bytes_C" equals 6291456
      And counter "evil_peer_read_bytes_EP" equals 6291456
      And no orphan coroutines

    Examples:
      | rate    |
      | 8192    |
      | 65536   |
      | 1048576 |

  Scenario: chunked writes against a slow reader — logic chaos on the call shape
    # Same payload, but split into many small fwrite() calls. The byte stream
    # the peer reassembles must be identical regardless of write granularity.
    Given an evil peer "EP" that reads 65536 bytes at a time
      And a coroutine "C"
     When coroutine "C" uploads 6291456 bytes to peer "EP" in 32768-byte writes
     Then counter "io_upload_ok_C" equals 1
      And counter "io_sent_bytes_C" equals 6291456
      And counter "evil_peer_read_bytes_EP" equals 6291456
      And no orphan coroutines

  Scenario: cancel a writer blocked on a full send buffer
    # The peer never reads a byte, so an 8 MiB upload parks the writer on the
    # write-wait hook. A killer cancels it mid-block. Under random scheduling
    # the cancel may also land before the upload body reaches its try block,
    # so the invariant is the liveness sum, not an exact bucket.
    Given an evil peer "EP" that never reads
      And evil peer "EP" holds the connection for 150 ms
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" uploads 8388608 bytes to peer "EP"
      And coroutine "K" sleeps 10 ms
      And coroutine "K" cancels coroutine "C"
     Then counter "io_upload_ok_C" plus counter "io_upload_cancelled_C" plus counter "io_upload_failed_C" equals counter "io_upload_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario Outline: cancel delay varied against the blocked writer
    Given an evil peer "EP" that never reads
      And evil peer "EP" holds the connection for 150 ms
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" uploads 8388608 bytes to peer "EP"
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "C"
     Then counter "io_upload_ok_C" plus counter "io_upload_cancelled_C" plus counter "io_upload_failed_C" equals counter "io_upload_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 5  |
      | 25 |

  Scenario: peer drains part of the upload then abandons the connection
    # The peer reads 8 KiB then closes. The client still has ~8 MiB queued —
    # its fwrite() must surface a clean broken-pipe failure, never hang.
    Given an evil peer "EP" that reads 8192 bytes at a time
      And evil peer "EP" stops reading after 8192 bytes
      And a coroutine "C"
     When coroutine "C" uploads 8388608 bytes to peer "EP"
     Then counter "io_upload_attempts_C" equals 1
      And counter "io_upload_ok_C" equals 0
      And counter "io_upload_failed_C" equals 1
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: two writers, two peers — independent back-pressure
    Given an evil peer "EP1" that reads 65536 bytes at a time
      And evil peer "EP1" delays 1 ms between reads
      And an evil peer "EP2" that reads 262144 bytes at a time
      And a coroutine "C1"
      And a coroutine "C2"
     When coroutine "C1" uploads 6291456 bytes to peer "EP1"
      And coroutine "C2" uploads 6291456 bytes to peer "EP2"
     Then counter "io_upload_ok_C1" equals 1
      And counter "io_upload_ok_C2" equals 1
      And counter "evil_peer_read_bytes_EP1" equals 6291456
      And counter "evil_peer_read_bytes_EP2" equals 6291456
      And no orphan coroutines

  Scenario: back-pressure crossed with scheduler chaos
    # Transport varies which slow-drain toxics fire; a second coroutine adds
    # interleaving pressure. The payload is fixed so the drain invariant
    # stays decidable across the whole cross-product.
    Given an evil peer "EP" that reads 65536 bytes at a time
    Any of:
      - evil peer "EP" delays 1|2 ms between reads
      - evil peer "EP" holds the connection for 0 ms
    Given a coroutine "C"
      And a coroutine "N"
    One of:
      - coroutine "C" uploads 6291456 bytes to peer "EP"
      - coroutine "C" uploads 6291456 bytes to peer "EP" in 65536-byte writes
    Any of:
      - coroutine "N" sleeps 2 ms
      - coroutine "N" sleeps 6 ms
     Then counter "io_upload_ok_C" equals 1
      And counter "io_sent_bytes_C" equals 6291456
      And counter "evil_peer_read_bytes_EP" equals 6291456
      And no orphan coroutines
