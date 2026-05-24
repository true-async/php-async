Feature: Signal chaos — Async\signal() under concurrent raise + cancel

  Backstop for php-async #109. The original bug was a multi-thread race
  in zend_signal_activate; the fix was to skip the legacy activate when
  ZEND_ASYNC_REACTOR_IS_ENABLED. ext/async/tests/signal/ pins the
  multi-thread happy path; this feature exercises the chaos cross-
  product (multiple waiters racing with raises, killers cancelling
  waiters mid-await) under random ChaosNet scheduling.

  Invariant per waiter: signal_attempts == signal_received +
  signal_cancelled + signal_failed. Liveness: every coroutine
  terminates, no orphans. The raiser must sleep before posix_kill so
  the Async\signal() registration on the waiter side is guaranteed to
  have run — Async\signal() is the first synchronous call in the waiter
  step, so any non-zero raiser sleep is sufficient.

  Scenario: one waiter, one raise
    Given a coroutine "W"
      And a coroutine "R"
     When coroutine "W" awaits signal SIGUSR1
      And coroutine "R" sleeps 20 ms
      And coroutine "R" raises signal SIGUSR1
     Then counter "signal_received_W" plus counter "signal_cancelled_W" plus counter "signal_failed_W" equals counter "signal_attempts_W"
      And counter "signal_received_W" is at least 1
      And coroutine "W" is completed
      And coroutine "R" is completed
      And no orphan coroutines

  Scenario: four waiters share one signal
    # posix_kill raises the signal once at the process level; every
    # registered Async\signal() waiter must wake. Under the pre-fix bug
    # zend_signal_activate could race between threads — here all
    # waiters live in one process / one reactor, so it stresses the
    # single-process waiter-list path that #109 touched.
    Given a coroutine "W1"
      And a coroutine "W2"
      And a coroutine "W3"
      And a coroutine "W4"
      And a coroutine "R"
     When coroutine "W1" awaits signal SIGUSR1
      And coroutine "W2" awaits signal SIGUSR1
      And coroutine "W3" awaits signal SIGUSR1
      And coroutine "W4" awaits signal SIGUSR1
      And coroutine "R" sleeps 30 ms
      And coroutine "R" raises signal SIGUSR1
     Then counter "signal_received_W1" plus counter "signal_cancelled_W1" plus counter "signal_failed_W1" equals counter "signal_attempts_W1"
      And counter "signal_received_W2" plus counter "signal_cancelled_W2" plus counter "signal_failed_W2" equals counter "signal_attempts_W2"
      And counter "signal_received_W3" plus counter "signal_cancelled_W3" plus counter "signal_failed_W3" equals counter "signal_attempts_W3"
      And counter "signal_received_W4" plus counter "signal_cancelled_W4" plus counter "signal_failed_W4" equals counter "signal_attempts_W4"
      And counter "signal_received_W1" is at least 1
      And counter "signal_received_W2" is at least 1
      And counter "signal_received_W3" is at least 1
      And counter "signal_received_W4" is at least 1
      And coroutine "W1" is completed
      And coroutine "W2" is completed
      And coroutine "W3" is completed
      And coroutine "W4" is completed
      And coroutine "R" is completed
      And no orphan coroutines

  Scenario: two waiters, one cancelled mid-wait
    # The killer cancels W2 before the raise lands. W1 must still
    # receive the signal; W2 must terminate via AsyncCancellation. The
    # waiter list must be left consistent so the raise that follows
    # finds exactly the survivor.
    Given a coroutine "W1"
      And a coroutine "W2"
      And a coroutine "K"
      And a coroutine "R"
     When coroutine "W1" awaits signal SIGUSR1
      And coroutine "W2" awaits signal SIGUSR1
      And coroutine "K" sleeps 15 ms
      And coroutine "K" cancels coroutine "W2"
      And coroutine "R" sleeps 40 ms
      And coroutine "R" raises signal SIGUSR1
     Then counter "signal_received_W1" plus counter "signal_cancelled_W1" plus counter "signal_failed_W1" equals counter "signal_attempts_W1"
      And counter "signal_received_W2" plus counter "signal_cancelled_W2" plus counter "signal_failed_W2" equals counter "signal_attempts_W2"
      And counter "signal_received_W1" is at least 1
      And counter "signal_cancelled_W2" is at least 1
      And coroutine "W1" is completed
      And coroutine "W2" is completed
      And coroutine "K" is completed
      And coroutine "R" is completed
      And no orphan coroutines

  Scenario: two signal kinds in parallel
    # SIGUSR1 and SIGUSR2 register separately; cross-signal interference
    # would either swap deliveries or hang one waiter forever.
    Given a coroutine "W1"
      And a coroutine "W2"
      And a coroutine "R1"
      And a coroutine "R2"
     When coroutine "W1" awaits signal SIGUSR1
      And coroutine "W2" awaits signal SIGUSR2
      And coroutine "R1" sleeps 20 ms
      And coroutine "R1" raises signal SIGUSR1
      And coroutine "R2" sleeps 25 ms
      And coroutine "R2" raises signal SIGUSR2
     Then counter "signal_received_W1" plus counter "signal_cancelled_W1" plus counter "signal_failed_W1" equals counter "signal_attempts_W1"
      And counter "signal_received_W2" plus counter "signal_cancelled_W2" plus counter "signal_failed_W2" equals counter "signal_attempts_W2"
      And counter "signal_received_W1" is at least 1
      And counter "signal_received_W2" is at least 1
      And coroutine "W1" is completed
      And coroutine "W2" is completed
      And no orphan coroutines

  Scenario Outline: raiser-delay vs cancel-delay race
    # Sweeps the relative timing of the raise vs the cancel against the
    # same waiter. Outcomes must always bucket — either the raise lands
    # first (received) or the cancel lands first (cancelled).
    Given a coroutine "W"
      And a coroutine "R"
      And a coroutine "K"
     When coroutine "W" awaits signal SIGUSR1
      And coroutine "R" sleeps <rms> ms
      And coroutine "R" raises signal SIGUSR1
      And coroutine "K" sleeps <kms> ms
      And coroutine "K" cancels coroutine "W"
     Then counter "signal_received_W" plus counter "signal_cancelled_W" plus counter "signal_failed_W" equals counter "signal_attempts_W"
      And coroutine "W" is completed
      And coroutine "R" is completed
      And coroutine "K" is completed
      And no orphan coroutines

    Examples:
      | rms | kms |
      | 5   | 25  |
      | 25  | 5   |
      | 15  | 15  |
