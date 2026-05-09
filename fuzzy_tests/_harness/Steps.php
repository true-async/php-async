<?php
/**
 * Standard step definitions for ext/async chaos tests.
 *
 * Each definition matches a Given / When / Then line and either
 *   - configures the Context plan (Given / When), or
 *   - asserts an invariant after the run (Then).
 *
 * Then-handlers MUST throw on violation — Executor catches and reports.
 *
 * Naming convention for steps:
 *   - Quoted strings ("name") for entity identifiers.
 *   - Bare numbers / range expressions for fuzzed values (1|5, random:10, 0..9).
 *
 * The default registry is intentionally small. Extend by calling
 * StandardSteps::register() then chaining ->on(...) on the returned registry.
 */

namespace Async\Chaos;

require_once __DIR__ . '/Context.php';
require_once __DIR__ . '/StepRegistry.php';

final class StandardSteps {
    public static function register(StepRegistry $r): StepRegistry {
        // ---- Given: setup ----

        // Given a channel "ch" with capacity 0
        $r->on('/^a channel "([^"]+)" with capacity (\S+)$/',
            function(Context $ctx, string $name, string $capExpr) {
                $cap = (int)$ctx->resolver->resolve($capExpr);
                $ctx->defineChannel($name, $cap);
            });

        // Given a coroutine "A"
        $r->on('/^a coroutine "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->defineCoroutine($name);
            });

        // ---- When: actions inside a coroutine ----

        // When coroutine "A" sends N messages to "ch"
        $r->on('/^coroutine "([^"]+)" sends (\S+) messages to "([^"]+)"$/',
            function(Context $ctx, string $coro, string $countExpr, string $ch) {
                $n = (int)$ctx->resolver->resolve($countExpr);
                for ($i = 0; $i < $n; $i++) {
                    $value = $i;
                    $ctx->planAction($coro, function(Context $ctx) use ($ch, $value) {
                        $ctx->channels[$ch]->send($value);
                        $ctx->inc("sent_$ch");
                    });
                }
            });

        // When coroutine "A" sends VAL to "ch"
        $r->on('/^coroutine "([^"]+)" sends (\S+) to "([^"]+)"$/',
            function(Context $ctx, string $coro, string $valExpr, string $ch) {
                $val = $ctx->resolver->resolve($valExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($ch, $val) {
                    $ctx->channels[$ch]->send($val);
                    $ctx->inc("sent_$ch");
                });
            });

        // When coroutine "B" receives N messages from "ch"
        $r->on('/^coroutine "([^"]+)" receives (\S+) messages from "([^"]+)"$/',
            function(Context $ctx, string $coro, string $countExpr, string $ch) {
                $n = (int)$ctx->resolver->resolve($countExpr);
                for ($i = 0; $i < $n; $i++) {
                    $ctx->planAction($coro, function(Context $ctx) use ($ch) {
                        try {
                            $ctx->channels[$ch]->recv();
                            $ctx->inc("received_$ch");
                        } catch (\Throwable $e) {
                            $ctx->inc("recv_failed_$ch");
                        }
                    });
                }
            });

        // When coroutine "X" closes "ch"
        $r->on('/^coroutine "([^"]+)" closes "([^"]+)"$/',
            function(Context $ctx, string $coro, string $ch) {
                $ctx->planAction($coro, function(Context $ctx) use ($ch) {
                    $ctx->channels[$ch]->close();
                    $ctx->inc("closed_$ch");
                });
            });

        // When coroutine "X" suspends
        $r->on('/^coroutine "([^"]+)" suspends$/',
            function(Context $ctx, string $coro) {
                $ctx->planAction($coro, function(Context $ctx) {
                    \Async\suspend();
                });
            });

        // When coroutine "X" sleeps N ms
        $r->on('/^coroutine "([^"]+)" sleeps (\S+) ms$/',
            function(Context $ctx, string $coro, string $msExpr) {
                $ms = (int)$ctx->resolver->resolve($msExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($ms) {
                    \Async\delay($ms);
                });
            });

        // When coroutine "X" prints "msg"
        $r->on('/^coroutine "([^"]+)" prints "([^"]*)"$/',
            function(Context $ctx, string $coro, string $msg) {
                $ctx->planAction($coro, function(Context $ctx) use ($msg) {
                    $ctx->events[] = $msg;
                });
            });

        // ---- Then: invariants ----

        // Then counter "X" equals counter "Y"
        $r->on('/^counter "([^"]+)" equals counter "([^"]+)"$/',
            function(Context $ctx, string $a, string $b) {
                $va = $ctx->counter($a);
                $vb = $ctx->counter($b);
                if ($va !== $vb) {
                    throw new \RuntimeException("counter $a ($va) != counter $b ($vb)");
                }
            });

        // Then counter "X" equals N
        $r->on('/^counter "([^"]+)" equals (\d+)$/',
            function(Context $ctx, string $name, string $expected) {
                $v = $ctx->counter($name);
                if ($v !== (int)$expected) {
                    throw new \RuntimeException("counter $name = $v, expected $expected");
                }
            });

        // Then channel "ch" is closed
        $r->on('/^channel "([^"]+)" is closed$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->channels[$name]) || !$ctx->channels[$name]->isClosed()) {
                    throw new \RuntimeException("channel $name expected to be closed");
                }
            });

        // Then channel "ch" is empty
        $r->on('/^channel "([^"]+)" is empty$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->channels[$name]) || !$ctx->channels[$name]->isEmpty()) {
                    $cnt = $ctx->channels[$name]->count();
                    throw new \RuntimeException("channel $name expected empty, has $cnt items");
                }
            });

        return $r;
    }
}
