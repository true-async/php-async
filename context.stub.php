<?php

/** @generate-class-entries */

namespace Async;

/**
 * Thrown when a mandatory Context key is missing.
 */
class ContextException extends AsyncException {}

/**
 * @strict-properties
 * @not-serializable
 */
final class Context
{
    /**
     * Find a value by key in the current or parent Context.
     *
     * Returns null when the key is absent. Use get() when the value is mandatory.
     */
    public function find(string|object $key): mixed {}

    /**
     * Get a value by key in the current or parent Context.
     *
     * Unlike find(), a missing key is an error rather than a null.
     *
     * @throws ContextException If the key is not found at any level.
     */
    public function get(string|object $key): mixed {}

    /**
     * Check if a key exists in the current Context.
     */
    public function has(string|object $key): bool {}

    /**
     * Find a value by key only in the local Context.
     *
     * Returns null when the key is absent. Use getLocal() when the value is mandatory.
     */
    public function findLocal(string|object $key): mixed {}

    /**
     * Get a value by key only in the local Context.
     *
     * Unlike findLocal(), a missing key is an error rather than a null.
     *
     * @throws ContextException If the key is not found in the local Context.
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