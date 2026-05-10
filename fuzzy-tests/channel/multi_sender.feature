Feature: Multiple senders and receivers on one channel

  N senders push messages, M receivers pull. Total attempts are deterministic;
  total successes + failures must equal total attempts on each side, regardless
  of how the scheduler interleaves them.

  Scenario: 3 senders, 2 receivers, no close
    Given a channel "ch" with capacity 2
      And a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "S3"
      And a coroutine "R1"
      And a coroutine "R2"
     When coroutine "S1" sends 4 messages to "ch"
      And coroutine "S2" sends 4 messages to "ch"
      And coroutine "S3" sends 4 messages to "ch"
      And coroutine "R1" receives 6 messages from "ch"
      And coroutine "R2" receives 6 messages from "ch"
     Then counter "send_attempts_ch" equals 12
      And counter "recv_attempts_ch" equals 12
      And counter "sent_ch" plus counter "send_failed_ch" equals 12
      And counter "received_ch" plus counter "recv_failed_ch" equals 12

  Scenario Outline: parameterised senders / receivers
    Given a channel "ch" with capacity <cap>
      And a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "R1"
      And a coroutine "R2"
     When coroutine "S1" sends <each> messages to "ch"
      And coroutine "S2" sends <each> messages to "ch"
      And coroutine "R1" receives <each> messages from "ch"
      And coroutine "R2" receives <each> messages from "ch"
     Then counter "sent_ch" plus counter "send_failed_ch" equals counter "send_attempts_ch"
      And counter "received_ch" plus counter "recv_failed_ch" equals counter "recv_attempts_ch"

    Examples:
      | cap | each |
      | 0   | 1    |
      | 0   | 5    |
      | 1   | 5    |
      | 3   | 5    |
      | 10  | 10   |
