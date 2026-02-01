<?php

/** @generate-class-entries */

namespace Async;

interface Awaitable {}

interface Completable extends Awaitable {
    public function cancel(?CancellationError $cancellation = null): void;
    public function isCompleted(): bool;
    public function isCancelled(): bool;
}

final class Timeout implements Completable
{
    private function __construct() {}

    public function cancel(?CancellationError $cancellation = null): void {}

    public function isCompleted(): bool {}

    public function isCancelled(): bool {}
}

/**
 * Returns the current Coroutine.
 *
 * @return Coroutine
 */
function spawn(callable $task, mixed ... $args): Coroutine {}

/**
 * Returns the current Coroutine.
 *
 * @return Coroutine
 */
function spawn_with(ScopeProvider $provider, callable $task, mixed ... $args): Coroutine {}

/**
 * Suspends the execution of a Coroutine until the Scheduler takes control.
 */
function suspend(): void {}

/**
 * Execute the provided closure in non-cancellable mode.
 */
function protect(\Closure $closure): mixed {}

function await(Completable $awaitable, ?Completable $cancellation = null): mixed {}

function await_any_or_fail(iterable $triggers, ?Awaitable $cancellation = null): mixed {}

function await_first_success(iterable $triggers, ?Awaitable $cancellation = null): mixed {}

function await_all_or_fail(iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true): array {}

function await_all(iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true, bool $fillNull = false): array {}

function await_any_of_or_fail(int $count, iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true): array {}

function await_any_of(int $count, iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true, bool $fillNull = false): array {}

function delay(int $ms): void {}

function timeout(int $ms): Awaitable {}

function current_context(): Context {}

function coroutine_context(): Context {}

/**
 * Returns the current coroutine.
 */
function current_coroutine(): Coroutine {}

/**
 * Adds an onFinally handler for the current coroutine.
 */
//function onFinally(\Closure $callback): void {}

/**
 * Returns the root Scope.
 */
function root_context(): Context {}

/**
 * Returns the list of all coroutines
 *
 * @return Coroutine[]
 */
function get_coroutines(): array {}

/**
 * Start the graceful shutdown of the Scheduler.
 */
function graceful_shutdown(?CancellationError $cancellationError = null): void {}

/**
 * Execute an external program.
 * @return Future<array{string, int}>
 */
/*
function exec(
    string $command,
    int $timeout        = 0,
    ?string $cwd        = null,
    ?array $env         = null,
    bool $returnAll     = false
): Future {}
*/