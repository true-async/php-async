<?php

/**
 * TrueAsync extension stubs for PhpStorm.
 *
 * Provides IDE-level type information for the `async` PHP extension.
 *
 * @since      8.6
 * @version    1.0.0
 * @link       https://github.com/true-async/php-async
 */

declare(strict_types=1);

namespace Async;

// ---------------------------------------------------------------------------
// Exceptions & Errors
// ---------------------------------------------------------------------------

/**
 * Exception thrown when a Coroutine is canceled.
 *
 * Code inside the Coroutine must properly handle this exception
 * to ensure graceful termination.
 *
 * @since 8.6
 */
class AsyncCancellation extends \Cancellation {}

/**
 * Exception thrown when an awaited operation is cancelled by a cancellation token.
 *
 * This exception wraps the original reason from the token as `$previous`,
 * allowing the caller to distinguish a token-triggered cancellation from
 * an exception thrown by the awaitable itself.
 *
 * @since 8.6
 */
class OperationCanceledException extends AsyncCancellation {}

/**
 * Common base exception for the async extension.
 *
 * @since 8.6
 */
class AsyncException extends \Exception {}

/**
 * General exception for input/output operations.
 *
 * Can be used with sockets, files, pipes, and other I/O descriptors.
 *
 * @since 8.6
 */
class InputOutputException extends \Exception {}

/**
 * Exception for DNS-related errors: getaddrinfo and getnameinfo.
 *
 * @since 8.6
 */
class DnsException extends \Exception {}

/**
 * Exception thrown when an operation exceeds its time limit.
 *
 * @since 8.6
 */
class TimeoutException extends \Exception {}

/**
 * Exception thrown when a poll operation fails.
 *
 * @since 8.6
 */
class PollException extends \Exception {}

/**
 * Error thrown when a deadlock is detected.
 *
 * @since 8.6
 */
class DeadlockError extends \Error {}

/**
 * Exception thrown when a service is unavailable.
 *
 * Used by the circuit breaker when the circuit is in the INACTIVE state.
 *
 * @since 8.6
 */
class ServiceUnavailableException extends AsyncException {}

/**
 * Exception that aggregates multiple exceptions.
 *
 * Used when several exceptions occur simultaneously, for example in finally handlers.
 *
 * @since 8.6
 */
final class CompositeException extends \Exception
{
    /** @var \Throwable[] */
    private array $exceptions;

    /**
     * Add an exception to the composite.
     */
    public function addException(\Throwable $exception): void {}

    /**
     * Get all aggregated exceptions.
     *
     * @return \Throwable[]
     */
    public function getExceptions(): array {}
}

/**
 * Exception thrown when operating on a closed channel.
 *
 * @since 8.6
 */
class ChannelException extends AsyncException {}

/**
 * Exception thrown when operating on a closed or exhausted pool.
 *
 * @since 8.6
 */
class PoolException extends AsyncException {}

// ---------------------------------------------------------------------------
// Core Interfaces
// ---------------------------------------------------------------------------

/**
 * Marker interface for objects that can be awaited.
 *
 * @since 8.6
 */
interface Awaitable {}

/**
 * Interface for objects that represent a completable asynchronous operation.
 *
 * @since 8.6
 */
interface Completable extends Awaitable
{
    /**
     * Request cancellation of the operation.
     *
     * @param AsyncCancellation|null $cancellation Optional cancellation reason.
     */
    public function cancel(?AsyncCancellation $cancellation = null): void;

    /**
     * Return true if the operation has finished (successfully or with an error).
     */
    public function isCompleted(): bool;

    /**
     * Return true if the operation was cancelled.
     */
    public function isCancelled(): bool;
}

/**
 * Interface for objects that can provide a Scope instance.
 *
 * @since 8.6
 */
interface ScopeProvider
{
    /**
     * Return the Scope, or null if none is available.
     */
    public function provideScope(): ?Scope;
}

/**
 * Strategy interface for controlling how coroutines are enqueued in a Scope.
 *
 * Implement this interface to customise scheduling behaviour before and after
 * a coroutine enters the run-queue.
 *
 * @since 8.6
 */
interface SpawnStrategy extends ScopeProvider
{
    /**
     * Called before the coroutine is enqueued.
     *
     * @param Coroutine $coroutine The coroutine about to be enqueued.
     * @param Scope     $scope     The owning scope.
     * @return array               Arbitrary metadata passed to afterCoroutineEnqueue.
     */
    public function beforeCoroutineEnqueue(Coroutine $coroutine, Scope $scope): array;

    /**
     * Called immediately after the coroutine has been enqueued.
     *
     * @param Coroutine $coroutine The enqueued coroutine.
     * @param Scope     $scope     The owning scope.
     */
    public function afterCoroutineEnqueue(Coroutine $coroutine, Scope $scope): void;
}

// ---------------------------------------------------------------------------
// Circuit Breaker
// ---------------------------------------------------------------------------

/**
 * Circuit breaker states.
 *
 * @since 8.6
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
     * Testing whether the service has recovered.
     * Limited requests are allowed through.
     */
    case RECOVERING;
}

/**
 * Circuit breaker state machine.
 *
 * Manages state transitions for service availability.
 * This interface defines **how** to transition between states.
 * Use {@see CircuitBreakerStrategy} to define **when** to transition.
 *
 * @since 8.6
 */
interface CircuitBreaker
{
    /**
     * Get the current circuit breaker state.
     */
    public function getState(): CircuitBreakerState;

    /**
     * Transition to ACTIVE state (service available).
     */
    public function activate(): void;

