Feature: I/O chaos — transport toxics injected by Toxiproxy

  The other io features drive an in-process or forked EvilPeer: the toxics
  it can play out are application-level (when it writes, how it slices, when
  it closes). This feature adds an external Toxiproxy proxy between the
  client and the peer, so the chaos happens at the TCP transport level —
  faults a pure-PHP peer cannot reproduce precisely:

    bandwidth   — a real throughput cap (PHP can only usleep between writes)
    latency     — per-packet delay, optionally with jitter
    slicer      — chops the TCP stream into small segments
    limit_data  — closes the connection after an exact byte count
    reset_peer  — sends a TCP RST a fixed time into the connection

  Toxiproxy is opt-in: every scenario here carries a --SKIPIF-- probe and is
  skipped wherever no Toxiproxy admin endpoint answers (dev machines, per-PR
  CI). It runs only where Toxiproxy is actually up — the nightly job, or a
  local `docker run --network host ghcr.io/shopify/toxiproxy`.

  The peer-address indirection makes the proxy transparent: the client
  connects to whatever Toxiproxy hands back, exactly as it would the peer.
  So the invariants are unchanged — a non-truncating toxic must leave the
  payload byte-for-byte intact; a truncating one must leave a clean prefix.

  Scenario: a Toxiproxy pass-through proxy is transparent
    # No toxic at all — the proxy alone must not perturb the byte stream.
    Given an evil peer "EP" serving 4096 bytes
      And evil peer "EP" is fronted by Toxiproxy
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_download_ok_C" equals 1
      And counter "io_recv_bytes_C" equals 4096
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

  Scenario: a bandwidth-throttled peer still delivers the payload intact
    # A real 256 KB/s throughput cap — the payload arrives slowly but whole.
    Given an evil peer "EP" serving 4096 bytes
      And evil peer "EP" is fronted by Toxiproxy
      And Toxiproxy throttles peer "EP" to 256 KB/s
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_download_ok_C" equals 1
      And counter "io_recv_bytes_C" equals 4096
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

  Scenario Outline: latency does not corrupt the stream
    Given an evil peer "EP" serving 1024 bytes
      And evil peer "EP" is fronted by Toxiproxy
      And Toxiproxy adds <latency> ms latency to peer "EP"
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_recv_bytes_C" equals 1024
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

    Examples:
      | latency |
      | 1       |
      | 5       |
      | 20      |

  Scenario Outline: the TCP slicer stream is reassembled exactly
    # Toxiproxy slices the stream into ~<segment>-byte TCP packets — distinct
    # from EvilPeer's own application-level slicing. The client must end up
    # with the same bytes regardless of how the transport fragmented them.
    Given an evil peer "EP" serving 2048 bytes
      And evil peer "EP" is fronted by Toxiproxy
      And Toxiproxy slices peer "EP" into <segment>-byte TCP segments
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_recv_bytes_C" equals 2048
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

    Examples:
      | segment |
      | 1       |
      | 13      |
      | 256     |

  Scenario: limit_data truncates at an exact byte count
    # limit_data closes the connection once exactly N bytes have passed — a
    # deterministic truncation, so both the exact count and the clean-prefix
    # invariant are decidable.
    Given an evil peer "EP" serving 2048 bytes
      And evil peer "EP" is fronted by Toxiproxy
      And Toxiproxy cuts peer "EP" off after 768 bytes
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_download_attempts_C" equals 1
      And counter "io_download_ok_C" equals 1
      And counter "io_recv_bytes_C" equals 768
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines

  Scenario: reset_peer mid-stream leaves a clean prefix
    # A TCP RST a fixed time into the connection. The peer drips its payload
    # so delivery spans long enough for the reset to land mid-stream; the
    # exact byte count is timing-dependent, so only the universal invariants
    # hold — whatever arrived is a clean prefix, and the download finishes.
    Given an evil peer "EP" serving 2048 bytes
      And evil peer "EP" slices output into 32-byte chunks
      And evil peer "EP" delays 3 ms between chunks
      And evil peer "EP" is fronted by Toxiproxy
      And Toxiproxy resets peer "EP" after 25 ms
      And a coroutine "C"
     When coroutine "C" downloads from peer "EP"
     Then counter "io_download_attempts_C" equals 1
      And counter "io_download_ok_C" equals 1
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines

  Scenario: a throttled upload still delivers every byte
    # Back-pressure path: the consume peer drains the client's upload, with
    # Toxiproxy capping the upstream throughput. fwrite() suspends on a full
    # send buffer far longer, but every byte still gets through.
    Given an evil peer "EP" that reads 65536 bytes at a time
      And evil peer "EP" is fronted by Toxiproxy
      And Toxiproxy throttles peer "EP" to 256 KB/s
      And a coroutine "C"
     When coroutine "C" uploads 65536 bytes to peer "EP"
     Then counter "io_upload_attempts_C" equals 1
      And counter "io_upload_ok_C" equals 1
      And counter "io_sent_bytes_C" equals 65536
      And no orphan coroutines

  Scenario: transport toxics crossed with logic and scheduler chaos
    # Three chaos axes around a fixed 256-byte oracle: which non-truncating
    # transport toxic Toxiproxy applies, what the client does, and the
    # scheduler interleaving. None of the toxics truncate, so the exact-value
    # invariant stays decidable across the whole cross-product.
    Given an evil peer "EP" serving 256 bytes
      And evil peer "EP" is fronted by Toxiproxy
    One of:
      - Toxiproxy adds 2 ms latency to peer "EP"
      - Toxiproxy adds 3 ms latency with 2 ms jitter to peer "EP"
      - Toxiproxy throttles peer "EP" to 128 KB/s
      - Toxiproxy slices peer "EP" into random:32-byte TCP segments
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
