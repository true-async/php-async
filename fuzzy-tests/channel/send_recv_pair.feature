Feature: Channel send/recv pair invariants

  Scenario: 1 sender + 1 receiver, fixed N
    Given a channel "ch" with capacity 0
      And a coroutine "A"
      And a coroutine "B"
     When coroutine "A" sends 5 messages to "ch"
      And coroutine "B" receives 5 messages from "ch"
     Then counter "sent_ch" equals counter "received_ch"
      And counter "received_ch" equals 5

  Scenario Outline: 1 sender + 1 receiver, parameterised
    Given a channel "ch" with capacity <cap>
      And a coroutine "A"
      And a coroutine "B"
     When coroutine "A" sends <msgs> messages to "ch"
      And coroutine "B" receives <msgs> messages from "ch"
     Then counter "sent_ch" equals counter "received_ch"
      And counter "received_ch" equals <msgs>

    Examples:
      | cap | msgs |
      | 0   | 1    |
      | 0   | 5    |
      | 0   | 20   |
      | 1   | 5    |
      | 3   | 10   |
      | 10  | 1    |
