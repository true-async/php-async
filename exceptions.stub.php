<?php

/** @generate-class-entries */

namespace Async;

/**
 * Exception thrown when a Coroutine is canceled.
 * Code inside the Coroutine must properly handle this exception to ensure graceful termination.
 */
class CancellationError extends \Error {}

/**
 * Common type of exception.
 */
class AsyncException extends \Exception {}

/**
 * General exception for input/output operations.
 * Can be used with sockets, files, pipes, and other I/O descriptors.
 */
class InputOutputException extends \Exception {}

/**
 * Exception for Dns related errors: getaddrinfo and getnameinfo.
 */
class DnsException extends \Exception {}

/**
 * Exception thrown when a timeout occurs.
 */
class TimeoutException extends \Exception {}

/**
 * Exception thrown when a poll operation fails.
 */
class PollException extends \Exception {}

/**
 * Exception that can contain multiple exceptions.
 * Used when multiple exceptions occur in finally handlers.
 * @strict-properties
 */
final class CompositeException extends \Exception
{
    private array $exceptions;
    
    /**
     * Add an exception to the composite.
     */
    public function addException(\Throwable $exception): void {}
    
    /**
     * Get all exceptions stored in this composite.
     * @return array Array of Throwable objects
     */
    public function getExceptions(): array {}
}