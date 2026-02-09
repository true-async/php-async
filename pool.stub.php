<?php

/**
 * @generate-class-entries
 */

namespace Async;

class PoolException extends AsyncException {}

/**
 * Resource pool with automatic lifecycle management.
 *
 * Similar to channel but resources are reusable - they circulate
 * between idle buffer and active usage.
 *
 * Implements CircuitBreaker for service availability control.
 */
final class Pool implements \Countable, CircuitBreaker
{
    /**
     * Create a new resource pool.
     *
     * @param callable $factory Creates a new resource: fn(): mixed
     * @param callable|null $destructor Destroys a resource: fn(mixed $resource): void
     * @param callable|null $healthcheck Background check if resource is alive: fn(mixed $resource): bool
     * @param callable|null $beforeAcquire Check before each acquire: fn(mixed $resource): bool (false = destroy and get next)
     * @param callable|null $beforeRelease Called when resource is returned: fn(mixed $resource): bool (false = destroy, don't return to pool)
     * @param int $min Minimum idle resources (pre-created on startup)
     * @param int $max Maximum total resources (idle + active)
     * @param int $healthcheckInterval Background healthcheck interval (ms, 0 = disabled)
     */
    public function __construct(
        callable $factory,
        ?callable $destructor = null,
        ?callable $healthcheck = null,
        ?callable $beforeAcquire = null,
        ?callable $beforeRelease = null,
        int $min = 0,
        int $max = 10,
        int $healthcheckInterval = 0,
    ) {}

    /**
     * Acquire a resource (blocking).
     *
     * Waits if no resource available and pool is at max capacity.
     *
     * @param int $timeout Max wait time in ms (0 = infinite)
     * @return mixed The acquired resource
     * @throws PoolException If pool is closed or timeout
     */
    public function acquire(int $timeout = 0): mixed {}

    /**
     * Try to acquire a resource (non-blocking).
     *
     * Returns immediately, even if no resource available.
     *
     * @return mixed|null Resource or null if none available
     */
    public function tryAcquire(): mixed {}

    /**
     * Release a resource back to the pool.
     *
     * Calls beforeRelease callback if set.
     * If beforeRelease returns false, resource is destroyed instead of returned to pool.
     * IMPORTANT: Always release resources when done!
     *
     * @param mixed $resource The resource to release
     */
    public function release(mixed $resource): void {}

    /**
     * Close the pool and destroy all resources.
     *
     * Wakes all waiting coroutines with PoolException.
     */
    public function close(): void {}

    /**
     * Check if pool is closed.
     */
    public function isClosed(): bool {}

    /**
     * Get total resource count (idle + active).
     */
    public function count(): int {}

    /**
     * Get idle (available) resource count.
     */
    public function idleCount(): int {}

    /**
     * Get active (in-use) resource count.
     */
    public function activeCount(): int {}

    /**
     * Set circuit breaker strategy.
     *
     * When set, the strategy controls service availability:
     * - isAvailable() checked before acquire
     * - reportSuccess()/reportFailure() called based on release status
     */
    public function setCircuitBreakerStrategy(?CircuitBreakerStrategy $strategy): void {}

    /**
     * Get current circuit breaker state.
     */
    public function getState(): CircuitBreakerState {}

    /**
     * Transition to ACTIVE state.
     */
    public function activate(): void {}

    /**
     * Transition to INACTIVE state.
     */
    public function deactivate(): void {}

    /**
     * Transition to RECOVERING state.
     */
    public function recover(): void {}
}
