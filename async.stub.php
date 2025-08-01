<?php

/** @generate-class-entries */

namespace Async;

interface Awaitable {}

final class Timeout implements Awaitable
{
    private function __construct() {}
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
function spawnWith(ScopeProvider $provider, callable $task, mixed ... $args): Coroutine {}

/**
 * Suspends the execution of a Coroutine until the Scheduler takes control.
 */
function suspend(): void {}

/**
 * Execute the provided closure in non-cancellable mode.
 */
function protect(\Closure $closure): mixed {}

function await(Awaitable $awaitable, ?Awaitable $cancellation = null): mixed {}

function awaitAnyOrFail(iterable $triggers, ?Awaitable $cancellation = null): mixed {}

function awaitFirstSuccess(iterable $triggers, ?Awaitable $cancellation = null): mixed {}

function awaitAllOrFail(iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true): array {}

function awaitAll(iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true, bool $fillNull = false): array {}

function awaitAnyOfOrFail(int $count, iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true): array {}

function awaitAnyOf(int $count, iterable $triggers, ?Awaitable $cancellation = null, bool $preserveKeyOrder = true, bool $fillNull = false): array {}

function delay(int $ms): void {}

function timeout(int $ms): Awaitable {}

function currentContext(): Context {}

function coroutineContext(): Context {}

/**
 * Returns the current coroutine.
 */
function currentCoroutine(): Coroutine {}

/**
 * Adds an onFinally handler for the current coroutine.
 */
//function onFinally(\Closure $callback): void {}

/**
 * Returns the root Scope.
 */
function rootContext(): Context {}

/**
 * Returns the list of all coroutines
 *
 * @return Coroutine[]
 */
function getCoroutines(): array {}

/**
 * Start the graceful shutdown of the Scheduler.
 */
function gracefulShutdown(?CancellationError $cancellationError = null): void {}

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