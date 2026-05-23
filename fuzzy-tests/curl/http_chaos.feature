Feature: HTTP chaos — an async ext/curl client against a misbehaving HTTP peer

  The io/ features drive a raw-TCP client against the EvilPeer. This feature
  closes the gap tracked in issue #136: nothing exercised ext/curl under the
  random scheduler, cancellation, or a mid-response connection failure, even
  though every async curl_exec() goes through the libuv reactor.

  The peer here is the same EvilPeer in its `http` mode: it drains one HTTP
  request, then writes back an HTTP/1.1 response. The body-level toxics are
  the serve-mode ones (slicing, drip delay, abrupt close, hard reset, forked
  peer, every Toxiproxy transport toxic); on top of them sit HTTP-specific
  toxics — chunked framing, a mendacious Content-Length, dribbled headers,
  an arbitrary status code.

  Invariants, decidable regardless of interleaving:
    - a non-truncating response is de-framed back to the exact body bytes;
    - a truncating fault (mid-body close / reset / over-promised length)
      surfaces as a clean curl error, never a hang — and whatever bytes the
      write callback captured are a clean prefix of the body;
    - a cancel mid-fetch leaves the coroutine completed and unorphaned, and
      the outcome buckets sum to the attempt count;
    - the response status code is reported faithfully (a 4xx/5xx is a valid
      HTTP transaction, not a transport error).

  Scenario: a clean HTTP response arrives intact
    Given an evil HTTP peer "EP" serving 4096 bytes
      And a coroutine "C"
     When coroutine "C" fetches peer "EP" over HTTP
     Then counter "curl_get_attempts_C" equals 1
      And counter "curl_get_ok_C" equals 1
      And counter "curl_recv_bytes_C" equals 4096
      And coroutine "C" received HTTP status 200
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

  Scenario: a sliced and dripped body is reassembled exactly
    # The peer drips the body in small application-level chunks; curl must
    # reassemble the byte stream regardless of how it was fragmented.
    Given an evil HTTP peer "EP" serving 2048 bytes
      And evil peer "EP" slices output into 64-byte chunks
      And evil peer "EP" delays 2 ms between chunks
      And a coroutine "C"
     When coroutine "C" fetches peer "EP" over HTTP
     Then counter "curl_get_ok_C" equals 1
      And counter "curl_recv_bytes_C" equals 2048
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

  Scenario Outline: a chunked-encoded body is de-chunked exactly
    # Transfer-Encoding: chunked — curl must de-chunk back to the exact bytes
    # whatever the chunk size, including a 1-byte-per-chunk worst case.
    Given an evil HTTP peer "EP" serving 1500 bytes
      And evil HTTP peer "EP" uses chunked transfer encoding
      And evil peer "EP" slices output into <chunk>-byte chunks
      And a coroutine "C"
     When coroutine "C" fetches peer "EP" over HTTP
     Then counter "curl_get_ok_C" equals 1
      And counter "curl_recv_bytes_C" equals 1500
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

    Examples:
      | chunk |
      | 1     |
      | 17    |
      | 512   |

  Scenario Outline: an arbitrary status code completes the transaction
    # A 4xx/5xx is a valid HTTP response — curl finishes with errno 0 and the
    # body still arrives intact; only the reported status differs.
    Given an evil HTTP peer "EP" serving 128 bytes
      And evil HTTP peer "EP" responds with status <status>
      And a coroutine "C"
     When coroutine "C" fetches peer "EP" over HTTP
     Then counter "curl_get_ok_C" equals 1
      And coroutine "C" received HTTP status <status>
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

    Examples:
      | status |
      | 200    |
      | 404    |
      | 500    |
      | 503    |

  Scenario: slow-dribbled response headers do not corrupt the body
    # The status line and headers arrive over two TCP writes with a pause
    # between — curl's header parser must reassemble them and still hand back
    # the exact body.
    Given an evil HTTP peer "EP" serving 1024 bytes
      And evil HTTP peer "EP" delays 5 ms mid-headers
      And a coroutine "C"
     When coroutine "C" fetches peer "EP" over HTTP
     Then counter "curl_get_ok_C" equals 1
      And coroutine "C" received HTTP status 200
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

  Scenario: a peer closing mid-body surfaces as a clean partial-file error
    # Honest Content-Length, but the peer closes after only 768 of 2048 body
    # bytes. curl must report a transport error (not errno 0, not a hang) and
    # whatever the write callback captured is a clean prefix of the body.
    Given an evil HTTP peer "EP" serving 2048 bytes
      And evil peer "EP" closes abruptly after 768 bytes
      And a coroutine "C"
     When coroutine "C" fetches peer "EP" over HTTP
     Then counter "curl_get_attempts_C" equals 1
      And counter "curl_get_failed_C" equals 1
      And counter "curl_recv_bytes_C" equals 768
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines

  Scenario: an over-promised Content-Length errors without a hang
    # The peer delivers the whole body but advertises 512 bytes more. curl
    # waits for bytes that never come; when the connection closes it must
    # report a partial-file error — the body it did receive is still intact.
    Given an evil HTTP peer "EP" serving 1024 bytes
      And evil HTTP peer "EP" overstates Content-Length by 512 bytes
      And a coroutine "C"
     When coroutine "C" fetches peer "EP" over HTTP
     Then counter "curl_get_attempts_C" equals 1
      And counter "curl_get_failed_C" equals 1
      And counter "curl_recv_bytes_C" equals 1024
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines

  Scenario: an under-promised Content-Length yields a clean prefix
    # The peer advertises 256 bytes fewer than it sends; curl stops at the
    # advertised length and finishes cleanly with a prefix of the body.
    Given an evil HTTP peer "EP" serving 1024 bytes
      And evil HTTP peer "EP" understates Content-Length by 256 bytes
      And a coroutine "C"
     When coroutine "C" fetches peer "EP" over HTTP
     Then counter "curl_get_ok_C" equals 1
      And counter "curl_recv_bytes_C" equals 768
      And coroutine "C" received a clean prefix of peer "EP"
      And no orphan coroutines

  Scenario: a hard reset mid-body terminates the fetch cleanly
    # An RST (SO_LINGER 0) instead of a graceful FIN, mid-body. curl must
    # surface a transport error and the coroutine must terminate — no UAF in
    # the reactor's curl request, no hang.
    Given an evil HTTP peer "EP" serving 4096 bytes
      And evil peer "EP" slices output into 256-byte chunks
      And evil peer "EP" delays 1 ms between chunks
      And evil peer "EP" closes abruptly after 1024 bytes
      And evil peer "EP" uses a hard reset
      And a coroutine "C"
     When coroutine "C" fetches peer "EP" over HTTP
     Then counter "curl_get_attempts_C" equals 1
      And counter "curl_get_failed_C" equals 1
      And coroutine "C" received a clean prefix of peer "EP"
      And coroutine "C" is completed
      And no orphan coroutines

  Scenario Outline: cancel a coroutine mid-HTTP-fetch
    # A killer cancels the fetching coroutine while curl is parked in the
    # reactor waiting on a dripped response. The cancel must be delivered into
    # the curl wait; the coroutine terminates via its catch block. Under the
    # random scheduler the cancel can land before, during, or after the
    # transfer — so only the liveness sum and the no-hang/no-orphan invariants
    # are decidable.
    Given an evil HTTP peer "EP" serving 2048 bytes
      And evil peer "EP" slices output into 32-byte chunks
      And evil peer "EP" delays 3 ms between chunks
      And a coroutine "C"
      And a coroutine "K"
     When coroutine "C" fetches peer "EP" over HTTP
      And coroutine "K" sleeps <ms> ms
      And coroutine "K" cancels coroutine "C"
     Then counter "curl_get_ok_C" plus counter "curl_get_cancelled_C" plus counter "curl_get_failed_C" plus counter "curl_get_no_peer_C" equals counter "curl_get_attempts_C"
      And coroutine "C" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 5  |
      | 25 |
      | 80 |

  Scenario: many concurrent HTTP fetches under the random scheduler
    # Three curl clients race three evil HTTP peers, each applying a different
    # non-truncating toxic. Every transfer must still complete with its body
    # intact regardless of how the scheduler interleaves the reactor work.
    Given an evil HTTP peer "EP1" serving 1024 bytes
      And evil peer "EP1" slices output into 48-byte chunks
      And an evil HTTP peer "EP2" serving 1024 bytes
      And evil HTTP peer "EP2" uses chunked transfer encoding
      And evil peer "EP2" slices output into 96-byte chunks
      And an evil HTTP peer "EP3" serving 1024 bytes
      And evil HTTP peer "EP3" delays 4 ms mid-headers
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
     When coroutine "C1" fetches peer "EP1" over HTTP
      And coroutine "C2" fetches peer "EP2" over HTTP
      And coroutine "C3" fetches peer "EP3" over HTTP
     Then counter "curl_get_ok_C1" equals 1
      And counter "curl_get_ok_C2" equals 1
      And counter "curl_get_ok_C3" equals 1
      And coroutine "C1" received the payload of peer "EP1" intact
      And coroutine "C2" received the payload of peer "EP2" intact
      And coroutine "C3" received the payload of peer "EP3" intact
      And no orphan coroutines

  Scenario: HTTP toxics crossed with logic and scheduler chaos
    # Three chaos axes around a fixed 512-byte oracle: which non-truncating
    # HTTP toxic the peer applies, whether a sibling coroutine perturbs the
    # scheduler, and the interleaving. None of the toxics truncate, so the
    # exact-value invariant stays decidable across the whole cross-product.
    Given an evil HTTP peer "EP" serving 512 bytes
    One of:
      - evil peer "EP" slices output into 32-byte chunks
      - evil HTTP peer "EP" uses chunked transfer encoding
      - evil HTTP peer "EP" delays 3 ms mid-headers
      - evil HTTP peer "EP" responds with status 418
    Given a coroutine "C"
      And a coroutine "N"
     When coroutine "C" fetches peer "EP" over HTTP
    Any of:
      - coroutine "N" sleeps 2 ms
      - coroutine "N" sleeps 6 ms
     Then counter "curl_get_ok_C" equals 1
      And counter "curl_recv_bytes_C" equals 512
      And coroutine "C" received the payload of peer "EP" intact
      And no orphan coroutines
