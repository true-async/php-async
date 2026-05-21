Feature: Pool acquire/release + circuit breaker under chaos scheduler

  Async\Pool is a reusable-resource pool that also implements the
  CircuitBreaker interface:

    acquire(timeout) / tryAcquire() / release(resource)
    count() / idleCount() / activeCount()  — count == idle + active
    getState() / activate() / deactivate() / recover()  — circuit breaker
    setCircuitBreakerStrategy(strategy)    — attach failure-detection hook

  A CircuitBreakerStrategy is driven by the runtime: reportSuccess fires on
  a clean release, reportFailure when beforeRelease rejects a resource.

  Invariants for every interleaving:
    pool_acquired == pool_released  when nothing throws
    count() == idleCount() + activeCount()  at every sampled instant
    the circuit breaker cycles ACTIVE -> INACTIVE -> RECOVERING -> ACTIVE
    every acquired resource is balanced by a release, so activeCount
    returns to 0 once all coroutines finish

  Scenario Outline: one coroutine acquires and releases resources
    Given a pool "P" with min <min> and max <max>
      And a coroutine "W"
     When coroutine "W" acquires and releases <n> resources from pool "P"
      And coroutine "W" inspects pool "P" counts
     Then counter "pool_acquired_P" equals <n>
      And counter "pool_released_P" equals <n>
      And counter "pool_acquire_failed_P" equals 0
      And counter "pool_counts_ok_P" equals 1
      And counter "pool_counts_bad_P" equals 0
      And pool "P" active count equals 0
      And no orphan coroutines

    Examples:
      | min | max | n |
      | 0   | 10  | 1 |
      | 0   | 4   | 5 |
      | 2   | 8   | 6 |

  Scenario: concurrent workers contend for a small pool
    Given a pool "P" with min 0 and max 2
      And a coroutine "W1"
      And a coroutine "W2"
      And a coroutine "W3"
      And a coroutine "W4"
     When coroutine "W1" acquires and releases 3 resources from pool "P"
      And coroutine "W2" acquires and releases 3 resources from pool "P"
      And coroutine "W3" acquires and releases 3 resources from pool "P"
      And coroutine "W4" acquires and releases 3 resources from pool "P"
     Then counter "pool_acquired_P" equals 12
      And counter "pool_released_P" equals 12
      And counter "pool_acquire_failed_P" equals 0
      And pool "P" active count equals 0
      And no orphan coroutines

  Scenario: tryAcquire never blocks and stays balanced
    Given a pool "P" with min 0 and max 4
      And a coroutine "A"
      And a coroutine "B"
     When coroutine "A" tries to acquire from pool "P"
      And coroutine "B" tries to acquire from pool "P"
     Then counter "pool_try_attempts_P" equals 2
      And counter "pool_try_got_P" plus counter "pool_try_null_P" equals 2
      And counter "pool_try_failed_P" equals 0
      And pool "P" active count equals 0
      And no orphan coroutines

  Scenario: many count inspectors all see count == idle + active
    Given a pool "P" with min 3 and max 6
      And a coroutine "W"
      And a coroutine "I1"
      And a coroutine "I2"
      And a coroutine "I3"
     When coroutine "W" acquires and releases 4 resources from pool "P"
      And coroutine "I1" inspects pool "P" counts
      And coroutine "I2" inspects pool "P" counts
      And coroutine "I3" inspects pool "P" counts
     Then counter "pool_counts_attempts_P" equals 3
      And counter "pool_counts_ok_P" equals 3
      And counter "pool_counts_bad_P" equals 0
      And no orphan coroutines

  Scenario: circuit breaker state machine cycles cleanly
    Given a pool "P"
      And a coroutine "C"
     When coroutine "C" cycles the circuit breaker of pool "P"
     Then counter "cb_cycle_attempts_P" equals 1
      And counter "cb_cycle_ok_P" equals 1
      And counter "cb_cycle_bad_P" equals 0
      And pool "P" circuit state is ACTIVE
      And no orphan coroutines

  Scenario: many coroutines cycle the breaker — every cycle is exact
    Given a pool "P"
      And a coroutine "C1"
      And a coroutine "C2"
      And a coroutine "C3"
     When coroutine "C1" cycles the circuit breaker of pool "P"
      And coroutine "C2" cycles the circuit breaker of pool "P"
      And coroutine "C3" cycles the circuit breaker of pool "P"
     Then counter "cb_cycle_ok_P" equals 3
      And counter "cb_cycle_bad_P" equals 0
      And pool "P" circuit state is ACTIVE
      And no orphan coroutines

  Scenario: a recording strategy sees reportSuccess on every clean release
    Given a pool "P" with min 1 and max 4
      And a coroutine "S"
      And a coroutine "W"
     When coroutine "S" attaches a recording strategy to pool "P"
      And coroutine "W" acquires and releases 3 resources from pool "P"
     Then counter "cb_strategy_attached_P" equals 1
      And counter "cb_success_P" equals 3
      And counter "cb_failure_P" equals 0
      And pool "P" active count equals 0
      And no orphan coroutines

  Scenario: a rejecting pool drives reportFailure on the strategy
    Given a pool "P" that rejects release
      And a coroutine "S"
      And a coroutine "W"
     When coroutine "S" attaches a recording strategy to pool "P"
      And coroutine "W" acquires and releases 2 resources from pool "P"
     Then counter "cb_strategy_attached_P" equals 1
      And counter "cb_failure_P" equals 2
      And counter "cb_success_P" equals 0
      And no orphan coroutines

  Scenario: a strategy can be attached then detached
    Given a pool "P" with min 1 and max 4
      And a coroutine "S"
     When coroutine "S" attaches a recording strategy to pool "P"
      And coroutine "S" detaches the strategy from pool "P"
     Then counter "cb_strategy_attached_P" equals 1
      And counter "cb_strategy_detached_P" equals 1
      And no orphan coroutines
