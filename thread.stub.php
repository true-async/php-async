<?php

/** @generate-class-entries */

namespace Async {

    /**
     * Wraps an exception that originated in a child thread.
     * Always used as a container — the original exception is accessible
     * via getRemoteException(). This provides a clear marker that the
     * exception crossed a thread boundary.
     *
     * @strict-properties
     */
    class RemoteException extends AsyncException
    {
        private ?\Throwable $remoteException = null;
        private string $remoteClass = '';

        /**
         * Get the original exception from the child thread.
         * Returns null if the exception class could not be loaded.
         */
        public function getRemoteException(): ?\Throwable {}

        /**
         * Get the class name of the original exception in the child thread.
         */
        public function getRemoteClass(): string {}
    }

    /**
     * Exception thrown when data transfer between threads fails.
     * For example, when a class required for object deserialization
     * cannot be found or autoloaded in the target thread.
     */
    class ThreadTransferException extends AsyncException {}

    /**
     * Represents a running or completed OS thread.
     * Thread objects are created by spawn_thread() and implement Completable
     * so they can be awaited.
     */
    final class Thread implements Completable
    {
        private function __construct() {}

        public function isRunning(): bool {}

        public function isCompleted(): bool {}

        public function isCancelled(): bool {}

        public function getResult(): mixed {}

        public function getException(): mixed {}

        public function cancel(?AsyncCancellation $cancellation = null): void {}

        public function finally(\Closure $callback): void {}
    }
}
