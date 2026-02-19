<?php

/** @generate-class-entries */

namespace Async;

/**
 * Represents a filesystem event detected by FileSystemWatcher.
 *
 * @strict-properties
 * @not-serializable
 */
final readonly class FileSystemEvent
{
    public string $path;
    public ?string $filename;
    public bool $renamed;
    public bool $changed;
}

/**
 * Persistent filesystem watcher that buffers events for async iteration.
 *
 * Monitors a file or directory for changes and delivers events via foreach or await().
 * Two buffering modes: coalesce (merge events per file) or raw (every event separately).
 *
 * @strict-properties
 * @not-serializable
 */
final class FileSystemWatcher implements Awaitable, \IteratorAggregate
{
    /**
     * Create a new filesystem watcher and start monitoring immediately.
     *
     * @param string $path Path to file or directory to watch.
     * @param bool $recursive Watch subdirectories recursively.
     * @param bool $coalesce Merge events per file (true) or deliver every event (false).
     */
    public function __construct(string $path, bool $recursive = false, bool $coalesce = true) {}

    /**
     * Stop watching and terminate any active iteration.
     * Idempotent — safe to call multiple times.
     */
    public function close(): void {}

    /**
     * Check if the watcher has been closed.
     */
    public function isClosed(): bool {}

    /**
     * Get async iterator for foreach support.
     *
     * Yields FileSystemEvent objects as filesystem changes are detected.
     * Iteration suspends when no events are available and resumes on the next event.
     * Iteration ends when close() is called or scope is cancelled.
     */
    public function getIterator(): \Iterator {}
}