    /**
     * Transition to INACTIVE state (service unavailable).
     */
    public function deactivate(): void;

    /**
     * Transition to RECOVERING state (probing for recovery).
     */
    public function recover(): void;
}

/**
 * Circuit breaker strategy interface.
 *
 * Defines **when** to transition between circuit breaker states.
 * Implement this interface to create custom failure-detection logic.
 *
 * @since 8.6
 */
interface CircuitBreakerStrategy
{
    /**
     * Called when an operation succeeds.
     *
     * @param mixed $source The object reporting the event (e.g., {@see Pool}).
     */
    public function reportSuccess(mixed $source): void;

    /**
     * Called when an operation fails.
     *
     * @param mixed      $source The object reporting the event (e.g., {@see Pool}).
     * @param \Throwable $error  The error that occurred.
     */
    public function reportFailure(mixed $source, \Throwable $error): void;

    /**
     * Check if the circuit should attempt to recover.
     *
     * Called periodically while the circuit is INACTIVE to determine
     * whether it should transition to RECOVERING.
     */
    public function shouldRecover(): bool;
}

// ---------------------------------------------------------------------------
// OS Signal Enum
// ---------------------------------------------------------------------------

/**
 * OS signal identifiers.
 *
 * @since 8.6
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

// ---------------------------------------------------------------------------
// Context
// ---------------------------------------------------------------------------

/**
 * Immutable key-value store propagated through a coroutine hierarchy.
 *
 * Each coroutine inherits the context of its parent. Calling {@see set()}
 * or {@see unset()} returns a new Context instance; the original is not
 * modified.
 *
 * @since 8.6
 */
final class Context
{
    /**
     * Find a value by key, searching the current context and all ancestors.
     *
     * @param string|object $key
     */
    public function find(string|object $key): mixed {}

    /**
     * Get a value by key from the current context only.
     *
     * @param string|object $key
     */
    public function get(string|object $key): mixed {}

    /**
     * Check if a key exists in the current context or any ancestor.
     *
     * @param string|object $key
     */
    public function has(string|object $key): bool {}

    /**
     * Find a value by key in the local (non-inherited) context only.
     *
     * @param string|object $key
     */
    public function findLocal(string|object $key): mixed {}

    /**
     * Get a value by key from the local context only.
     *
     * @param string|object $key
     */
    public function getLocal(string|object $key): mixed {}

    /**
     * Check if a key exists in the local context only.
     *
     * @param string|object $key
     */
    public function hasLocal(string|object $key): bool {}

    /**
     * Return a new Context with the given key-value pair set.
     *
     * @param string|object $key
     * @param mixed         $value
     * @param bool          $replace Allow replacing an existing key.
     * @return Context A new Context instance.
     */
    public function set(string|object $key, mixed $value, bool $replace = false): Context {}

    /**
     * Return a new Context with the given key removed.
     *
     * @param string|object $key
     * @return Context A new Context instance.
     */
    public function unset(string|object $key): Context {}
}

// ---------------------------------------------------------------------------
// Coroutine
// ---------------------------------------------------------------------------

/**
 * Represents a running or suspended asynchronous coroutine.
 *
 * Coroutines are created via {@see spawn()} or {@see Scope::spawn()}.
 * They implement {@see Completable} and can be awaited by other coroutines.
 *
 * @since 8.6
 */
final class Coroutine implements Completable
{
    /**
     * Return the numeric coroutine ID.
     */
    public function getId(): int {}

    /**
     * Mark the coroutine as high-priority and return it.
     *
     * @return Coroutine $this
     */
    public function asHiPriority(): Coroutine {}

    /**
     * Return the local context of this coroutine.
     */
    public function getContext(): Context {}

    /**
     * Return the coroutine result, or null if it has not finished yet.
     */
    public function getResult(): mixed {}

    /**
     * Return the exception that terminated the coroutine, or null if it has
     * not finished or finished successfully.
     *
     * If the coroutine was cancelled, returns an {@see AsyncCancellation}.
     *
     * @throws \RuntimeException If the coroutine is still running.
     */
    public function getException(): mixed {}

    /**
     * Return the backtrace of the suspended coroutine, or null if it is not suspended.
     *
     * @param int $options {@see DEBUG_BACKTRACE_PROVIDE_OBJECT}, {@see DEBUG_BACKTRACE_IGNORE_ARGS}
     * @param int $limit   Maximum number of stack frames (0 = unlimited).
     * @return array<int, array<string, mixed>>|null
     */
    public function getTrace(int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT, int $limit = 0): ?array {}

    /**
     * Return an array with the file and line where the coroutine was spawned.
     *
     * @return array{file: string, line: int}
     */
    public function getSpawnFileAndLine(): array {}

    /**
     * Return the spawn location as a human-readable string.
     */
    public function getSpawnLocation(): string {}

    /**
     * Return an array with the file and line where the coroutine is currently suspended.
     *
     * @return array{file: string, line: int}
     */
    public function getSuspendFileAndLine(): array {}

    /**
     * Return the suspend location as a human-readable string.
     */
    public function getSuspendLocation(): string {}

    /**
     * Return true if the coroutine has been started.
     */
    public function isStarted(): bool {}

    /**
     * Return true if the coroutine is waiting in the run-queue.
     */
    public function isQueued(): bool {}

    /**
     * Return true if the coroutine is actively executing.
     */
    public function isRunning(): bool {}

    /**
     * Return true if the coroutine is currently suspended.
     */
    public function isSuspended(): bool {}

