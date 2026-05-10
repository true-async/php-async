Feature: Shutdown with pending coroutines

  When a request ends with coroutines still suspended on resources
  (channel recv, future await, long delay), the runtime cleanup must
  unblock or cancel them without crash, hang, or leak. Test simulates
  the shutdown phase via an explicit cancel sweep over non-awaited
  coroutines, then runs the harness's normal drain.

  Invariants for every interleaving:
    coroutine "X" is completed
    no orphan coroutines

  Note: under the safe-scope rules, plain $scope->cancel()/dispose()
  does NOT deliver AsyncCancellation to delay-blocked or recv-blocked
  children. That's why the harness uses a direct $coro->cancel() sweep
  on every non-awaited handle — that path is the one that does deliver.

  Scenario: pending coroutine blocked on channel recv
    Given a channel "ch" with capacity 1
      And a non-awaited coroutine "R"
     When coroutine "R" receives 1 messages from "ch"
     Then coroutine "R" is completed
      And counter "received_ch" plus counter "recv_failed_ch" equals counter "recv_attempts_ch"
      And no orphan coroutines

  Scenario: pending coroutine blocked on future await
    Given a future "F"
      And a non-awaited coroutine "A"
     When coroutine "A" awaits future "F"
     Then coroutine "A" is completed
      And counter "awaited_F" plus counter "await_failed_F" equals counter "await_attempts_F"
      And no orphan coroutines

  Scenario: pending coroutine in long delay
    Given a non-awaited coroutine "S"
     When coroutine "S" sleeps 3600000 ms
     Then coroutine "S" is completed
      And no orphan coroutines

  Scenario: many pending coroutines on different resources
    Given a channel "ch" with capacity 1
      And a future "F"
      And a non-awaited coroutine "R1"
      And a non-awaited coroutine "R2"
      And a non-awaited coroutine "A"
      And a non-awaited coroutine "S"
     When coroutine "R1" receives 1 messages from "ch"
      And coroutine "R2" receives 1 messages from "ch"
      And coroutine "A" awaits future "F"
      And coroutine "S" sleeps 3600000 ms
     Then coroutine "R1" is completed
      And coroutine "R2" is completed
      And coroutine "A" is completed
      And coroutine "S" is completed
      And no orphan coroutines

  Scenario: pending coroutine alongside completing one
    Given a channel "ch" with capacity 1
      And a non-awaited coroutine "Pending"
      And a coroutine "Active"
     When coroutine "Pending" receives 1 messages from "ch"
      And coroutine "Active" prints "active"
     Then coroutine "Pending" is completed
      And coroutine "Active" is completed
      And no orphan coroutines
