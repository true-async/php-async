<?php

/** @generate-class-entries */

namespace Async;

/**
 * TaskGroup is a task pool with queue and concurrency control.
 * Accepts closures, manages coroutine creation with concurrency limits.
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
     * Add a closure to the group.
     *
     * If concurrency limit is not reached, a coroutine is created immediately.
     * Otherwise the closure is queued and started when a slot becomes available.
     *
     * @param \Closure $task Closure to execute.
     * @param string|int|null $key Result key. null = auto-increment index.
     * @throws AsyncException if group is closed/cancelled or key is duplicate.
     */
    public function spawn(\Closure $task, string|int|null $key = null): void {}

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
     * Implicitly calls close(). Queued closures are never started.
     *
     * @param AsyncCancellation|null $cancellation Cancellation reason.
     */
    public function cancel(?AsyncCancellation $cancellation = null): void {}

    /**
     * Close the group for new tasks.
     * Already running coroutines and queued closures continue working.
     */
    public function close(): void {}

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
     * Check if the group is closed for new tasks.
     */
    public function isClosed(): bool {}

    /**
     * Total number of tasks (queued + running + completed).
     */
    public function count(): int {}

    /**
     * Register a callback invoked when the group is closed AND all tasks are completed.
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
     * Ends when group is closed and all tasks are delivered.
     * Marks errors as handled.
     */
    public function getIterator(): \Iterator {}
}
