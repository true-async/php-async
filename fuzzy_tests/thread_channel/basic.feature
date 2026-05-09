Feature: ThreadChannel send and receive within a single thread

  Mirrors channel/send_recv_pair.feature but on ThreadChannel — same coroutine
  interaction model, exercising the cross-thread-safe send/recv path used by
  ThreadPool. Both endpoints live in the main thread; this is a smoke test for
  the lock/cond signalling logic without actual cross-thread traffic.

  Invariants:
    tch_sent_X + tch_send_failed_X == N
    tch_received_X + tch_recv_failed_X == N

  Scenario Outline: A sends N, B receives N
    Given a thread channel "tch" with capacity <cap>
      And a coroutine "A"
      And a coroutine "B"
     When coroutine "A" sends <n> messages to thread channel "tch"
      And coroutine "B" receives <n> messages from thread channel "tch"
     Then counter "tch_sent_tch" plus counter "tch_send_failed_tch" equals <n>
      And counter "tch_received_tch" plus counter "tch_recv_failed_tch" equals <n>
      And no orphan coroutines

    Examples:
      | cap | n |
      | 1   | 1 |
      | 1   | 5 |
      | 4   | 8 |
      | 16  | 8 |
