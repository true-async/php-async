<?php

/** @generate-class-entries */

namespace Async;

/**
 * TaskSet is a mutable task collection with auto-cleanup.
 * Completed tasks are automatically removed after their results
 * are consumed via joinNext(), joinAny(), joinAll(), or iteration.
 *
 * @strict-properties
 * @not-serializable
 */
final class TaskSet implements Awaitable, \Countable, \IteratorAggregate
{
    /**
     * @param int|null $concurrency Maximum number of concurrent coroutines.
     * @param Scope|null $scope Parent scope.
     */
    public function __construct(?int $concurrency = null, ?Scope $scope = null) {}

    public function spawn(callable $task, mixed ...$args): void {}

    public function spawnWithKey(string|int $key, callable $task, mixed ...$args): void {}

    /**
     * Return a Future that resolves or rejects with the first settled task.
     * The completed entry is automatically removed from the set.
     */
    public function joinNext(): Future {}

    /**
     * Return a Future that resolves with the first successfully completed task.
     * The completed entry is automatically removed from the set.
     */
    public function joinAny(): Future {}

    /**
     * Return a Future that resolves with all task results.
     * All entries are automatically removed from the set after delivery.
     *
     * @param bool $ignoreErrors If true, errors are excluded from results.
     */
    public function joinAll(bool $ignoreErrors = false): Future {}

    public function cancel(?AsyncCancellation $cancellation = null): void {}

    public function seal(): void {}

    public function dispose(): void {}

    public function isFinished(): bool {}

    public function isSealed(): bool {}

    public function count(): int {}

    public function awaitCompletion(): void {}

    public function finally(\Closure $callback): void {}

    public function getIterator(): \Iterator {}
}