    /**
     * Return true if the coroutine has been cancelled.
     */
    public function isCancelled(): bool {}

    /**
     * Return true if a cancellation has been requested but not yet delivered.
     */
    public function isCancellationRequested(): bool {}

    /**
     * Return true if the coroutine has finished (success, error, or cancellation).
     */
    public function isCompleted(): bool {}

    /**
     * Return debug information about what this coroutine is currently awaiting.
     *
     * @return array<string, mixed>
     */
    public function getAwaitingInfo(): array {}

    /**
     * Request cancellation of the coroutine.
     *
     * @param AsyncCancellation|null $cancellation Optional cancellation reason.
     */
    public function cancel(?AsyncCancellation $cancellation = null): void {}

    /**
     * Register a callback to be executed when the coroutine finishes.
     *
     * @param \Closure $callback Invoked with no arguments after the coroutine completes.
     */
    public function finally(\Closure $callback): void {}
}

// ---------------------------------------------------------------------------
// Scope
// ---------------------------------------------------------------------------

/**
 * Structured-concurrency scope that owns a group of coroutines.
 *
 * A Scope forms a parent–child hierarchy: when a scope is cancelled or
 * disposed, all coroutines it owns are cancelled as well.
 *
 * @since 8.6
 */
final class Scope implements ScopeProvider
{
    /**
     * Create a new Scope that inherits from the given parent scope.
     *
     * If no parent is provided, the new scope inherits from the current one.
     *
     * @param Scope|null $parentScope
     * @return Scope
     */
    public static function inherit(?Scope $parentScope = null): Scope {}

    /** @inheritDoc */
    #[\Override]
    public function provideScope(): Scope {}

    /**
     * Create a new root Scope.
     */
    public function __construct() {}

    /**
     * Mark the scope as "not safely disposable" and return it.
     *
     * @return Scope $this
     */
    public function asNotSafely(): Scope {}

    /**
     * Spawn a new coroutine inside this scope.
     *
     * @param \Closure  $callable Coroutine body.
     * @param mixed     ...$params Arguments forwarded to the closure.
     * @return Coroutine The new coroutine.
     */
    public function spawn(\Closure $callable, mixed ...$params): Coroutine {}

    /**
     * Cancel all coroutines owned by this scope.
     *
     * @param AsyncCancellation|null $cancellationError Optional cancellation reason.
     */
    public function cancel(?AsyncCancellation $cancellationError = null): void {}

    /**
     * Suspend the current coroutine until all child coroutines have finished.
     *
     * @param Awaitable $cancellation Cancellation token.
     * @throws OperationCanceledException If the cancellation token fires.
     */
    public function awaitCompletion(Awaitable $cancellation): void {}

    /**
     * Await scope completion after cancellation, optionally handling errors.
     *
     * @param callable|null $errorHandler Called for each unhandled child error.
     * @param Awaitable|null $cancellation Cancellation token.
     * @throws OperationCanceledException If the cancellation token fires.
     */
    public function awaitAfterCancellation(?callable $errorHandler = null, ?Awaitable $cancellation = null): void {}

    /**
     * Return true if all child coroutines have finished.
     */
    public function isFinished(): bool {}

    /**
     * Return true if the scope has been closed.
     */
    public function isClosed(): bool {}

    /**
     * Return true if the scope has been cancelled.
     */
    public function isCancelled(): bool {}

    /**
     * Set a handler invoked when a child coroutine propagates an unhandled exception.
     *
     * @param callable $exceptionHandler
     */
    public function setExceptionHandler(callable $exceptionHandler): void {}

    /**
     * Set an exception handler for child scopes.
     *
     * Setting this handler prevents the exception from propagating further up.
     *
     * @param callable $exceptionHandler
     */
    public function setChildScopeExceptionHandler(callable $exceptionHandler): void {}

    /**
     * Register a callback to be executed when the scope finishes.
     *
     * @param \Closure $callback
     */
    public function finally(\Closure $callback): void {}

    /**
     * Cancel and dispose of the scope immediately.
     */
    public function dispose(): void {}

    /**
     * Dispose of the scope, waiting for in-flight coroutines to finish gracefully.
     */
    public function disposeSafely(): void {}

    /**
     * Dispose of the scope after a timeout, even if coroutines have not finished.
     *
     * @param int $timeout Timeout in milliseconds.
     */
    public function disposeAfterTimeout(int $timeout): void {}

    /**
     * Return all direct child scopes.
     *
     * @return Scope[]
     */
    public function getChildScopes(): array {}
}

// ---------------------------------------------------------------------------
// Future & FutureState
// ---------------------------------------------------------------------------

/**
 * Write-side handle for a {@see Future}.
 *
 * The owner of a FutureState can resolve or reject the corresponding Future.
 *
 * @template T
 * @since 8.6
 */
final class FutureState
{
    public function __construct() {}

    /**
     * Resolve the Future with a result value.
     *
     * @param T $result
     */
    public function complete(mixed $result): void {}

    /**
     * Reject the Future with an error.
     *
     * @param \Throwable $throwable
     */
    public function error(\Throwable $throwable): void {}

    /**
     * Return true if the Future has already been resolved or rejected.
     */
    public function isCompleted(): bool {}

    /**
     * Suppress error forwarding to the event-loop error handler when no
     * consumer handles the error.
     */
    public function ignore(): void {}

    /**
     * Return debug information about awaiters of this FutureState.
     *
     * @return array<string, mixed>
     */
    public function getAwaitingInfo(): array {}

