<?php

/** @generate-class-entries */

namespace Async;

/**
 * TaskGroup is a task pool with queue and concurrency control.
 * Accepts callables, manages coroutine creation with concurrency limits.
 * Tasks can be added with associative keys.
 *
 * @strict-properties
 * @not-serializable
 */
final class TaskGroup implements Awaitable, \Countable, \IteratorAggregate
{
    /**
     * Create a new TaskGroup.
     *
     * @param int|null $concurrency Maximum number of concurrent coroutines.
     *   null = no limit, all closures start immediately.
     *   N   = at most N coroutines run simultaneously, rest wait in queue.
     * @param Scope|null $scope Parent scope. A child scope is always created.
     *   null = child of current scope.
     */
    public function __construct(?int $concurrency = null, ?Scope $scope = null) {}

    /**
     * Add a callable to the group with auto-increment key.
     *
     * If concurrency limit is not reached, a coroutine is created immediately.
     * Otherwise the callable is queued and started when a slot becomes available.
     *
     * @param callable $task Callable to execute.
     * @param mixed ...$args Arguments to pass to the callable.
     * @throws AsyncException if group is sealed/cancelled.
     */
    public function spawn(callable $task, mixed ...$args): void {}

    /**
     * Add a callable to the group with an explicit key.
     *
     * If concurrency limit is not reached, a coroutine is created immediately.
     * Otherwise the callable is queued and started when a slot becomes available.
     *
     * @param string|int $key Result key.
     * @param callable $task Callable to execute.
     * @param mixed ...$args Arguments to pass to the callable.
     * @throws AsyncException if group is sealed/cancelled or key is duplicate.
     */
    public function spawnWithKey(string|int $key, callable $task, mixed ...$args): void {}

    /**
     * Wait for all current tasks to complete.
     *
     * Returns results array keyed by spawn keys.
     * On empty group returns empty array immediately.
     *
     * @param bool $ignoreErrors If false and errors exist, throws CompositeException.
     *   If true, errors are ignored (retrieve via getErrors()).
     * @return array Results indexed by task keys.
     * @throws CompositeException if $ignoreErrors is false and any task failed.
     */
    public function all(bool $ignoreErrors = false): array {}

    /**
     * Wait for the first completed task (success or error).
     *
     * If a task is already completed, returns immediately.
     * Remaining tasks continue running.
     *
     * @return mixed Result of the first completed task.
     * @throws AsyncException if group is empty.
     * @throws \Throwable if the first completed task failed.
     */
    public function race(): mixed {}

    /**
     * Wait for the first successful task.
     *
     * Errors are ignored until a successful result is found.
     * Remaining tasks continue running.
     *
     * @return mixed Result of the first successful task.
     * @throws AsyncException if group is empty.
     * @throws CompositeException if all tasks failed.
     */
    public function any(): mixed {}

    /**
     * Get results of completed tasks.
     *
     * @return array Results indexed by task keys.
     */
    public function getResults(): array {}

    /**
     * Get errors of failed tasks.
     * Marks errors as handled.
     *
     * @return array Throwable instances indexed by task keys.
     */
    public function getErrors(): array {}

    /**
     * Mark all current errors as handled.
     */
    public function suppressErrors(): void {}

    /**
     * Cancel all running coroutines and queued closures.
     * Implicitly calls seal(). Queued closures are never started.
     *
     * @param AsyncCancellation|null $cancellation Cancellation reason.
     */
    public function cancel(?AsyncCancellation $cancellation = null): void {}

    /**
     * Seal the group for new tasks.
     * Already running coroutines and queued closures continue working.
     * Unlike close/cancel, the group can still be awaited.
     */
    public function seal(): void {}

    /**
     * Dispose the group's scope, cancelling all coroutines.
     */
    public function dispose(): void {}

    /**
     * Check if all tasks are currently finished (queue empty and no active coroutines).
     * This state may be temporary if the group is still open for new spawns.
     */
    public function isFinished(): bool {}

    /**
     * Check if the group is sealed for new tasks.
     */
    public function isSealed(): bool {}

    /**
     * Total number of tasks (queued + running + completed).
     */
    public function count(): int {}

    /**
     * Register a callback invoked when the group is sealed AND all tasks are completed.
     * If the group is already completed, the callback is invoked immediately.
     *
     * @param \Closure $callback Callback receiving the TaskGroup as parameter.
     */
    public function onFinally(\Closure $callback): void {}

    /**
     * Get iterator for foreach support.
     *
     * Yields results as they complete: key => [result, error].
     *   Success: [$result, null]
     *   Error:   [null, $error]
     * Iteration suspends waiting for results.
     * Ends when group is sealed and all tasks are delivered.
     * Marks errors as handled.
     */
    public function getIterator(): \Iterator {}
}
