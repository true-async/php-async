<?php

/** @generate-class-entries */

namespace Async;

/**
 * @strict-properties
 * @not-serializable
 */
final class Coroutine implements Completable
{
    /**
     * Returns the Coroutine ID.
     */
    public function getId(): int {}

    /**
     * Marks the Coroutine as high priority.
     */
    public function asHiPriority(): Coroutine {}

    /**
     * Returns the Coroutine local-context.
     */
    public function getContext(): Context {}

    /**
     * Returns the Coroutine result when finished.
     * If the Coroutine is not finished, it will return null.
     */
    public function getResult(): mixed {}

    /**
     * Returns the Coroutine exception when finished.
     * If the Coroutine is not finished, it will return null.
     * If the Coroutine is cancelled, it will return a AsyncCancellation.
     *
     * @throws \RuntimeException if the Coroutine is running
     */
    public function getException(): mixed {}

    /**
     * Returns the Coroutine debug trace.
     * If the coroutine is in the suspended state, returns a backtrace array.
     * Otherwise, returns null.
     *
     * @param int $options Options for the backtrace (DEBUG_BACKTRACE_PROVIDE_OBJECT, DEBUG_BACKTRACE_IGNORE_ARGS)
     * @param int $limit Maximum number of stack frames to return (0 for no limit)
     */
    public function getTrace(int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT, int $limit = 0): ?array {}

    /**
     * Return spawn file and line.
     */
    public function getSpawnFileAndLine(): array {}

    /**
     * Return spawn location as string.
     */
    public function getSpawnLocation(): string {}

    /**
     * Return suspend file and line.
     */
    public function getSuspendFileAndLine(): array {}

    /**
     * Return suspend location as string.
     */
    public function getSuspendLocation(): string {}

    /**
     * Return true if the coroutine is started.
     */
    public function isStarted(): bool {}

    public function isQueued(): bool {}

    /**
     * Return true if the coroutine is running.
     */
    public function isRunning(): bool {}

    /**
     * Return true if the coroutine is suspended.
     */
    public function isSuspended(): bool {}

    /**
     * Return true if the coroutine is cancelled.
     */
    public function isCancelled(): bool {}

    /**
     * Return true if the coroutine is cancellation requested.
     */
    public function isCancellationRequested(): bool {}

    /**
     * Return true if the coroutine is completed.
     */
    public function isCompleted(): bool {}

    /**
     * Return awaiting debug information.
     */
    public function getAwaitingInfo(): array {}

    /**
     * Cancel the coroutine.
     */
    public function cancel(?AsyncCancellation $cancellation = null): void {}

    /**
     * Define a callback to be executed when the coroutine is finished.
     */
    public function onFinally(\Closure $callback): void {}
}
