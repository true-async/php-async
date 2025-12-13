<?php

/** @generate-class-entries */

namespace Async;

/**
 * A back-end class for the Future class.
 * The owner of this object can resolve the state of the Future. The class is a descendant of Notifier and can
 * participate in the EventLoop.
 *
 * @strict-properties
 * @not-serializable
 * @template T
 */
final class FutureState
{
    public function __construct() {}

    /**
     * Completes the operation with a result value.
     *
     * @param T $result Result of the operation.
     */
    public function complete(mixed $result): void {}

    /**
     * Marks the operation as failed.
     *
     * @param \Throwable $throwable Throwable to indicate the error.
     */
    public function error(\Throwable $throwable): void {}

    /**
     * @return bool True if the operation has completed.
     */
    public function isComplete(): bool {}

    /**
     * Suppress the exception thrown to the loop error handler if and operation error is not handled by a callback.
     */
    public function ignore(): void {}

    /**
     * Return awaiting debug information.
     */
    public function getAwaitingInfo(): array {}
}

/**
 * @template-covariant T
 */
final class Future implements FutureLike
{
    /**
     * @template Tv
     *
     * @param Tv $value
     *
     * @return Future<Tv>
     */
    public static function complete(mixed $value = null): Future {}

    /**
     * @return Future<never>
     */
    public static function error(\Throwable $throwable): Future {}

    /**
     * param FutureState<T> $state
     */
    public function __construct(FutureState $state) {}

    /**
     * @return bool True if the operation has completed.
     */
    public function isComplete(): bool {}

    /**
     * Do not forward unhandled errors to the event loop handler.
     *
     * @return Future<T>
     */
    public function ignore(): Future {}

    /**
     * Attaches a callback that is invoked if this future completes. The returned future is completed with the return
     * value of the callback, or errors with an exception thrown from the callback.
     *
     * @psalm-suppress InvalidTemplateParam
     *
     * @template Tr
     *
     * @param callable(T):Tr $map
     *
     * @return Future<Tr>
     */
    public function map(callable $map): Future {}

    /**
     * Attaches a callback that is invoked if this future errors. The returned future is completed with the return
     * value of the callback, or errors with an exception thrown from the callback.
     *
     * @template Tr
     *
     * @param callable(\Throwable):Tr $catch
     *
     * @return Future<Tr>
     */
    public function catch(callable $catch): Future {}

    /**
     * Attaches a callback that is always invoked when the future is completed. The returned future resolves with the
     * same value as this future once the callback has finished execution. If the callback throws, the returned future
     * will error with the thrown exception.
     *
     * @param \Closure():void $finally
     *
     * @return Future<T>
     */
    public function finally(callable $finally): Future {}

    /**
     * Awaits the operation to complete.
     *
     * Throws an exception if the operation fails.
     *
     * @return T
     */
    public function await(?Awaitable $cancellation = null): mixed {}

    /**
     * Return awaiting debug information.
     */
    public function getAwaitingInfo(): array {}
}