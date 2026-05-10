Feature: ThreadChannel close behaviour

  Closing a ThreadChannel must wake every blocked sender/receiver and convert
  subsequent send/recv calls into ThreadChannelException. recv() must drain
  buffered values before raising.

  Invariants under chaos scheduling:
    tch_sent_X + tch_send_failed_X == sends_attempted
    tch_received_X + tch_recv_failed_X == recvs_attempted

  Scenario Outline: closer races senders and receivers
    Given a thread channel "tch" with capacity <cap>
      And a coroutine "A"
      And a coroutine "B"
      And a coroutine "C"
     When coroutine "A" sends <n> messages to thread channel "tch"
      And coroutine "B" receives <n> messages from thread channel "tch"
      And coroutine "C" closes thread channel "tch"
     Then counter "tch_closed_tch" equals 1
      And counter "tch_sent_tch" plus counter "tch_send_failed_tch" equals <n>
      And counter "tch_received_tch" plus counter "tch_recv_failed_tch" equals <n>
      And no orphan coroutines

    Examples:
      | cap | n |
      | 1   | 4 |
      | 4   | 8 |

  Scenario: send/recv after close — every operation fails
    Given a thread channel "tch" with capacity 4
      And a coroutine "C"
      And a coroutine "A"
      And a coroutine "B"
     When coroutine "C" closes thread channel "tch"
      And coroutine "A" sends 3 messages to thread channel "tch"
      And coroutine "B" receives 3 messages from thread channel "tch"
     Then counter "tch_closed_tch" equals 1
      And counter "tch_sent_tch" plus counter "tch_send_failed_tch" equals 3
      And counter "tch_received_tch" plus counter "tch_recv_failed_tch" equals 3
      And no orphan coroutines
