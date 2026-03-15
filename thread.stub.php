<?php

/** @generate-class-entries */

namespace Async {

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
