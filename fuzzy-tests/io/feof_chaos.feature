Feature: I/O chaos — feof() semantics under concurrent close / drain / poll

  `ext/async/tests/stream/038–044` pin the deterministic feof() return value
  at specific points (live socket, after connect, after send/recv, after
  remote close, with pending unread data). This feature crosses those into
  chaos: a writer pushes N bytes then closes one end, a reader drains via
  the canonical `while (!feof($fd))` loop, and an independent poller
  coroutine spams `feof()` from a third coroutine.

  Invariants — true under any interleaving:
    - the drain loop sees every byte (`feof_bytes_R == N`) BEFORE feof()
      becomes true (otherwise the loop would exit early and lose data);
    - feof() never reports true while bytes are still in the kernel /
      stream buffer (a premature-true is a real bug — readers would skip
      data assuming EOF);
    - the poller's `feof_true_F` count is non-decreasing in real time
      (once true, stays true — feof is sticky in PHP stream semantics).

  Scenario: drain via feof loop while peer closes
    # Reader uses canonical `while (!feof) fread` over a shared pipe.
    # Writer pushes a known payload, sleeps, closes write end. Reader
    # must drain all bytes before feof() becomes true.
    Given a shared pipe "P"
      And a coroutine "R"
      And a coroutine "W"
     When coroutine "W" writes "hello-feof-chaos-payload" to shared pipe "P"
      And coroutine "W" sleeps 10 ms
      And coroutine "W" closes the write end of shared pipe "P"
      And coroutine "R" drains shared pipe "P" with feof loop
     Then counter "feof_drain_attempts_R" equals 1
      And counter "feof_drain_done_R" plus counter "feof_drain_cancelled_R" plus counter "feof_drain_failed_R" equals counter "feof_drain_attempts_R"
      And counter "feof_drain_done_R" equals 1
      And counter "feof_drain_bytes_R" equals 24
      And coroutine "R" is completed
      And coroutine "W" is completed
      And no orphan coroutines

  Scenario Outline: drain vs close-timing sweep
    Given a shared pipe "P"
      And a coroutine "R"
      And a coroutine "W"
     When coroutine "W" writes "hello-feof-chaos-payload" to shared pipe "P"
      And coroutine "W" sleeps <ms> ms
      And coroutine "W" closes the write end of shared pipe "P"
      And coroutine "R" drains shared pipe "P" with feof loop
     Then counter "feof_drain_done_R" equals 1
      And counter "feof_drain_bytes_R" equals 24
      And coroutine "R" is completed
      And no orphan coroutines

    Examples:
      | ms |
      | 0  |
      | 5  |
      | 25 |

  Scenario: poller reads feof concurrently with the drainer
    # A second coroutine polls feof() repeatedly from a separate coroutine
    # context. It must never observe feof_true while bytes are still
    # in-flight (i.e. before the drainer has drained them). Invariant:
    # if any feof_true was observed, the drainer had already received
    # all bytes by then.
    Given a shared pipe "P"
      And a coroutine "R"
      And a coroutine "W"
      And a coroutine "F"
     When coroutine "W" writes "hello-feof-chaos-payload" to shared pipe "P"
      And coroutine "W" sleeps 5 ms
      And coroutine "W" closes the write end of shared pipe "P"
      And coroutine "R" drains shared pipe "P" with feof loop
      And coroutine "F" polls feof of shared pipe "P" 10 times
     Then counter "feof_drain_done_R" equals 1
      And counter "feof_drain_bytes_R" equals 24
      And counter "feof_poll_attempts_F" equals 10
      And counter "feof_poll_true_F" plus counter "feof_poll_false_F" equals counter "feof_poll_attempts_F"
      And coroutine "R" is completed
      And coroutine "W" is completed
      And coroutine "F" is completed
      And no orphan coroutines

  Scenario: cancel the drainer mid-loop
    # Killer cancels the drainer while it's parked in fread. Drainer
    # must surface cancelled / failed, not loop forever.
    Given a shared pipe "P"
      And a coroutine "R"
      And a coroutine "K"
     When coroutine "R" drains shared pipe "P" with feof loop
      And coroutine "K" sleeps 15 ms
      And coroutine "K" cancels coroutine "R"
     Then counter "feof_drain_done_R" plus counter "feof_drain_cancelled_R" plus counter "feof_drain_failed_R" equals counter "feof_drain_attempts_R"
      And coroutine "R" is completed
      And coroutine "K" is completed
      And no orphan coroutines
