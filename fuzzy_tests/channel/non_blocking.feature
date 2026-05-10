Feature: Channel non-blocking send/recv (sendAsync, recvAsync, isFull)

  sendAsync(value): bool — never suspends; returns true on accept,
                          false when buffer is full or channel is closed.
  recvAsync(): Future    — returns a Future that resolves with the value
                          (or rejects with ChannelException on close).
  isFull(): bool         — true when buffered capacity is exhausted.

  Note: capacity 0 is intentionally avoided in this feature — its
  observable behaviour (rendezvous vs. 1-slot) is tracked under #108.

  Scenario: fill a buffered channel via sendAsync — last attempt fails
    Given a channel "ch" with capacity 2
      And a coroutine "F"
     When coroutine "F" tries to send 3 messages to "ch" without blocking
     Then counter "try_send_attempts_ch" equals 3
      And counter "try_send_ok_ch" equals 2
      And counter "try_send_full_ch" equals 1
      And channel "ch" is full

  Scenario: drain a full channel via recvAsync (no producer afterwards)
    Given a channel "ch" with capacity 3
      And a coroutine "F"
      And a coroutine "D"
     When coroutine "F" tries to send 3 messages to "ch" without blocking
      And coroutine "D" awaits recvAsync 3 times from "ch"
     Then counter "try_send_attempts_ch" equals 3
      And counter "try_send_ok_ch" equals 3
      And counter "async_recv_attempts_ch" equals 3
      And counter "async_received_ch" equals 3
      And no orphan coroutines

  Scenario: blocking sender + recvAsync consumer — gives full delivery
    Given a channel "ch" with capacity 2
      And a coroutine "P"
      And a coroutine "C"
     When coroutine "P" sends 5 messages to "ch"
      And coroutine "C" awaits recvAsync 5 times from "ch"
     Then counter "send_attempts_ch" equals 5
      And counter "sent_ch" equals 5
      And counter "async_recv_attempts_ch" equals 5
      And counter "async_received_ch" equals 5
      And no orphan coroutines

  Scenario Outline: blocking sender + recvAsync varying sizes
    Given a channel "ch" with capacity <cap>
      And a coroutine "P"
      And a coroutine "C"
     When coroutine "P" sends <msgs> messages to "ch"
      And coroutine "C" awaits recvAsync <msgs> times from "ch"
     Then counter "sent_ch" equals <msgs>
      And counter "async_received_ch" equals <msgs>
      And no orphan coroutines

    Examples:
      | cap | msgs |
      | 1   | 1    |
      | 1   | 5    |
      | 2   | 4    |
      | 5   | 5    |
      | 5   | 20   |

  Scenario: sendAsync on closed channel returns false
    Given a channel "ch" with capacity 1
      And a coroutine "Closer"
      And a coroutine "Sender"
     When coroutine "Closer" closes "ch"
      And coroutine "Sender" tries to send 2 messages to "ch" without blocking
     Then counter "try_send_attempts_ch" equals 2
      And counter "try_send_ok_ch" plus counter "try_send_full_ch" equals 2
      And channel "ch" is closed
      And no orphan coroutines

  Scenario: isFull flips back to false after a recv
    Given a channel "ch" with capacity 2
      And a coroutine "F"
      And a coroutine "R"
     When coroutine "F" tries to send 2 messages to "ch" without blocking
      And coroutine "R" receives 1 messages from "ch"
     Then counter "try_send_ok_ch" equals 2
      And counter "received_ch" equals 1
      And channel "ch" is not full
      And no orphan coroutines
