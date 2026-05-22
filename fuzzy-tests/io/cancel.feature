Feature: I/O chaos — cancelling a coroutine in the middle of a transfer

  A coroutine blocked in fread()/fwrite() on a libuv-driven stream must
  accept Async\AsyncCancellation delivered into the I/O wait: the reactor
  releases the underlying request (no UAF, no leaked watcher, no hang) and
  the coroutine terminates via its catch block. The download/upload routines
  bucket a mid-transfer cancel into io_*_cancelled with the partial bytes
  preserved.

  Under random scheduling a cancel can land before the transfer body reaches
  its try block, or after it already finished — so the invariant is the
  liveness sum across every outcome bucket, plus: the coroutine is completed
  and no coroutine is orphaned. The exact bucket is not decidable; that the
  buckets sum to attempts, and nothing hangs or leaks, is.

  Scenario: cancel a coroutine blocked mid-download
    # A slow drip keeps the client parked in fread() between chunks.
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" slices output into 8-byte chunks
      And evil peer "EP" delays 3 ms between chunks
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" downloads from peer "EP"
      And coroutine "K" sleeps 15 ms
      And coroutine "K" cancels coroutine "C"
     Then counter "io_download_ok_C" plus counter "io_download_cancelled_C" plus counter "io_download_failed_C" plus counter "io_download_connect_failed_C" equals counter "io_download_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario Outline: cancel delay varied against a dripping download
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" slices output into 8-byte chunks
      And evil peer "EP" delays 3 ms between chunks
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" downloads from peer "EP"
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "C"
     Then counter "io_download_ok_C" plus counter "io_download_cancelled_C" plus counter "io_download_failed_C" plus counter "io_download_connect_failed_C" equals counter "io_download_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 5  |
      | 25 |
      | 60 |

  Scenario: cancel a coroutine blocked mid-upload
    # A never-reading peer parks the writer on a full send buffer.
    Given an evil peer "EP" that never reads
      And evil peer "EP" holds the connection for 200 ms
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" uploads 8388608 bytes to peer "EP"
      And coroutine "K" sleeps 10 ms
      And coroutine "K" cancels coroutine "C"
     Then counter "io_upload_ok_C" plus counter "io_upload_cancelled_C" plus counter "io_upload_failed_C" equals counter "io_upload_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: many downloaders cancelled together
    Given an evil peer "EP1" serving 256 bytes
      And evil peer "EP1" slices output into 8-byte chunks
      And evil peer "EP1" delays 3 ms between chunks
      And an evil peer "EP2" serving 256 bytes
      And evil peer "EP2" slices output into 8-byte chunks
      And evil peer "EP2" delays 3 ms between chunks
      And an evil peer "EP3" serving 256 bytes
      And evil peer "EP3" slices output into 8-byte chunks
      And evil peer "EP3" delays 3 ms between chunks
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
      And a coroutine "K"
     When coroutine "C1" downloads from peer "EP1"
      And coroutine "C2" downloads from peer "EP2"
      And coroutine "C3" downloads from peer "EP3"
      And coroutine "K" sleeps 12 ms
      And coroutine "K" cancels coroutine "C1"
      And coroutine "K" cancels coroutine "C2"
      And coroutine "K" cancels coroutine "C3"
     Then counter "io_download_ok_C1" plus counter "io_download_cancelled_C1" plus counter "io_download_failed_C1" plus counter "io_download_connect_failed_C1" equals counter "io_download_attempts_C1"
      And counter "io_download_ok_C2" plus counter "io_download_cancelled_C2" plus counter "io_download_failed_C2" plus counter "io_download_connect_failed_C2" equals counter "io_download_attempts_C2"
      And counter "io_download_ok_C3" plus counter "io_download_cancelled_C3" plus counter "io_download_failed_C3" plus counter "io_download_connect_failed_C3" equals counter "io_download_attempts_C3"
      And coroutine "C1" is completed
      And coroutine "C2" is completed
      And coroutine "C3" is completed
      And no orphan coroutines

  Scenario: cancel a download from a forked peer
    # Cancellation must reach the I/O wait the same way whether the peer is
    # an in-process coroutine or a separate process.
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" slices output into 8-byte chunks
      And evil peer "EP" delays 3 ms between chunks
      And evil peer "EP" runs as a forked peer
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" downloads from peer "EP"
      And coroutine "K" sleeps 15 ms
      And coroutine "K" cancels coroutine "C"
     Then counter "io_download_ok_C" plus counter "io_download_cancelled_C" plus counter "io_download_failed_C" plus counter "io_download_connect_failed_C" equals counter "io_download_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: cancel during transfer crossed with scheduler chaos
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" slices output into 8-byte chunks
      And evil peer "EP" delays 3 ms between chunks
      And a coroutine "C"
      And a coroutine "K"
    One of:
      - coroutine "C" downloads from peer "EP"
      - coroutine "C" downloads from peer "EP" byte by byte
    Any of:
      - coroutine "K" sleeps 5 ms
      - coroutine "K" sleeps 20 ms
     When coroutine "K" cancels coroutine "C"
     Then counter "io_download_ok_C" plus counter "io_download_cancelled_C" plus counter "io_download_failed_C" plus counter "io_download_connect_failed_C" equals counter "io_download_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines
