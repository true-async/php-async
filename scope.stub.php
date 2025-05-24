<?php

/** @generate-class-entries */

namespace Async;

interface ScopeProvider
{
    public function provideScope(): ?Scope;
}

interface SpawnStrategy extends ScopeProvider
{
    /**
     * Called before a coroutine is spawned, before it is enqueued.
     *
     * @param   Coroutine   $coroutine  The coroutine to be spawned.
     * @param   Scope       $scope      The Scope instance.
     *
     */
    public function beforeCoroutineEnqueue(Coroutine $coroutine, Scope $scope): array;

    /**
     * Called after a coroutine is spawned, enqueued.
     *
     * @param Coroutine $coroutine
     * @param Scope     $scope
     */
    public function afterCoroutineEnqueue(Coroutine $coroutine, Scope $scope): void;
}

/**
 * @strict-properties
 * @not-serializable
 */
final class Scope implements ScopeProvider
{
    //public readonly Context $context;

    /**
     * Creates a new Scope that inherits from the specified one. If the parameter is not provided,
     * the Scope inherits from the current one.
     *
     * @param Scope|null $parentScope
     *
     * @return Scope
     */
    public static function inherit(?Scope $parentScope = null): Scope {}

    #[\Override] public function provideScope(): Scope {}

    public function __construct() {}

    public function asNotSafely(): Scope {}

    public function spawn(\Closure $callable, mixed ...$params): Coroutine {}

    public function cancel(?CancellationException $cancellationException = null): void {}

    public function awaitCompletion(Awaitable $cancellation): void {}

    public function awaitAfterCancellation(?callable $errorHandler = null, ?Awaitable $cancellation = null): void {}

    public function isFinished(): bool {}

    public function isClosed(): bool {}

    /**
     * Sets an error handler that is called when an exception is passed to the Scope from one of its child coroutines.
     */
    public function setExceptionHandler(callable $exceptionHandler): void {}

    /**
     * Exception handler for child Scope.
     * Setting this handler prevents the exception from propagating to the current Scope.
     */
    public function setChildScopeExceptionHandler(callable $exceptionHandler): void {}

    public function onFinally(\Closure $callback): void {}

    public function dispose(): void {}

    public function disposeSafely(): void {}

    public function disposeAfterTimeout(int $timeout): void {}

    public function getChildScopes(): array {}
}