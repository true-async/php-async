Feature: Channel close semantics under chaos scheduler

  Under randomized scheduling the closer coroutine may run before, between
  or after sender/receiver actions. The invariants below must hold for
  every interleaving:

    send_attempts == N        (we tried N sends)
    recv_attempts == N        (we tried N recvs)
    sent + send_failed == N   (every attempt either succeeded or threw)
    received + recv_failed == N
    closed == 1
    channel ends closed

  Scenario: closer coroutine runs concurrently with sender / receiver
    Given a channel "ch" with capacity 0
      And a coroutine "S"
      And a coroutine "R"
      And a coroutine "C"
     When coroutine "S" sends 3 messages to "ch"
      And coroutine "R" receives 3 messages from "ch"
      And coroutine "C" closes "ch"
     Then counter "send_attempts_ch" equals 3
      And counter "recv_attempts_ch" equals 3
      And counter "sent_ch" plus counter "send_failed_ch" equals 3
      And counter "received_ch" plus counter "recv_failed_ch" equals 3
      And counter "closed_ch" equals 1
      And channel "ch" is closed

  Scenario Outline: bigger pipelines
    Given a channel "ch" with capacity <cap>
      And a coroutine "S"
      And a coroutine "R"
      And a coroutine "C"
     When coroutine "S" sends <msgs> messages to "ch"
      And coroutine "R" receives <msgs> messages from "ch"
      And coroutine "C" closes "ch"
     Then counter "send_attempts_ch" equals <msgs>
      And counter "recv_attempts_ch" equals <msgs>
      And counter "sent_ch" plus counter "send_failed_ch" equals <msgs>
      And counter "received_ch" plus counter "recv_failed_ch" equals <msgs>
      And channel "ch" is closed

    Examples:
      | cap | msgs |
      | 0   | 1    |
      | 0   | 10   |
      | 1   | 5    |
      | 5   | 5    |
      | 5   | 20   |
