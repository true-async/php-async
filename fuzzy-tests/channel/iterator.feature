Feature: Channel iterator drains until close

  foreach over a Channel is the iterator-style consumer; it should yield
  every item that was successfully sent, then exit cleanly when the channel
  is closed.

  Under chaos scheduling the closer can run at any point, so the iterator
  may see fewer items than were attempted. Robust invariants:

    iterate_attempts_ch == 1 per iterating coroutine (deterministic)
    iterated_ch <= sent_ch                          (can't pull more than was pushed)
    iterated_ch + iterate_failed_ch == iterate_attempts_ch
    channel ends closed

  Scenario: sender + iterator + closer
    Given a channel "ch" with capacity 4
      And a coroutine "S"
      And a coroutine "I"
      And a coroutine "C"
     When coroutine "S" sends 5 messages to "ch"
      And coroutine "I" iterates "ch" and counts
      And coroutine "C" closes "ch"
     Then counter "iterate_attempts_ch" equals 1
      And counter "iterated_ch" is at most 5
      And channel "ch" is closed
      And no orphan coroutines

  Scenario Outline: parameterised
    Given a channel "ch" with capacity <cap>
      And a coroutine "S"
      And a coroutine "I"
      And a coroutine "C"
     When coroutine "S" sends <msgs> messages to "ch"
      And coroutine "I" iterates "ch" and counts
      And coroutine "C" closes "ch"
     Then counter "iterate_attempts_ch" equals 1
      And counter "iterated_ch" is at most <msgs>
      And channel "ch" is closed

    Examples:
      | cap | msgs |
      | 0   | 1    |
      | 0   | 10   |
      | 1   | 5    |
      | 5   | 20   |
      | 10  | 5    |
