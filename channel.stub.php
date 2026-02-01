<?php

/** @generate-class-entries */

namespace Async;

/**
 * Exception thrown when attempting to send to or receive from a closed channel.
 */
class ChannelException extends AsyncException {}

/**
 * Channel is a concurrency primitive for message passing between coroutines.
 *
 * The channel can be:
 *  - unbuffered (capacity = 0): rendezvous semantics, direct handoff
 *  - buffered   (capacity > 0): bounded buffer
 *
 * @strict-properties
 * @not-serializable
 */
final class Channel implements Awaitable, \IteratorAggregate, \Countable
{
    /**
     * Create a new channel.
     *
     * @param int $capacity
     *   0  = unbuffered (rendezvous) - send blocks until receive
     *  >0  = bounded buffer
     */
    public function __construct(int $capacity = 0) {}

    /**
     * Send a value into the channel (blocking).
     *
     * Suspends the current coroutine until the value
     * is received (unbuffered) or buffered.
     *
     * @throws ChannelException if channel is closed
     */
    public function send(mixed $value): void {}

    /**
     * Try to send a value without blocking.
     *
     * @return bool true if sent successfully, false if channel is full or closed
     */
    public function sendAsync(mixed $value): bool {}

    /**
     * Receive a value from the channel (blocking).
     *
     * Suspends the current coroutine until a value is available.
     *
     * @throws ChannelException if channel is closed and empty
     */
    public function recv(): mixed {}

    /**
     * Receive a value without blocking, returns Future.
     *
     * @return Future<mixed> Future that resolves to the received value
     */
    public function recvAsync(): Future {}

    /**
     * Close the channel.
     *
     * After closing:
     *  - send() throws ChannelException
     *  - recv() drains remaining values, then throws ChannelException
     *  - All waiting coroutines are woken with ChannelException
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

    /**
     * Get iterator for foreach support.
     *
     * Allows: foreach ($channel as $value) { ... }
     * Iteration stops when channel is closed and empty.
     */
    public function getIterator(): \Iterator {}
}