    /**
     * Return the file and line where this FutureState was created.
     *
     * @return array{file: string, line: int}
     */
    public function getCreatedFileAndLine(): array {}

    /**
     * Return the creation location as a human-readable string.
     */
    public function getCreatedLocation(): string {}

    /**
     * Return the file and line where this FutureState was completed.
     *
     * @return array{file: string, line: int}
     */
    public function getCompletedFileAndLine(): array {}

    /**
     * Return the completion location as a human-readable string.
     */
    public function getCompletedLocation(): string {}
}

/**
 * Read-side handle for an asynchronous operation.
 *
 * A Future is completed by its paired {@see FutureState}. It can be
 * chained via {@see map()}, {@see catch()}, and {@see finally()}, and
 * awaited inside a coroutine via {@see await()}.
 *
 * @template-covariant T
 * @since 8.6
 */
final class Future implements Completable
{
    /**
     * Create an already-resolved Future.
     *
     * @template Tv
     * @param Tv $value
     * @return Future<Tv>
     */
    public static function completed(mixed $value = null): Future {}

    /**
     * Create an already-rejected Future.
     *
     * @return Future<never>
     */
    public static function failed(\Throwable $throwable): Future {}

    /**
     * Create a Future backed by the given FutureState.
     *
     * @param FutureState<T> $state
     */
    public function __construct(FutureState $state) {}

    /**
     * Return true if the Future has been resolved or rejected.
     */
    public function isCompleted(): bool {}

    /**
     * Return true if the Future was cancelled.
     */
    public function isCancelled(): bool {}

    /**
     * Request cancellation of the Future.
     *
     * @param AsyncCancellation|null $cancellation
     */
    public function cancel(?AsyncCancellation $cancellation = null): void {}

    /**
     * Suppress error forwarding to the event-loop handler.
     *
     * @return Future<T>
     */
    public function ignore(): Future {}

    /**
     * Transform the resolved value.
     *
     * The returned Future resolves with the return value of $map, or rejects
     * if $map throws.
     *
     * @template Tr
     * @param callable(T): Tr $map
     * @return Future<Tr>
     */
    public function map(callable $map): Future {}

    /**
     * Handle a rejection.
     *
     * The returned Future resolves with the return value of $catch, or
     * re-rejects if $catch throws.
     *
     * @template Tr
     * @param callable(\Throwable): Tr $catch
     * @return Future<Tr>
     */
    public function catch(callable $catch): Future {}

    /**
     * Attach a callback that runs regardless of success or failure.
     *
     * The returned Future carries the same result as this Future after the
     * callback completes. If the callback throws, the returned Future rejects
     * with that exception.
     *
     * @param callable(): void $finally
     * @return Future<T>
     */
    public function finally(callable $finally): Future {}

    /**
     * Suspend the current coroutine until this Future is settled.
     *
     * @param Completable|null $cancellation Optional cancellation token.
     * @return T
     * @throws \Throwable If the Future was rejected.
     * @throws OperationCanceledException If the cancellation token fires.
     */
    public function await(?Completable $cancellation = null): mixed {}

    /**
     * Return debug information about awaiters of this Future.
     *
     * @return array<string, mixed>
     */
    public function getAwaitingInfo(): array {}

    /**
     * Return the file and line where this Future was created.
     *
     * @return array{file: string, line: int}
     */
    public function getCreatedFileAndLine(): array {}

    /**
     * Return the creation location as a human-readable string.
     */
    public function getCreatedLocation(): string {}

    /**
     * Return the file and line where this Future was completed.
     *
     * @return array{file: string, line: int}
     */
    public function getCompletedFileAndLine(): array {}

    /**
     * Return the completion location as a human-readable string.
     */
    public function getCompletedLocation(): string {}
}

// ---------------------------------------------------------------------------
// Timeout
// ---------------------------------------------------------------------------

/**
 * A one-shot cancellable timer that implements {@see Completable}.
 *
 * Obtain a Timeout via {@see timeout()}.
 *
 * @since 8.6
 */
final class Timeout implements Completable
{
    private function __construct() {}

    /** @inheritDoc */
    public function cancel(?AsyncCancellation $cancellation = null): void {}

    /** @inheritDoc */
    public function isCompleted(): bool {}

    /** @inheritDoc */
    public function isCancelled(): bool {}
}

// ---------------------------------------------------------------------------
// Channel
// ---------------------------------------------------------------------------

/**
 * Concurrency primitive for typed message passing between coroutines.
 *
 * A Channel can be:
 *  - **unbuffered** (`capacity = 0`): rendezvous semantics — send blocks
 *    until a receiver is ready.
 *  - **buffered** (`capacity > 0`): bounded FIFO queue.
 *
 * @since 8.6
 */
final class Channel implements Awaitable, \IteratorAggregate, \Countable
{
    /**
     * Create a new Channel.
     *
     * @param int $capacity 0 = unbuffered; >0 = bounded buffer size.
     */
    public function __construct(int $capacity = 0) {}

    /**
     * Send a value into the channel (blocking).
     *
     * Suspends the current coroutine until the value is consumed (unbuffered)
     * or until a buffer slot is available (buffered).
     *
     * @param mixed            $value
     * @param Completable|null $cancellationToken Optional cancellation token (e.g. timeout(ms)).
     * @throws ChannelException If the channel is closed.
     * @throws OperationCanceledException If the cancellation token fires.
     */
    public function send(mixed $value, ?Completable $cancellationToken = null): void {}

