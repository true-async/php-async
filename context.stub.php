<?php

/** @generate-class-entries */

namespace Async;

/**
 * @strict-properties
 * @not-serializable
 */
final class Context
{
    /**
     * Find a value by key in the current or parent Context.
     */
    public function find(string|object $key): mixed {}

    /**
     * Get a value by key in the current Context.
     */
    public function get(string|object $key): mixed {}

    /**
     * Check if a key exists in the current Context.
     */
    public function has(string|object $key): bool {}

    /**
     * Find a value by key only in the local Context.
     */
    public function findLocal(string|object $key): mixed {}

    /**
     * Get a value by key only in the local Context.
     */
    public function getLocal(string|object $key): mixed {}

    /**
     * Check if a key exists in the local Context.
     */
    public function hasLocal(string|object $key): bool {}

    /**
     * Set a value by key in the Context.
     */
    public function set(string|object $key, mixed $value, bool $replace = false): Context {}

    /**
     * Delete a value by key from the Context.
     */
    public function unset(string|object $key): Context {}
}