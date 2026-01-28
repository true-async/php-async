<?php

declare(strict_types=1);

namespace Async;

/**
 * Channel is a concurrency primitive for message passing
 * between coroutines.
 *
 * The channel can be:
 *  - unbuffered (capacity = 0): rendezvous semantics
 *  - buffered   (capacity > 0)
 *  - unbounded  (capacity < 0)
 */
final class Channel
{
    /**
     * Create a new channel.
     *
     * @param int $capacity
     *   0  = unbuffered (rendezvous)
     *  >0  = bounded buffer
     *  <0  = unbounded buffer
     */
    public function __construct(int $capacity = 0) {}

    /**
     * Send a value into the channel.
     *
     * Suspends the current coroutine until the value
     * is received or buffered.
     *
     * @throws ChannelClosedException
     */
    public function send(mixed $value): Future {}

    /**
     * Receive a value from the channel.
     *
     * Suspends the current coroutine until a value
     * is available.
     *
     * @return Future<mixed>
     *   Returns null when the channel is closed
     *   and no more values are available.
     */
    public function recv(): Future {}

    /**
     * Close the channel.
     *
     * After closing:
     *  - send() fails
     *  - recv() drains remaining values, then returns null
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
     *
     * This value is informational and may change immediately.
     */
    public function size(): int {}
}
