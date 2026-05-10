Feature: ThreadChannel under many senders + many receivers

  Multiple producers + multiple consumers competing for the same lock. Real
  cross-thread parallelism is exercised through ThreadPool tests; here we
  hammer the channel with coroutines in one thread to force frequent
  lock/cond churn.

  Invariants:
    sum(sent) + sum(send_failed) == N * num_senders
    sum(received) + sum(recv_failed) == N * num_receivers
    after the drain, the channel is closed

  Scenario Outline: <senders> senders, <receivers> receivers, N each
    Given a thread channel "tch" with capacity <cap>
      And a coroutine "S1"
      And a coroutine "S2"
      And a coroutine "S3"
      And a coroutine "R1"
      And a coroutine "R2"
      And a coroutine "R3"
      And a coroutine "C"
     When coroutine "S1" sends <n> messages to thread channel "tch"
      And coroutine "S2" sends <n> messages to thread channel "tch"
      And coroutine "S3" sends <n> messages to thread channel "tch"
      And coroutine "R1" receives <n> messages from thread channel "tch"
      And coroutine "R2" receives <n> messages from thread channel "tch"
      And coroutine "R3" receives <n> messages from thread channel "tch"
      And coroutine "C" closes thread channel "tch"
     Then counter "tch_sent_tch" plus counter "tch_send_failed_tch" equals <total>
      And counter "tch_received_tch" plus counter "tch_recv_failed_tch" equals <total>
      And counter "tch_closed_tch" equals 1
      And no orphan coroutines

    Examples:
      | cap | n | total |
      | 1   | 2 | 6     |
      | 4   | 4 | 12    |
      | 8   | 6 | 18    |
