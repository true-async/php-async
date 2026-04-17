<?php

/** @generate-class-entries */

namespace Async;

/**
 * ThreadPool manages a fixed set of reusable worker threads.
 * Tasks are submitted and distributed to workers via an internal channel.
 * The pool can be transferred between threads (shared persistent memory).
 *
 * @strict-properties
 * @not-serializable
 */
final class ThreadPool
{
    /**
     * @param int $workers Number of worker threads.
     * @param int $queueSize Maximum pending task queue size (default: workers * 4).
     */
    public function __construct(int $workers, int $queueSize = 0) {}

    /**
     * Submit a task for execution. Returns a Future that resolves
     * with the task's return value or rejects with its exception.
     */
    public function submit(callable $task, mixed ...$args): Future {}

    /**
     * Apply a callable to each item in parallel across worker threads.
     * Returns an array of results in the same order as the input.
     */
    public function map(array $items, callable $task): array {}

    /**
     * Close the pool — reject new submissions but let running tasks finish.
     */
    public function close(): void {}

    /**
     * Cancel all pending tasks and stop workers.
     */
    public function cancel(): void {}

    /**
     * Whether the pool is closed (no new submissions accepted).
     */
    public function isClosed(): bool {}

    /**
     * Number of tasks waiting in the queue.
     */
    public function getPendingCount(): int {}

    /**
     * Number of tasks currently being executed by workers.
     */
    public function getRunningCount(): int {}

    /**
     * Number of tasks that have completed (successfully or with exception)
     * since the pool was created. Monotonically increasing.
     */
    public function getCompletedCount(): int {}

    /**
     * Number of worker threads.
     */
    public function getWorkerCount(): int {}
}

/**
 * Exception thrown by ThreadPool operations.
 */
class ThreadPoolException extends \Exception {}
