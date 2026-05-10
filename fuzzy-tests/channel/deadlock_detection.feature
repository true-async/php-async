Feature: Channel per-channel deadlock detection

  When a channel is created with non-zero noProducerTimeout /
  noConsumerTimeout, a per-channel deadlock timer fires if no progress is
  made within the configured window while at least one side is blocked.
  The runtime reaction is uniform: channel closes with reason DEADLOCK,
  every blocked send/recv unblocks with ChannelException, isClosed()==true.

  Hand-written baselines: tests/channel/043 (timeouts==0 → disabled),
  tests/channel/044 (timer resets between successful operations).

  Invariants for every interleaving:
    - With deadlock timeout enabled and no counterpart appearing in time:
        recv_attempts == N
        recv_failed   == N        (every blocked recv unblocks via close)
        channel "ch" is closed
    - With deadlock timeout 0 (disabled) and a normal send/recv pair:
        sent + send_failed == N
        received + recv_failed == N
        recv_attempts == N

  Scenario: stuck recv with no producer triggers deadlock close
    Given a channel "ch" with capacity 0 and deadlock timeout 100 ms
      And a coroutine "R"
     When coroutine "R" receives 1 messages from "ch"
     Then counter "recv_attempts_ch" equals 1
      And counter "recv_failed_ch" equals 1
      And channel "ch" is closed
      And no orphan coroutines

  Scenario: stuck send into full channel with no consumer
    Given a channel "ch" with capacity 0 and deadlock timeout 100 ms
      And a coroutine "S"
     When coroutine "S" sends 2 messages to "ch"
     Then counter "send_attempts_ch" equals 2
      And counter "sent_ch" plus counter "send_failed_ch" equals 2
      And counter "send_failed_ch" is at most 2
      And channel "ch" is closed
      And no orphan coroutines

  Scenario: many stuck receivers all unblock together
    Given a channel "ch" with capacity 0 and deadlock timeout 100 ms
      And a coroutine "R1"
      And a coroutine "R2"
      And a coroutine "R3"
     When coroutine "R1" receives 1 messages from "ch"
      And coroutine "R2" receives 1 messages from "ch"
      And coroutine "R3" receives 1 messages from "ch"
     Then counter "recv_attempts_ch" equals 3
      And counter "recv_failed_ch" equals 3
      And channel "ch" is closed
      And no orphan coroutines

  Scenario: timeout disabled (0) — normal send/recv pair, channel survives
    Given a channel "ch" with capacity 0
      And a coroutine "S"
      And a coroutine "R"
     When coroutine "S" sends 3 messages to "ch"
      And coroutine "R" receives 3 messages from "ch"
     Then counter "send_attempts_ch" equals 3
      And counter "recv_attempts_ch" equals 3
      And counter "sent_ch" plus counter "send_failed_ch" equals 3
      And counter "received_ch" plus counter "recv_failed_ch" equals 3
      And no orphan coroutines

  Scenario Outline: producer races deadlock — either path is fine
    Given a channel "ch" with capacity 0 and deadlock timeout <timeout> ms
      And a coroutine "S"
      And a coroutine "R"
     When coroutine "S" sleeps <delay> ms
      And coroutine "S" sends 1 messages to "ch"
      And coroutine "R" receives 1 messages from "ch"
     Then counter "send_attempts_ch" equals 1
      And counter "recv_attempts_ch" equals 1
      And counter "sent_ch" plus counter "send_failed_ch" equals 1
      And counter "received_ch" plus counter "recv_failed_ch" equals 1
      And no orphan coroutines

    Examples:
      | timeout | delay |
      | 100     | 10    |
      | 100     | 50    |
      | 100     | 200   |
