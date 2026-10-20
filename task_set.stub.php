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
     * @param int|null $queueLimit Maximum number of pending tasks (null = 2 * concurrency).
     * @param Scope|null $scope Parent scope.
     * @implementation-alias Async\TaskGroup::__construct
     */
    public function __construct(?int $concurrency = null, ?int $queueLimit = null, ?Scope $scope = null) {}

    /** @implementation-alias Async\TaskGroup::spawn */
    public function spawn(callable $task, mixed ...$args): void {}

    /** @implementation-alias Async\TaskGroup::spawnWithKey */
    public function spawnWithKey(string|int $key, callable $task, mixed ...$args): void {}

    /**
     * Return a Future that resolves or rejects with the first settled task.
     * The completed entry is automatically removed from the set.
     * @implementation-alias Async\TaskGroup::race
     */
    public function joinNext(): Future {}

    /**
     * Return a Future that resolves with the first successfully completed task.
     * The completed entry is automatically removed from the set.
     * @implementation-alias Async\TaskGroup::any
     */
    public function joinAny(): Future {}

    /**
     * Return a Future that resolves with all task results.
     * All entries are automatically removed from the set after delivery.
     *
     * @param bool $ignoreErrors If true, errors are excluded from results.
     * @implementation-alias Async\TaskGroup::all
     */
    public function joinAll(bool $ignoreErrors = false): Future {}

    /** @implementation-alias Async\TaskGroup::cancel */
    public function cancel(?AsyncCancellation $cancellation = null): void {}

    /** @implementation-alias Async\TaskGroup::close */
    public function close(): void {}

    /** @implementation-alias Async\TaskGroup::dispose */
    public function dispose(): void {}

    /** @implementation-alias Async\TaskGroup::isFinished */
    public function isFinished(): bool {}

    /** @implementation-alias Async\TaskGroup::isClosed */
    public function isClosed(): bool {}

    /** @implementation-alias Async\TaskGroup::count */
    public function count(): int {}

    /** @implementation-alias Async\TaskGroup::awaitCompletion */
    public function awaitCompletion(): void {}

    /** @implementation-alias Async\TaskGroup::finally */
    public function finally(\Closure $callback): void {}

    /** @implementation-alias Async\TaskGroup::getIterator */
    public function getIterator(): \Iterator {}
}
