Feature: ThreadChannel cross-thread traffic under chaos scheduler

  Unlike thread_channel/basic.feature (both endpoints in the main thread),
  these scenarios put one endpoint inside a real OS-thread worker spawned
  with Async\spawn_thread(). The ThreadChannel is the only shared object;
  its lock/cond signalling carries every value across the thread boundary.

  Invariants for every interleaving:
    a worker that sends N → the main thread receives exactly N
    a worker that receives N → the main thread sends exactly N
    the worker's joined return value matches N
    closing a ThreadChannel from the main thread unblocks a worker parked
    on recv() with a ChannelException

  Scenario Outline: a worker thread sends, the main thread drains
    Given a thread channel "tc" with capacity <cap>
      And a coroutine "M"
     When coroutine "M" runs a thread that sends <n> to thread channel "tc"
     Then counter "tc_thread_send_attempts_tc" equals 1
      And counter "tc_main_received_tc" equals <n>
      And counter "tc_main_recv_failed_tc" equals 0
      And counter "tc_thread_send_ok_tc" equals 1
      And no orphan coroutines

    Examples:
      | cap | n  |
      | 1   | 1  |
      | 1   | 8  |
      | 4   | 8  |
      | 16  | 20 |

  Scenario Outline: a worker thread receives, the main thread feeds it
    Given a thread channel "tc" with capacity <cap>
      And a coroutine "M"
     When coroutine "M" runs a thread that receives <n> from thread channel "tc"
     Then counter "tc_thread_recv_attempts_tc" equals 1
      And counter "tc_main_sent_tc" equals <n>
      And counter "tc_main_send_failed_tc" equals 0
      And counter "tc_thread_recv_ok_tc" equals 1
      And no orphan coroutines

    Examples:
      | cap | n  |
      | 1   | 1  |
      | 1   | 8  |
      | 4   | 8  |
      | 16  | 20 |

  Scenario: closing from the main thread unblocks a worker parked on recv
    Given a thread channel "tc" with capacity 4
      And a coroutine "M"
     When coroutine "M" runs a thread that blocks on closed thread channel "tc"
     Then counter "tc_close_race_attempts_tc" equals 1
      And counter "tc_close_race_threw_tc" equals 1
      And counter "tc_close_race_no_throw_tc" equals 0
      And counter "tc_close_race_await_failed_tc" equals 0
      And no orphan coroutines

  Scenario: two worker threads — one sends, one receives, over one channel
    Given a thread channel "tc" with capacity 4
      And a coroutine "S"
      And a coroutine "R"
     When coroutine "S" runs a thread that sends 10 to thread channel "tc"
      And coroutine "R" runs a thread that receives 10 from thread channel "tc"
     Then counter "tc_thread_send_ok_tc" equals 1
      And counter "tc_thread_recv_ok_tc" equals 1
      And counter "tc_main_received_tc" equals 10
      And counter "tc_main_sent_tc" equals 10
      And no orphan coroutines
