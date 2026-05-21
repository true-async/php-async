Feature: I/O chaos — client reassembles a sliced EvilPeer response

  An EvilPeer accepts one connection and delivers its payload through a
  declarative fault table. The "slicer" toxic chops the byte stream into
  small chunks, optionally with a drip delay between them. Whatever the
  chunking or timing, a correct async reactor must reassemble the exact
  byte stream the peer sent.

  Invariant for every interleaving / chunk size / delay:
    the downloaded bytes equal the peer's payload, byte for byte

  Scenario: whole payload in one write — baseline
    Given an evil peer "EP" serving "hello world"
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_download_attempts_C" equals 1
      And counter "io_download_ok_C" equals 1
      And counter "io_recv_bytes_C" equals 11
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

  Scenario Outline: a sliced payload is reassembled at every chunk size
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" slices output into <chunk>-byte chunks
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
      | 256   |

  Scenario: a slow drip — one byte at a time with a delay between chunks
    Given an evil peer "EP" serving 64 bytes
      And evil peer "EP" slices output into 1-byte chunks
      And evil peer "EP" delays 2 ms between chunks
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_recv_bytes_C" equals 64
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

  Scenario: concurrent clients each drain their own sliced peer
    Given an evil peer "EP1" serving 128 bytes
      And evil peer "EP1" slices output into 3-byte chunks
      And an evil peer "EP2" serving 128 bytes
      And evil peer "EP2" slices output into 5-byte chunks
      And a coroutine "C1"
      And a coroutine "C2"
     When coroutine "C1" downloads from peer "EP1"
      And coroutine "C2" downloads from peer "EP2"
     Then coroutine "C1" received the payload of peer "EP1" intact
      And coroutine "C2" received the payload of peer "EP2" intact
      And counter "io_recv_bytes_C1" equals 128
      And counter "io_recv_bytes_C2" equals 128
      And no orphan coroutines