    /**
     * Try to send a value without blocking.
     *
     * @param mixed $value
     * @return bool True if the value was accepted; false if the channel is full or closed.
     */
    public function sendAsync(mixed $value): bool {}

    /**
     * Receive a value from the channel (blocking).
     *
     * Suspends the current coroutine until a value is available.
     *
     * @param Completable|null $cancellationToken Optional cancellation token (e.g. timeout(ms)).
     * @return mixed The received value.
     * @throws ChannelException If the channel is closed and empty.
     * @throws OperationCanceledException If the cancellation token fires.
     */
    public function recv(?Completable $cancellationToken = null): mixed {}

    /**
     * Receive a value without blocking.
     *
     * @return Future<mixed> Resolves to the next available value.
     */
    public function recvAsync(): Future {}

    /**
     * Close the channel.
     *
     * After closing:
     *  - {@see send()} throws {@see ChannelException}.
     *  - {@see recv()} drains remaining buffered values, then throws {@see ChannelException}.
     *  - All waiting coroutines are woken with {@see ChannelException}.
     */
    public function close(): void {}

    /**
     * Return true if the channel has been closed.
     */
    public function isClosed(): bool {}

    /**
     * Return the channel capacity (0 = unbuffered).
     */
    public function capacity(): int {}

    /**
     * Return the number of values currently buffered.
     */
    public function count(): int {}

    /**
     * Return true if no values are currently buffered.
     */
    public function isEmpty(): bool {}

    /**
     * Return true if the buffer is at capacity.
     */
    public function isFull(): bool {}

    /**
     * Return an iterator for foreach support.
     *
     * Yields each received value in order. Iteration ends when the channel
     * is closed and empty.
     *
     * @return \Iterator<int, mixed>
     */
    public function getIterator(): \Iterator {}
}

// ---------------------------------------------------------------------------
// TaskGroup
// ---------------------------------------------------------------------------

/**
 * Task pool with queue and concurrency control.
 *
 * Accepts callables, manages coroutine creation with optional concurrency
 * limits, and collects results keyed by task identifier.
 *
 * @since 8.6
 */
final class TaskGroup implements Awaitable, \Countable, \IteratorAggregate
{
    /**
     * Create a new TaskGroup.
     *
     * @param int|null   $concurrency Maximum concurrent coroutines; null = unlimited.
     * @param Scope|null $scope       Parent scope; null = current scope.
     */
    public function __construct(?int $concurrency = null, ?Scope $scope = null) {}

    /**
     * Spawn a task with an auto-increment key.
     *
     * If the concurrency limit is not reached, a coroutine starts immediately;
     * otherwise the callable is queued.
     *
     * @param callable $task
     * @param mixed    ...$args
     * @throws AsyncException If the group is sealed or cancelled.
     */
    public function spawn(callable $task, mixed ...$args): void {}

    /**
     * Spawn a task with an explicit key.
     *
     * @param string|int $key  Result key (must be unique within the group).
     * @param callable   $task
     * @param mixed      ...$args
     * @throws AsyncException If the group is sealed, cancelled, or the key is a duplicate.
     */
    public function spawnWithKey(string|int $key, callable $task, mixed ...$args): void {}

    /**
     * Return a Future that resolves with all task results when every task completes.
     *
     * @param bool $ignoreErrors If false, rejects with {@see CompositeException} on any error.
     * @return Future<array<string|int, mixed>>
     */
    public function all(bool $ignoreErrors = false): Future {}

    /**
     * Return a Future that resolves or rejects with the first settled task.
     *
     * Remaining tasks continue running.
     *
     * @return Future<mixed>
     * @throws AsyncException If the group is empty.
     */
    public function race(): Future {}

    /**
     * Return a Future that resolves with the first successfully completed task.
     *
     * Errors are skipped. If all tasks fail, rejects with {@see CompositeException}.
     * Remaining tasks continue running.
     *
     * @return Future<mixed>
     * @throws AsyncException If the group is empty.
     */
    public function any(): Future {}

    /**
     * Return results of already-completed tasks.
     *
     * @return array<string|int, mixed>
     */
    public function getResults(): array {}

    /**
     * Return errors of failed tasks and mark them as handled.
     *
     * @return array<string|int, \Throwable>
     */
    public function getErrors(): array {}

    /**
     * Mark all pending errors as handled without retrieving them.
     */
    public function suppressErrors(): void {}

    /**
     * Cancel all running coroutines and discard queued tasks.
     *
     * Implicitly calls {@see seal()}.
     *
     * @param AsyncCancellation|null $cancellation
     */
    public function cancel(?AsyncCancellation $cancellation = null): void {}

    /**
     * Seal the group: no new tasks may be added.
     *
     * Running and queued tasks continue normally.
     */
    public function seal(): void {}

    /**
     * Dispose of the group's scope, cancelling all coroutines.
     */
    public function dispose(): void {}

    /**
     * Return true if the queue is empty and no coroutines are active.
     *
     * This state may be temporary if the group is not yet sealed.
     */
    public function isFinished(): bool {}

    /**
     * Return true if the group has been sealed.
     */
    public function isSealed(): bool {}

    /**
     * Return the total number of tasks (queued + running + completed).
     */
    public function count(): int {}

    /**
     * Suspend the current coroutine until all tasks finish.
     *
     * The group **must** be sealed before calling this method.
     * Unlike {@see all()}, this method never throws on task errors.
     *
     * @throws AsyncException If the group is not sealed.
     */
    public function awaitCompletion(): void {}

