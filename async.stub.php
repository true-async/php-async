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
function protect(\Closure $closure): void {}

function await(Awaitable $awaitable, ?Awaitable $cancellation = null): mixed {}

function awaitAny(iterable $triggers, ?Awaitable $cancellation = null): mixed {}

function awaitFirstSuccess(iterable $triggers, ?Awaitable $cancellation = null): mixed {}

function awaitAll(iterable $triggers, ?Awaitable $cancellation = null, bool $fillNull = false): array {}

function awaitAllWithErrors(iterable $triggers, ?Awaitable $cancellation = null, bool $fillNull = false): array {}

function awaitAnyOf(int $count, iterable $triggers, ?Awaitable $cancellation = null): array {}

function awaitAnyOfWithErrors(int $count, iterable $triggers, ?Awaitable $cancellation = null): array {}

function delay(int $ms): void {}

function timeout(int $ms): Awaitable {}

function currentContext(): Context {}

function coroutineContext(): Context {}

/**
 * Returns the current coroutine.
 */
function currentCoroutine(): Coroutine {}

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
function gracefulShutdown(?CancellationException $cancellationException = null): void {}

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