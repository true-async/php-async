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
    /** The watched path this event belongs to (the value passed to the watcher). */
    public string $path;

    /**
     * The changed entry, or null if the backend could not name it. For a
     * recursive watch this is relative to the watched $path and may include
     * subdirectories (e.g. "sub/deep/config.php"), not just the leaf name.
     */
    public ?string $filename;

    /** A rename/create/delete was observed for the entry. */
    public bool $renamed;

    /** The entry's contents or metadata changed. */
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
     *   macOS and Windows use the OS-native recursive backend. On Linux/inotify
     *   (and most BSD) inotify is not recursive, so recursion is emulated with
     *   one watch per directory. In the emulated mode:
     *     - newly created subdirectories are watched automatically, and deleted
     *       or moved-out ones are dropped (a path reused later is re-watched);
     *     - symlinked directories are NOT followed (avoids cycles);
     *     - each directory consumes one inotify watch, so very large trees are
     *       bound by the system fs.inotify.max_user_watches limit (and an
     *       internal cap of 8192 directories per watcher);
     *     - the reported FileSystemEvent::$filename is relative to $path
     *       (e.g. "sub/deep/config.php"), not just the leaf name.
     * @param bool $coalesce Merge events per file (true) or deliver every event (false).
     *   Per-file de-duplication of the pending buffer; independent of debounce.
     * @param int $debounceMs Debounce window in milliseconds (0 = disabled, the
     *   default = per-event delivery). When > 0 the watcher collapses a burst of
     *   changes: it stays silent until $debounceMs of quiet passes, then delivers
     *   ONE event (with $filename = null) — the iterator sleeps through the burst
     *   instead of yielding per file. Ideal for reload triggers.
     * @param int $maxHoldMs Safety cap in milliseconds (0 = none). Under a never-
     *   ending stream of changes the quiet window would never elapse; with a cap,
     *   a collapsed event is force-delivered at most $maxHoldMs after the first
     *   change of the burst. Only meaningful with $debounceMs > 0.
     * @param array $extensions Case-insensitive extension allow-list, e.g.
     *   ['php'] or ['.php', '.env'] (empty = every file). A change only counts if
     *   the changed file's extension is listed. Applies in debounce mode.
     */
    public function __construct(string $path, bool $recursive = false, bool $coalesce = true, int $debounceMs = 0, int $maxHoldMs = 0, array $extensions = []) {}

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