    /**
     * Register a callback invoked when the group is sealed and all tasks complete.
     *
     * If the group is already in that state, the callback is invoked immediately.
     *
     * @param \Closure $callback Receives the TaskGroup as its argument.
     */
    public function finally(\Closure $callback): void {}

    /**
     * Return an iterator that yields results as tasks complete.
     *
     * Each iteration yields `[$result, null]` on success or `[null, $error]`
     * on failure, keyed by the task key. Errors are marked as handled on
     * delivery. Iteration ends when the group is sealed and all tasks are
     * delivered.
     *
     * @return \Iterator<string|int, array{mixed, \Throwable|null}>
     */
    public function getIterator(): \Iterator {}
}

// ---------------------------------------------------------------------------
// TaskSet
// ---------------------------------------------------------------------------

/**
 * Mutable task collection with automatic cleanup.
 *
 * Completed tasks are automatically removed from the set after their results
 * are consumed via {@see joinNext()}, {@see joinAny()}, {@see joinAll()},
 * or foreach iteration. This makes TaskSet ideal for worker-pool patterns
 * where tasks are spawned dynamically and results are processed as they arrive.
 *
 * @since 8.6
 */
final class TaskSet implements Awaitable, \Countable, \IteratorAggregate
{
    /**
     * Create a new TaskSet.
     *
     * @param int|null   $concurrency Maximum concurrent coroutines; null = unlimited.
     * @param Scope|null $scope       Parent scope; null = current scope.
     */
    public function __construct(?int $concurrency = null, ?Scope $scope = null) {}

    /**
     * Spawn a task with an auto-increment key.
     *
     * If the concurrency limit is not reached, a coroutine starts immediately;
     * otherwise the callable is queued.
     *
     * @param callable $task
     * @param mixed    ...$args
     * @throws AsyncException If the set is sealed or cancelled.
     */
    public function spawn(callable $task, mixed ...$args): void {}

    /**
     * Spawn a task with an explicit key.
     *
     * @param string|int $key  Result key (must be unique within the set).
     * @param callable   $task
     * @param mixed      ...$args
     * @throws AsyncException If the set is sealed, cancelled, or the key is a duplicate.
     */
    public function spawnWithKey(string|int $key, callable $task, mixed ...$args): void {}

    /**
     * Return a Future that resolves or rejects with the first settled task.
     *
     * The completed entry is automatically removed from the set.
     * Remaining tasks continue running.
     *
     * @return Future<mixed>
     * @throws AsyncException If the set is empty.
     */
    public function joinNext(): Future {}

    /**
     * Return a Future that resolves with the first successfully completed task.
     *
     * Errors are skipped. If all tasks fail, rejects with {@see CompositeException}.
     * The completed entry is automatically removed from the set.
     * Remaining tasks continue running.
     *
     * @return Future<mixed>
     * @throws AsyncException If the set is empty.
     */
    public function joinAny(): Future {}

    /**
     * Return a Future that resolves with all task results.
     *
     * All entries are automatically removed from the set after delivery.
     *
     * @param bool $ignoreErrors If false, rejects with {@see CompositeException} on any error.
     * @return Future<array<string|int, mixed>>
     */
    public function joinAll(bool $ignoreErrors = false): Future {}

    /**
     * Cancel all running coroutines and discard queued tasks.
     *
     * Implicitly calls {@see seal()}.
     *
     * @param AsyncCancellation|null $cancellation
     */
    public function cancel(?AsyncCancellation $cancellation = null): void {}

    /**
     * Seal the set: no new tasks may be added.
     *
     * Running and queued tasks continue normally.
     */
    public function seal(): void {}

    /**
     * Dispose of the set's scope, cancelling all coroutines.
     */
    public function dispose(): void {}

    /**
     * Return true if no coroutines are active and no tasks are queued.
     *
     * This state may be temporary if the set is not yet sealed.
     */
    public function isFinished(): bool {}

    /**
     * Return true if the set has been sealed.
     */
    public function isSealed(): bool {}

    /**
     * Return the number of tasks currently in the set.
     *
     * Decreases as completed tasks are consumed via join methods or iteration.
     */
    public function count(): int {}

    /**
     * Suspend the current coroutine until all tasks finish.
     *
     * The set **must** be sealed before calling this method.
     * Unlike {@see joinAll()}, this method never throws on task errors.
     *
     * @throws AsyncException If the set is not sealed.
     */
    public function awaitCompletion(): void {}

    /**
     * Register a callback invoked when the set is sealed and all tasks complete.
     *
     * If the set is already in that state, the callback is invoked immediately.
     *
     * @param \Closure $callback Receives the TaskSet as its argument.
     */
    public function finally(\Closure $callback): void {}

    /**
     * Return an iterator that yields results as tasks complete.
     *
     * Each iteration yields `[$result, null]` on success or `[null, $error]`
     * on failure, keyed by the task key. Consumed entries are automatically
     * removed from the set. Iteration ends when the set is sealed and all
     * tasks are delivered.
     *
     * @return \Iterator<string|int, array{mixed, \Throwable|null}>
     */
    public function getIterator(): \Iterator {}
}

// ---------------------------------------------------------------------------
// Pool
// ---------------------------------------------------------------------------

/**
 * Generic resource pool with automatic lifecycle management.
 *
 * Resources circulate between an idle buffer and active usage. Implements
 * {@see CircuitBreaker} for service-availability control.
 *
 * @since 8.6
 */
