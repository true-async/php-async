Feature: I/O chaos — client survives a real RST, not just a graceful close

  The abrupt_close feature drops the connection with a plain fclose(), which
  for a peer that is only writing usually leaves an empty receive buffer and
  therefore emits a graceful FIN. This feature forces the harder case: the
  peer arms SO_LINGER{l_onoff:1,l_linger:0} so its close emits an immediate
  RST. The client then faces a real ECONNRESET — buffers may be discarded,
  a blocked read or write is torn down — instead of a clean EOF.

  An RST is so abrupt it can land before the client's stream_socket_client()
  even returns, so the download may end in connect_failed; once established
  it ends in ok (a short prefix) or failed. The decidable invariant across
  every interleaving is therefore the liveness sum plus the universal safety
  property: whatever arrived is a clean prefix of the payload, no coroutine
  is left orphaned, and nothing hangs or corrupts.

  Scenario: a hard RST mid-download leaves a clean prefix
    # The drip delay lets the client establish the connection and start
    # reading before the peer resets.
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" slices output into 8-byte chunks
      And evil peer "EP" delays 1 ms between chunks
      And evil peer "EP" closes abruptly after 100 bytes
      And evil peer "EP" uses a hard reset
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_download_ok_C" plus counter "io_download_failed_C" plus counter "io_download_connect_failed_C" equals counter "io_download_attempts_C"
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines

  Scenario Outline: a hard RST is clean at every reset point
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" slices output into 8-byte chunks
      And evil peer "EP" delays 1 ms between chunks
      And evil peer "EP" closes abruptly after <at> bytes
      And evil peer "EP" uses a hard reset
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_download_ok_C" plus counter "io_download_failed_C" plus counter "io_download_connect_failed_C" equals counter "io_download_attempts_C"
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines

    Examples:
      | at  |
      | 0   |
      | 8   |
      | 64  |
      | 255 |

  Scenario: drip then a hard RST — a slow peer that resets mid-stream
    Given an evil peer "EP" serving 64 bytes
      And evil peer "EP" slices output into 4-byte chunks
      And evil peer "EP" delays 2 ms between chunks
      And evil peer "EP" closes abruptly after 20 bytes
      And evil peer "EP" uses a hard reset
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP" byte by byte
     Then counter "io_download_ok_C" plus counter "io_download_failed_C" plus counter "io_download_connect_failed_C" equals counter "io_download_attempts_C"
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines

  Scenario: a hard RST tears down a blocked uploader
    # The peer drains 8 KiB then resets hard while the client still has ~8 MiB
    # queued. The write-wait hook must surface a clean ECONNRESET failure.
    Given an evil peer "EP" that reads 8192 bytes at a time
      And evil peer "EP" stops reading after 8192 bytes
      And evil peer "EP" uses a hard reset
      And a coroutine "C"
     When coroutine "C" uploads 8388608 bytes to peer "EP"
     Then counter "io_upload_attempts_C" equals 1
      And counter "io_upload_ok_C" equals 0
      And counter "io_upload_failed_C" equals 1
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario: a hard-RST peer and a graceful peer side by side
    Given an evil peer "EP1" serving 128 bytes
      And evil peer "EP1" slices output into 8-byte chunks
      And evil peer "EP1" delays 1 ms between chunks
      And evil peer "EP1" closes abruptly after 40 bytes
      And evil peer "EP1" uses a hard reset
      And an evil peer "EP2" serving 128 bytes
      And a coroutine "C1"
      And a coroutine "C2"
     When coroutine "C1" downloads from peer "EP1"
      And coroutine "C2" downloads from peer "EP2"
     Then counter "io_download_ok_C1" plus counter "io_download_failed_C1" plus counter "io_download_connect_failed_C1" equals counter "io_download_attempts_C1"
      And coroutine "C1" received a clean prefix of peer "EP1"
      And counter "io_recv_bytes_C2" equals 128
      And coroutine "C2" received the payload of peer "EP2" intact
      And no orphan coroutines

  Scenario: a hard RST crossed with scheduler chaos
    # A fixed 256-byte payload, hard-reset at a seeded-random offset, crossed
    # with bulk vs byte-by-byte client logic and an interleaving coroutine.
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" slices output into 8-byte chunks
      And evil peer "EP" delays 1 ms between chunks
      And evil peer "EP" uses a hard reset
    One of:
      - evil peer "EP" closes abruptly after random:256 bytes
      - evil peer "EP" closes abruptly after 128 bytes
    Given a coroutine "C"
      And a coroutine "N"
    One of:
      - coroutine "C" downloads from peer "EP"
      - coroutine "C" downloads from peer "EP" byte by byte
    Any of:
      - coroutine "N" sleeps 2 ms
      - coroutine "N" sleeps 5 ms
     Then counter "io_download_ok_C" plus counter "io_download_failed_C" plus counter "io_download_connect_failed_C" equals counter "io_download_attempts_C"
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines
