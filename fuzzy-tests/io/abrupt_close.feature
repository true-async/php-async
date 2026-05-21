Feature: I/O chaos — client survives an EvilPeer that drops the connection

  The "reset" toxic makes the EvilPeer close the connection mid-stream
  after delivering only part of its payload. A correct async reactor must
  let the client observe a clean truncation — exactly the bytes that were
  delivered, forming an exact prefix of the payload — and then terminate.
  It must never hang waiting for bytes that will never arrive, nor corrupt
  the bytes it did receive.

  Invariant for every interleaving / reset point:
    the client's bytes are a prefix of the payload, and the download
    coroutine finishes (no orphan)

  Scenario: peer drops the connection after 100 of 256 bytes
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" closes abruptly after 100 bytes
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_download_ok_C" equals 1
      And counter "io_recv_bytes_C" equals 100
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines

  Scenario Outline: truncation is clean at every reset point
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" slices output into 8-byte chunks
      And evil peer "EP" closes abruptly after <at> bytes
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_download_ok_C" equals 1
      And counter "io_recv_bytes_C" equals <at>
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines

    Examples:
      | at  |
      | 0   |
      | 8   |
      | 64  |
      | 255 |

  Scenario: drip then drop — a slow peer that resets mid-stream
    Given an evil peer "EP" serving 64 bytes
      And evil peer "EP" slices output into 4-byte chunks
      And evil peer "EP" delays 2 ms between chunks
      And evil peer "EP" closes abruptly after 20 bytes
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_recv_bytes_C" equals 20
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines

  Scenario: a reset peer and an intact peer side by side
    Given an evil peer "EP1" serving 128 bytes
      And evil peer "EP1" closes abruptly after 40 bytes
      And an evil peer "EP2" serving 128 bytes
      And a coroutine "C1"
      And a coroutine "C2"
     When coroutine "C1" downloads from peer "EP1"
      And coroutine "C2" downloads from peer "EP2"
     Then counter "io_recv_bytes_C1" equals 40
      And coroutine "C1" received a clean prefix of peer "EP1"
      And counter "io_recv_bytes_C2" equals 128
      And coroutine "C2" received the payload of peer "EP2" intact
      And no orphan coroutines
