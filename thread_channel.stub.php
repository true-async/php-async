<?php

/** @generate-class-entries */

namespace Async;

/**
 * Exception thrown when attempting to send to or receive from a closed thread channel.
 */
class ThreadChannelException extends AsyncException {}

/**
 * Thread-safe channel for message passing between PHP threads.
 *
 * Unlike Channel (coroutine-only), ThreadChannel uses persistent memory
 * and pthread_mutex for cross-thread safety. Data is transferred via
 * deep copy (pemalloc).
 *
 * Always buffered (capacity >= 1). No rendezvous mode.
 *
 * @strict-properties
 * @not-serializable
 */
final class ThreadChannel implements Awaitable, \Countable
{
    /**
     * Create a new thread-safe channel.
     *
     * @param int $capacity Buffer size (must be >= 1)
     */
    public function __construct(int $capacity = 16) {}

    /**
     * Send a value into the channel (blocking).
     *
     * Suspends the current coroutine until buffer space is available.
     * The value is deep-copied into persistent memory.
     *
     * @param Completable|null $cancellationToken Optional cancellation token
     * @throws ThreadChannelException if channel is closed
     */
    public function send(mixed $value, ?Completable $cancellationToken = null): void {}

    /**
     * Receive a value from the channel (blocking).
     *
     * Suspends the current coroutine until a value is available.
     * The value is copied from persistent memory into the current thread.
     *
     * @param Completable|null $cancellationToken Optional cancellation token
     * @throws ThreadChannelException if channel is closed and empty
     */
    public function recv(?Completable $cancellationToken = null): mixed {}

    /**
     * Close the channel.
     *
     * After closing:
     *  - send() throws ThreadChannelException
     *  - recv() drains remaining values, then throws ThreadChannelException
     *  - All waiting coroutines are woken with ThreadChannelException
     */
    public function close(): void {}

    /**
     * Check whether the channel is closed.
     */
    public function isClosed(): bool {}

    /**
     * Get channel capacity.
     */
    public function capacity(): int {}

    /**
     * Current number of buffered values.
     */
    public function count(): int {}

    /**
     * Check if channel is empty.
     */
    public function isEmpty(): bool {}

    /**
     * Check if channel is full.
     */
    public function isFull(): bool {}
}
