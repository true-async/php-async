Feature: Multiple iterators consuming one channel concurrently

  Several coroutines all foreach the same Channel. Each delivered item
  goes to exactly one iterator (first-come-first-served). The total of
  iterated_ch across consumers equals what was sent before close.

  Invariants in every interleaving:
    sent_ch + send_failed_ch == N
    iterated_ch == sent_ch                (each delivered item counted once)
    no orphan coroutines
    channel ends closed

  Scenario Outline: M iterators, N items
    Given a channel "ch" with capacity <cap>
      And a coroutine "P"
      And a coroutine "I1"
      And a coroutine "I2"
      And a coroutine "Closer"
     When coroutine "P" sends <n> messages to "ch"
      And coroutine "I1" iterates "ch" and counts
      And coroutine "I2" iterates "ch" and counts
      And coroutine "Closer" closes "ch"
     Then counter "sent_ch" plus counter "send_failed_ch" equals <n>
      And counter "iterated_ch" equals counter "sent_ch"
      And channel "ch" is closed
      And no orphan coroutines

    Examples:
      | cap | n  |
      | 0   | 5  |
      | 1   | 8  |
      | 4   | 16 |

  Scenario: three iterators race on a small buffer
    Given a channel "ch" with capacity 2
      And a coroutine "P"
      And a coroutine "I1"
      And a coroutine "I2"
      And a coroutine "I3"
      And a coroutine "Closer"
     When coroutine "P" sends 12 messages to "ch"
      And coroutine "I1" iterates "ch" and counts
      And coroutine "I2" iterates "ch" and counts
      And coroutine "I3" iterates "ch" and counts
      And coroutine "Closer" closes "ch"
     Then counter "sent_ch" plus counter "send_failed_ch" equals 12
      And counter "iterated_ch" equals counter "sent_ch"
      And channel "ch" is closed
      And no orphan coroutines
