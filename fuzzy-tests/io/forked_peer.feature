Feature: I/O chaos — the client faces a peer running in a separate process

  The other io features run the EvilPeer in-process: a coroutine sharing the
  test's reactor. This feature runs it as a forked peer — a genuinely
  independent OS process (proc_open) with its own TCP stack endpoint, no
  shared event loop, real kernel scheduling between the two ends.

  The same fault table applies, so the same invariants must hold: whatever
  toxics the peer plays out, the client reassembles the byte stream exactly,
  a dropped connection yields a clean prefix, and no coroutine is orphaned.
  Only the surrounding accept/listen plumbing differs from the in-process
  peer — which is the point: it must not matter to the client.

  Scenario: a forked peer serves its payload intact
    Given an evil peer "EP" serving "hello from another process"
      And evil peer "EP" runs as a forked peer
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_download_attempts_C" equals 1
      And counter "io_download_ok_C" equals 1
      And counter "io_recv_bytes_C" equals 26
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

  Scenario Outline: a forked peer's sliced payload is reassembled
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" slices output into <chunk>-byte chunks
      And evil peer "EP" runs as a forked peer
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_download_ok_C" equals 1
      And counter "io_recv_bytes_C" equals 256
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

    Examples:
      | chunk |
      | 1     |
      | 7     |
      | 64    |

  Scenario: a slow forked peer drips its payload chunk by chunk
    Given an evil peer "EP" serving 64 bytes
      And evil peer "EP" slices output into 8-byte chunks
      And evil peer "EP" delays 2 ms between chunks
      And evil peer "EP" runs as a forked peer
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_recv_bytes_C" equals 64
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

  Scenario: a forked peer that drops the connection leaves a clean prefix
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" slices output into 8-byte chunks
      And evil peer "EP" closes abruptly after 96 bytes
      And evil peer "EP" runs as a forked peer
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_recv_bytes_C" equals 96
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines

  Scenario: a forked consume peer drains a client upload
    Given an evil peer "EP" that reads 65536 bytes at a time
      And evil peer "EP" runs as a forked peer
      And a coroutine "C"
     When coroutine "C" uploads 1048576 bytes to peer "EP"
     Then counter "io_upload_attempts_C" equals 1
      And counter "io_upload_ok_C" equals 1
      And counter "io_sent_bytes_C" equals 1048576
      And no orphan coroutines

  Scenario: a forked peer crossed with scheduler chaos
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" runs as a forked peer
    One of:
      - evil peer "EP" slices output into random:32-byte chunks
      - evil peer "EP" slices output into 64-byte chunks
    Given a coroutine "C"
      And a coroutine "N"
    One of:
      - coroutine "C" downloads from peer "EP"
      - coroutine "C" downloads from peer "EP" byte by byte
    Any of:
      - coroutine "N" sleeps 2 ms
      - coroutine "N" sleeps 5 ms
     Then counter "io_download_ok_C" equals 1
      And counter "io_recv_bytes_C" equals 256
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines
