<?php

/** @generate-class-entries */

namespace Async;

interface Awaitable {}

interface Completable extends Awaitable {
    public function cancel(?AsyncCancellation $cancellation = null): void;
    public function isCompleted(): bool;
    public function isCancelled(): bool;
}

final class Timeout implements Completable
{
    private function __construct() {}

    public function cancel(?AsyncCancellation $cancellation = null): void {}

    public function isCompleted(): bool {}

    public function isCancelled(): bool {}
}

/**
 * Returns the current Coroutine.
 *
 * @return Coroutine
 */
function spawn(callable $task, mixed ... $args): Coroutine {}

/**
 * Returns the current Coroutine.
 *
 * @return Coroutine
 */
function spawn_with(ScopeProvider $provider, callable $task, mixed ... $args): Coroutine {}

/**
 * Suspends the execution of a Coroutine until the Scheduler takes control.
 */
function suspend(): void {}

/**
 * Execute the provided closure in non-cancellable mode.
 */
function protect(\Closure $closure): mixed {}

function await(Completable $awaitable, ?Completable $cancellation = null): mixed {}

function await_any_or_fail(iterable $triggers, ?Awaitable $cancellation = null): mixed {}

function await_first_success(iterable $triggers, ?Awaitable $cancellation = null): mixed {}

function await_all_or_fail(iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true): array {}

function await_all(iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true, bool $fillNull = false): array {}

function await_any_of_or_fail(int $count, iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true): array {}

function await_any_of(int $count, iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true, bool $fillNull = false): array {}

function delay(int $ms): void {}

function timeout(int $ms): Awaitable {}

function current_context(): Context {}

function coroutine_context(): Context {}

/**
 * Returns the current coroutine.
 */
function current_coroutine(): Coroutine {}

/**
 * Adds a finally handler for the current coroutine.
 */
//function finally(\Closure $callback): void {}

/**
 * Returns the root Scope.
 */
function root_context(): Context {}

/**
 * Returns the list of all coroutines
 *
 * @return Coroutine[]
 */
function get_coroutines(): array {}

/**
 * Iterates over an iterable, calling the callback for each element.
 * The callback receives (value, key) and may return false to stop iteration.
 * Blocks the current coroutine until all iterations complete.
 *
 * If cancelPending is true (default), coroutines spawned inside the callback
 * will be cancelled when the iteration finishes. If false, iterate() will
 * wait for all spawned coroutines to complete before returning.
 */
function iterate(iterable $iterable, callable $callback, int $concurrency = 0, bool $cancelPending = true): void {}

/**
 * Start the graceful shutdown of the Scheduler.
 */
function graceful_shutdown(?AsyncCancellation $cancellationError = null): void {}

/**
 * Execute an external program.
 * @return Future<array{string, int}>
 */
/*
function exec(
    string $command,
    int $timeout        = 0,
    ?string $cwd        = null,
    ?array $env         = null,
    bool $returnAll     = false
): Future {}
*/


/**
 * OS signal identifiers.
 */
enum Signal: int
{
    case SIGHUP   = 1;
    case SIGINT   = 2;
    case SIGQUIT  = 3;
    case SIGILL   = 4;
    case SIGABRT  = 6;
    case SIGFPE   = 8;
    case SIGKILL  = 9;
    case SIGUSR1  = 10;
    case SIGSEGV  = 11;
    case SIGUSR2  = 12;
    case SIGTERM  = 15;
    case SIGBREAK = 21;
    case SIGABRT2 = 22;
    case SIGWINCH = 28;
}

/**
 * Wait for an OS signal.
 * Returns a Future that resolves with the Signal enum value when the signal is received.
 *
 * @return Future<Signal>
 */
function signal(Signal $signal, ?Completable $cancellation = null): Future {}

/**
 * Circuit breaker states.
 */
enum CircuitBreakerState
{
    /**
     * Service is working normally.
     * All requests are allowed through.
     */
    case ACTIVE;

    /**
     * Service is unavailable.
     * All requests are rejected immediately.
     */
    case INACTIVE;

    /**
     * Testing if service has recovered.
     * Limited requests are allowed through.
     */
    case RECOVERING;
}

/**
 * Circuit breaker state machine.
 *
 * Manages state transitions for service availability.
 * This interface defines HOW to transition between states.
 * Use CircuitBreakerStrategy to define WHEN to transition.
 */
interface CircuitBreaker
{
    /**
     * Get current state.
     */
    public function getState(): CircuitBreakerState;

    /**
     * Transition to ACTIVE state.
     */
    public function activate(): void;

    /**
     * Transition to INACTIVE state.
     */
    public function deactivate(): void;

    /**
     * Transition to RECOVERING state.
     */
    public function recover(): void;
}

/**
 * Circuit breaker strategy interface.
 *
 * Defines WHEN to transition between circuit breaker states.
 * Implement this interface to create custom failure detection logic.
 */
interface CircuitBreakerStrategy
{
    /**
     * Called when an operation succeeds.
     *
     * @param mixed $source The object reporting the event (e.g., Pool)
     */
    public function reportSuccess(mixed $source): void;

    /**
     * Called when an operation fails.
     *
     * @param mixed $source The object reporting the event (e.g., Pool)
     * @param \Throwable $error The error that occurred
     */
    public function reportFailure(mixed $source, \Throwable $error): void;

    /**
     * Check if circuit should attempt recovery.
     *
     * Called periodically when circuit is INACTIVE to determine
     * if it should transition to RECOVERING state.
     */
    public function shouldRecover(): bool;
}