final class Pool implements \Countable, CircuitBreaker
{
    /**
     * Create a new resource pool.
     *
     * @param callable      $factory             Creates a new resource: `fn(): mixed`
     * @param callable|null $destructor          Destroys a resource: `fn(mixed $resource): void`
     * @param callable|null $healthcheck         Background health check: `fn(mixed $resource): bool`
     * @param callable|null $beforeAcquire       Pre-acquire check: `fn(mixed $resource): bool`
     *                                           (false = destroy and fetch next)
     * @param callable|null $beforeRelease       Pre-release hook: `fn(mixed $resource): bool`
     *                                           (false = destroy instead of returning to pool)
     * @param int           $min                 Minimum idle resources pre-created on startup.
     * @param int           $max                 Maximum total resources (idle + active).
     * @param int           $healthcheckInterval Background health-check interval in ms; 0 = disabled.
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
     * Acquire a resource, blocking until one is available.
     *
     * @param int $timeout Max wait time in ms; 0 = infinite.
     * @return mixed The acquired resource.
     * @throws PoolException If the pool is closed or the timeout expires.
     */
    public function acquire(int $timeout = 0): mixed {}

    /**
     * Try to acquire a resource without blocking.
     *
     * @return mixed|null The resource, or null if none is immediately available.
     */
    public function tryAcquire(): mixed {}

    /**
     * Release a resource back to the pool.
     *
     * If `$beforeRelease` returns false, the resource is destroyed instead.
     *
     * @param mixed $resource The resource to release.
     */
    public function release(mixed $resource): void {}

    /**
     * Close the pool and destroy all resources.
     *
     * All waiting coroutines are woken with {@see PoolException}.
     */
    public function close(): void {}

    /**
     * Return true if the pool has been closed.
     */
    public function isClosed(): bool {}

    /**
     * Return the total resource count (idle + active).
     */
    public function count(): int {}

    /**
     * Return the number of idle (available) resources.
     */
    public function idleCount(): int {}

    /**
     * Return the number of active (in-use) resources.
     */
    public function activeCount(): int {}

    /**
     * Attach a circuit breaker strategy to control service availability.
     *
     * @param CircuitBreakerStrategy|null $strategy
     */
    public function setCircuitBreakerStrategy(?CircuitBreakerStrategy $strategy): void {}

    /** @inheritDoc */
    public function getState(): CircuitBreakerState {}

    /** @inheritDoc */
    public function activate(): void {}

    /** @inheritDoc */
    public function deactivate(): void {}

    /** @inheritDoc */
    public function recover(): void {}
}

// ---------------------------------------------------------------------------
// FileSystem Watcher
// ---------------------------------------------------------------------------

/**
 * Represents a single filesystem event detected by {@see FileSystemWatcher}.
 *
 * @since 8.6
 */
final readonly class FileSystemEvent
{
    /** Absolute path of the watched file or directory. */
    public string $path;

    /** Name of the file that changed, or null when unavailable. */
    public ?string $filename;

    /** True if the entry was renamed or moved. */
    public bool $renamed;

    /** True if the entry content was modified. */
    public bool $changed;
}

/**
 * Persistent filesystem watcher that buffers events for async iteration.
 *
 * Monitors a file or directory for changes and delivers {@see FileSystemEvent}
 * objects via `foreach` or by awaiting the watcher directly.
 *
 * Two buffering modes:
 *  - **coalesce** (`true`): merge multiple events per file into one.
 *  - **raw** (`false`): deliver every event individually.
 *
 * @since 8.6
 */
final class FileSystemWatcher implements Awaitable, \IteratorAggregate
{
    /**
     * Create a watcher and start monitoring immediately.
     *
     * @param string $path      Absolute or relative path to watch.
     * @param bool   $recursive Watch subdirectories recursively.
     * @param bool   $coalesce  Merge events per file (true) or deliver every event (false).
     */
    public function __construct(string $path, bool $recursive = false, bool $coalesce = true) {}

    /**
     * Stop monitoring and terminate any active iteration.
     *
     * Idempotent — safe to call multiple times.
     */
    public function close(): void {}

    /**
     * Return true if the watcher has been closed.
     */
    public function isClosed(): bool {}

    /**
     * Return an async iterator for `foreach` support.
     *
     * Yields {@see FileSystemEvent} objects as filesystem changes are detected.
     * Suspends when no events are pending; resumes on the next event.
     * Ends when {@see close()} is called or the owning scope is cancelled.
     *
     * @return \Iterator<int, FileSystemEvent>
     */
    public function getIterator(): \Iterator {}
}

// ---------------------------------------------------------------------------
// Global Functions
// ---------------------------------------------------------------------------

/**
 * Spawn a new coroutine in the current scope.
 *
 * @param callable $task     The coroutine body.
 * @param mixed    ...$args  Arguments forwarded to `$task`.
 * @return Coroutine The newly created coroutine (already enqueued).
 */
function spawn(callable $task, mixed ...$args): Coroutine {}

/**
 * Spawn a new coroutine using a custom {@see ScopeProvider}.
 *
 * @param ScopeProvider $provider Provides the target scope.
 * @param callable      $task     The coroutine body.
 * @param mixed         ...$args  Arguments forwarded to `$task`.
 * @return Coroutine
 */
function spawn_with(ScopeProvider $provider, callable $task, mixed ...$args): Coroutine {}

/**
 * Yield control to the scheduler, allowing other coroutines to run.
 */
function suspend(): void {}

/**
 * Execute `$closure` in non-cancellable mode.
 *
 * Any cancellation requests are deferred until after the closure returns.
 *
 * @param \Closure $closure
 * @return mixed The return value of `$closure`.
 */
function protect(\Closure $closure): mixed {}

