<?php

/** @generate-class-entries */

namespace Async;

/**
 * Thrown when a mandatory Context key is missing.
 */
class ContextException extends AsyncException {}

/**
 * Key-value store owned by a Scope and shared by every coroutine running in it.
 *
 * find(), get() and has() read this Context first and then the Contexts of the Scopes above it.
 * A Scope receives a Context only when someone asks for one, so scopes without a Context are
 * skipped rather than ending the search; the search ends at a Scope with no parent, which is the
 * main Scope or one created by `new Scope()`. The *Local() forms read this Context alone.
 *
 * @strict-properties
 * @not-serializable
 */
final class Context
{
    /**
     * Find a value by key in this Context, then in the Contexts of the Scopes above it.
     *
     * Returns null when no level holds the key. Use get() when the value is mandatory.
     */
    public function find(string|object $key): mixed {}

    /**
     * Get a value by key in this Context, then in the Contexts of the Scopes above it.
     *
     * Unlike find(), a missing key is an error rather than a null.
     *
     * @throws ContextException If the key is not found at any level.
     */
    public function get(string|object $key): mixed {}

    /**
     * Check if a key exists in this Context or in the Contexts of the Scopes above it.
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
     * Set a value by key in this Context and return the same Context.
     *
     * A key already held by this Context is an error unless $replace is true; a key inherited
     * from a Scope above does not count as held.
     */
    public function set(string|object $key, mixed $value, bool $replace = false): Context {}

    /**
     * Delete a value by key from this Context and return the same Context.
     *
     * Keys held by the Scopes above are left alone.
     */
    public function unset(string|object $key): Context {}
}