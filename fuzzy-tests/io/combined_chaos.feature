Feature: I/O chaos crossed with logic chaos — fixed payload, decidable oracle

  The payload and protocol are FIXED — a known 256-byte string — so the
  correct outcome is always known. Three independent chaos axes vary
  around that fixed oracle:

    transport chaos — which toxics the EvilPeer applies, with seeded-random
                      parameters (Any of: / One of: mutation blocks)
    logic chaos     — what the client program does (mutation blocks)
    scheduler chaos — coroutine interleaving (TRUE_ASYNC_SCHED=random)

  The generator cross-products the mutation blocks: every (transport x
  logic) pair becomes its own .phpt, run across CHAOS_GEN_SEED values and
  scheduler seeds. Because the payload is fixed the invariant stays
  decidable on the WHOLE product — that is the point of crossing the
  low-level I/O chaos with the logic chaos.

  Scenario: a 256-byte payload survives every transport x logic combination
    # Transport: any subset of {slice, drip} with seeded-random parameters.
    # Logic: bulk read or byte-by-byte read. Neither truncates, so the
    # exact-value invariant is decidable across the whole cross-product.
    Given an evil peer "EP" serving 256 bytes
    Any of:
      - evil peer "EP" slices output into random:48-byte chunks
      - evil peer "EP" delays 1|3 ms between chunks
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

  Scenario: truncating transport x logic — only universal invariants hold
    # Transport now MAY reset at a seeded-random offset, so the exact byte
    # count is no longer known. The invariant drops to the universal pair:
    # whatever arrived is a clean prefix of the fixed payload, and the
    # download coroutine always finishes.
    Given an evil peer "EP" serving 256 bytes
    One of:
      - evil peer "EP" slices output into random:64-byte chunks
      - evil peer "EP" closes abruptly after random:256 bytes
    Given a coroutine "C"
    One of:
      - coroutine "C" downloads from peer "EP"
      - coroutine "C" downloads from peer "EP" byte by byte
     Then counter "io_download_attempts_C" equals 1
      And counter "io_download_ok_C" equals 1
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines
