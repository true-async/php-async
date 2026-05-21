Feature: Channel and ThreadChannel capacity() under chaos scheduler

  capacity() reports the buffer size fixed at construction. It is constant
  for the object's whole lifetime — it does not change as messages are
  buffered or drained, and it survives close().

  Scenario Outline: a buffered Channel reports its construction capacity
    Given a channel "ch" with capacity <cap>
      And a coroutine "P"
      And a coroutine "C"
     When coroutine "P" sends <cap> messages to "ch"
      And coroutine "C" receives <cap> messages from "ch"
     Then channel "ch" capacity equals <cap>
      And no orphan coroutines

    Examples:
      | cap |
      | 1   |
      | 2   |
      | 5   |
      | 16  |

  Scenario: an unbuffered Channel reports capacity 0
    Given a channel "ch" with capacity 0
      And a coroutine "P"
      And a coroutine "C"
     When coroutine "P" sends 3 messages to "ch"
      And coroutine "C" receives 3 messages from "ch"
     Then channel "ch" capacity equals 0
      And no orphan coroutines

  Scenario: capacity survives close
    Given a channel "ch" with capacity 4
      And a coroutine "Closer"
     When coroutine "Closer" closes "ch"
     Then channel "ch" is closed
      And channel "ch" capacity equals 4
      And no orphan coroutines

  Scenario Outline: a ThreadChannel reports its construction capacity
    Given a thread channel "tc" with capacity <cap>
      And a coroutine "P"
      And a coroutine "C"
     When coroutine "P" sends <cap> messages to thread channel "tc"
      And coroutine "C" receives <cap> messages from thread channel "tc"
     Then thread channel "tc" capacity equals <cap>
      And no orphan coroutines

    Examples:
      | cap |
      | 1   |
      | 8   |
      | 16  |