/**
 * Await the completion of a {@see Completable}.
 *
 * Suspends the current coroutine until `$awaitable` settles.
 *
 * @param Completable      $awaitable
 * @param Completable|null $cancellation Optional cancellation token.
 * @return mixed The resolved value.
 * @throws \Throwable If `$awaitable` was rejected.
 * @throws OperationCanceledException If the cancellation token fires.
 */
function await(Completable $awaitable, ?Completable $cancellation = null): mixed {}

/**
 * Await the first trigger to settle; throw if it fails.
 *
 * @param iterable<Awaitable> $triggers
 * @param Awaitable|null      $cancellation
 * @return mixed The value of the first settled trigger.
 * @throws \Throwable If the settled trigger was rejected.
 * @throws OperationCanceledException If the cancellation token fires.
 */
function await_any_or_fail(iterable $triggers, ?Awaitable $cancellation = null): mixed {}

/**
 * Await the first trigger to succeed.
 *
 * Errors are skipped until a successful result is found.
 *
 * @param iterable<Awaitable> $triggers
 * @param Awaitable|null      $cancellation
 * @return mixed The first successful result.
 * @throws \Throwable If all triggers fail.
 * @throws OperationCanceledException If the cancellation token fires.
 */
function await_first_success(iterable $triggers, ?Awaitable $cancellation = null): mixed {}

/**
 * Await all triggers; throw if any fails.
 *
 * @param iterable<Awaitable> $triggers
 * @param Awaitable|null      $cancellation
 * @param bool                $preserveKeyOrder Preserve the original key order.
 * @return array<mixed> Resolved values.
 * @throws \Throwable On the first failed trigger.
 * @throws OperationCanceledException If the cancellation token fires.
 */
function await_all_or_fail(iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true): array {}

/**
 * Await all triggers, collecting both results and errors.
 *
 * @param iterable<Awaitable> $triggers
 * @param Awaitable|null      $cancellation
 * @param bool                $preserveKeyOrder
 * @param bool                $fillNull         Fill failed slots with null instead of skipping.
 * @return array<mixed>
 * @throws OperationCanceledException If the cancellation token fires.
 */
function await_all(iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true, bool $fillNull = false): array {}

/**
 * Await exactly `$count` triggers; throw if fewer than `$count` succeed.
 *
 * @param int                 $count
 * @param iterable<Awaitable> $triggers
 * @param Awaitable|null      $cancellation
 * @param bool                $preserveKeyOrder
 * @return array<mixed>
 * @throws \Throwable If fewer than `$count` triggers succeed.
 * @throws OperationCanceledException If the cancellation token fires.
 */
function await_any_of_or_fail(int $count, iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true): array {}

/**
 * Await up to `$count` triggers, collecting results without throwing.
 *
 * @param int                 $count
 * @param iterable<Awaitable> $triggers
 * @param Awaitable|null      $cancellation
 * @param bool                $preserveKeyOrder
 * @param bool                $fillNull
 * @return array<mixed>
 * @throws OperationCanceledException If the cancellation token fires.
 */
function await_any_of(int $count, iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true, bool $fillNull = false): array {}

/**
 * Suspend the current coroutine for at least `$ms` milliseconds.
 *
 * @param int $ms Delay in milliseconds.
 */
function delay(int $ms): void {}

/**
 * Create a {@see Timeout} that fires after `$ms` milliseconds.
 *
 * @param int $ms
 * @return Awaitable A Timeout instance.
 */
function timeout(int $ms): Awaitable {}

/**
 * Return the current coroutine's context.
 *
 * @return Context
 */
function current_context(): Context {}

/**
 * Return the context of the root coroutine in the current coroutine hierarchy.
 *
 * @return Context
 */
function coroutine_context(): Context {}

/**
 * Return the currently executing coroutine.
 *
 * @return Coroutine
 */
function current_coroutine(): Coroutine {}

/**
 * Return the root context of the scheduler.
 *
 * @return Context
 */
function root_context(): Context {}

/**
 * Return a snapshot of all live coroutines managed by the scheduler.
 *
 * @return Coroutine[]
 */
function get_coroutines(): array {}

/**
 * Iterate over `$iterable`, invoking `$callback` for each element.
 *
 * The callback receives `(value, key)` and may return `false` to stop
 * iteration. Blocks the current coroutine until all iterations complete.
 *
 * If `$cancelPending` is true (default), coroutines spawned inside the
 * callback are cancelled when iteration finishes. If false, the function
 * waits for all spawned coroutines to complete.
 *
 * @param iterable  $iterable
 * @param callable  $callback     `fn(mixed $value, mixed $key): mixed`
 * @param int       $concurrency  Maximum concurrent callback invocations; 0 = unlimited.
 * @param bool      $cancelPending Cancel pending coroutines when done.
 */
function iterate(iterable $iterable, callable $callback, int $concurrency = 0, bool $cancelPending = true): void {}

/**
 * Initiate a graceful shutdown of the scheduler.
 *
 * @param AsyncCancellation|null $cancellationError Optional cancellation reason propagated to running coroutines.
 */
function graceful_shutdown(?AsyncCancellation $cancellationError = null): void {}

/**
 * Await the delivery of an OS signal.
 *
 * Returns a Future that resolves with the {@see Signal} enum case when the
 * specified signal is received.
 *
 * @param Signal           $signal
 * @param Completable|null $cancellation
 * @return Future<Signal>
 * @throws OperationCanceledException If the cancellation token fires.
 */
function signal(Signal $signal, ?Completable $cancellation = null): Future {}
