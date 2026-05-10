Feature: Channel owned by a scope — disposing the scope closes the channel

  A Channel constructed inside a scope-bound coroutine is owned by that
  scope. When the scope is disposed (or cancelled), the runtime fires
  the owner-scope-end callback and the channel closes with reason
  SCOPE_DISPOSED — every blocked send/recv unblocks with ChannelException.
  Other scopes are unaffected.

  Scope-owned channels in this harness: a creator coroutine inside the
  owner scope constructs the Channel and exits; the Channel object is
  kept alive by the harness map. Receivers / senders consuming the
  channel live OUTSIDE the owner scope (in main_scope or another scope)
  so they outlast the owner-scope dispose and observe the close.

  Hand-written baselines: tests/channel/{049,057,058}.

  Note: capacity 0 is intentionally avoided — its observable semantics
  are tracked under #108.

  Invariants for every interleaving:
    - After scope dispose, the owner-scope channel is closed.
    - Each blocked recv sees exactly one outcome: success or
      ChannelException → received_ch / recv_failed_ch counter.

  Scenario: blocked recv on scope-owned channel unblocks on dispose
    Given a scope "S"
      And a channel "ch" with capacity 1 owned by scope "S"
      And a coroutine "R"
      And a coroutine "Killer"
     When coroutine "R" receives 1 messages from "ch"
      And coroutine "Killer" disposes scope "S"
     Then channel "ch" is closed
      And counter "recv_attempts_ch" equals 1
      And counter "received_ch" plus counter "recv_failed_ch" equals 1
      And no orphan coroutines

  Scenario: many receivers on scope-owned channel — all unblock together
    Given a scope "S"
      And a channel "ch" with capacity 1 owned by scope "S"
      And a coroutine "R1"
      And a coroutine "R2"
      And a coroutine "R3"
      And a coroutine "Killer"
     When coroutine "R1" receives 1 messages from "ch"
      And coroutine "R2" receives 1 messages from "ch"
      And coroutine "R3" receives 1 messages from "ch"
      And coroutine "Killer" disposes scope "S"
     Then channel "ch" is closed
      And counter "recv_attempts_ch" equals 3
      And counter "received_ch" plus counter "recv_failed_ch" equals 3
      And no orphan coroutines

  Scenario: blocked send on full scope-owned channel unblocks on dispose
    Given a scope "S"
      And a channel "ch" with capacity 1 owned by scope "S"
      And a coroutine "P"
      And a coroutine "Killer"
     When coroutine "P" sends 3 messages to "ch"
      And coroutine "Killer" disposes scope "S"
     Then channel "ch" is closed
      And counter "send_attempts_ch" equals 3
      And counter "sent_ch" plus counter "send_failed_ch" equals 3
      And no orphan coroutines

  Scenario: two scopes, two channels — disposing one leaves the other alone
    Given a scope "A"
      And a scope "B"
      And a channel "cha" with capacity 1 owned by scope "A"
      And a channel "chb" with capacity 1 owned by scope "B"
      And a coroutine "Ra"
      And a coroutine "Sb"
      And a coroutine "Rb"
      And a coroutine "Killer"
     When coroutine "Ra" receives 1 messages from "cha"
      And coroutine "Sb" sends 1 messages to "chb"
      And coroutine "Rb" receives 1 messages from "chb"
      And coroutine "Killer" disposes scope "A"
     Then channel "cha" is closed
      And counter "recv_attempts_cha" equals 1
      And counter "received_cha" plus counter "recv_failed_cha" equals 1
      And counter "send_attempts_chb" equals 1
      And counter "recv_attempts_chb" equals 1
      And no orphan coroutines

  Scenario Outline: vary capacity of scope-owned channel
    Given a scope "S"
      And a channel "ch" with capacity <cap> owned by scope "S"
      And a coroutine "R"
      And a coroutine "Killer"
     When coroutine "R" receives <msgs> messages from "ch"
      And coroutine "Killer" disposes scope "S"
     Then channel "ch" is closed
      And counter "recv_attempts_ch" equals <msgs>
      And counter "received_ch" plus counter "recv_failed_ch" equals <msgs>
      And no orphan coroutines

    Examples:
      | cap | msgs |
      | 1   | 1    |
      | 1   | 3    |
      | 5   | 2    |
      | 5   | 10   |
