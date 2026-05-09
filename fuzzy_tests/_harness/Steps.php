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

        // Given a coroutine "A" in scope "S"
        $r->on('/^a coroutine "([^"]+)" in scope "([^"]+)"$/',
            function(Context $ctx, string $name, string $scope) {
                $ctx->defineScope($scope);
                $ctx->defineCoroutine($name, $scope);
            });

        // Given a scope "S"
        $r->on('/^a scope "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->defineScope($name);
            });

        // Given a future "F"
        $r->on('/^a future "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->defineFuture($name);
            });

        // ---- When: actions inside a coroutine ----

        // When coroutine "A" sends N messages to "ch"
        // Increments three counters: send_attempts_$ch (always), then either
        // sent_$ch (on success) or send_failed_$ch (when channel was closed).
        $r->on('/^coroutine "([^"]+)" sends (\S+) messages to "([^"]+)"$/',
            function(Context $ctx, string $coro, string $countExpr, string $ch) {
                $n = (int)$ctx->resolver->resolve($countExpr);
                for ($i = 0; $i < $n; $i++) {
                    $value = $i;
                    $ctx->planAction($coro, function(Context $ctx) use ($ch, $value) {
                        $ctx->inc("send_attempts_$ch");
                        try {
                            $ctx->channels[$ch]->send($value);
                            $ctx->inc("sent_$ch");
                        } catch (\Throwable $e) {
                            $ctx->inc("send_failed_$ch");
                        }
                    });
                }
            });

        // When coroutine "A" sends VAL to "ch"
        $r->on('/^coroutine "([^"]+)" sends (\S+) to "([^"]+)"$/',
            function(Context $ctx, string $coro, string $valExpr, string $ch) {
                $val = $ctx->resolver->resolve($valExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($ch, $val) {
                    $ctx->inc("send_attempts_$ch");
                    try {
                        $ctx->channels[$ch]->send($val);
                        $ctx->inc("sent_$ch");
                    } catch (\Throwable $e) {
                        $ctx->inc("send_failed_$ch");
                    }
                });
            });

        // When coroutine "B" receives N messages from "ch"
        // Mirror: recv_attempts_$ch / received_$ch / recv_failed_$ch.
        $r->on('/^coroutine "([^"]+)" receives (\S+) messages from "([^"]+)"$/',
            function(Context $ctx, string $coro, string $countExpr, string $ch) {
                $n = (int)$ctx->resolver->resolve($countExpr);
                for ($i = 0; $i < $n; $i++) {
                    $ctx->planAction($coro, function(Context $ctx) use ($ch) {
                        $ctx->inc("recv_attempts_$ch");
                        try {
                            $ctx->channels[$ch]->recv();
                            $ctx->inc("received_$ch");
                        } catch (\Throwable $e) {
                            $ctx->inc("recv_failed_$ch");
                        }
                    });
                }
            });

        // When coroutine "X" iterates "ch" and counts
        // foreach over the Channel until it closes; each delivered item
        // increments iterated_$ch.
        $r->on('/^coroutine "([^"]+)" iterates "([^"]+)" and counts$/',
            function(Context $ctx, string $coro, string $ch) {
                $ctx->planAction($coro, function(Context $ctx) use ($ch) {
                    $ctx->inc("iterate_attempts_$ch");
                    try {
                        foreach ($ctx->channels[$ch] as $value) {
                            $ctx->inc("iterated_$ch");
                        }
                    } catch (\Throwable $e) {
                        $ctx->inc("iterate_failed_$ch");
                    }
                });
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

        // When coroutine "X" completes future "F" with VAL
        $r->on('/^coroutine "([^"]+)" completes future "([^"]+)" with (\S+)$/',
            function(Context $ctx, string $coro, string $f, string $valExpr) {
                $val = $ctx->resolver->resolve($valExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($f, $val) {
                    $ctx->inc("complete_attempts_$f");
                    try {
                        $ctx->futureStates[$f]->complete($val);
                        $ctx->inc("completed_$f");
                    } catch (\Throwable $e) {
                        $ctx->inc("complete_failed_$f");
                    }
                });
            });

        // When coroutine "X" awaits any of futures "F1,F2,F3"
        $r->on('/^coroutine "([^"]+)" awaits any of futures "([^"]+)"$/',
            function(Context $ctx, string $coro, string $list) {
                $names = array_map('trim', explode(',', $list));
                $ctx->planAction($coro, function(Context $ctx) use ($names) {
                    $ctx->inc('await_any_attempts');
                    $futures = [];
                    foreach ($names as $n) {
                        if (isset($ctx->futures[$n])) {
                            $futures[] = $ctx->futures[$n];
                        }
                    }
                    try {
                        \Async\await_any_or_fail($futures);
                        $ctx->inc('await_any_succeeded');
                    } catch (\Throwable $e) {
                        $ctx->inc('await_any_failed');
                    }
                });
            });

        // When coroutine "X" awaits future "F"
        $r->on('/^coroutine "([^"]+)" awaits future "([^"]+)"$/',
            function(Context $ctx, string $coro, string $f) {
                $ctx->planAction($coro, function(Context $ctx) use ($f) {
                    $ctx->inc("await_attempts_$f");
                    try {
                        $ctx->futures[$f]->await();
                        $ctx->inc("awaited_$f");
                    } catch (\Throwable $e) {
                        $ctx->inc("await_failed_$f");
                    }
                });
            });

        // When coroutine "X" cancels scope "S"
        $r->on('/^coroutine "([^"]+)" cancels scope "([^"]+)"$/',
            function(Context $ctx, string $caller, string $scope) {
                $ctx->planAction($caller, function(Context $ctx) use ($scope) {
                    $ctx->inc('scope_cancel_attempts');
                    if (!isset($ctx->scopes[$scope])) {
                        $ctx->inc('scope_cancel_target_missing');
                        return;
                    }
                    try {
                        $ctx->scopes[$scope]->cancel();
                        $ctx->inc("scope_cancelled_$scope");
                    } catch (\Throwable $e) {
                        $ctx->inc('scope_cancel_threw');
                    }
                });
            });

        // When coroutine "X" cancels coroutine "Y"
        $r->on('/^coroutine "([^"]+)" cancels coroutine "([^"]+)"$/',
            function(Context $ctx, string $caller, string $target) {
                $ctx->planAction($caller, function(Context $ctx) use ($target) {
                    $ctx->inc('cancel_attempts');
                    if (!isset($ctx->coroutineHandles[$target])) {
                        $ctx->inc('cancel_target_missing');
                        return;
                    }
                    try {
                        $ctx->coroutineHandles[$target]->cancel();
                        $ctx->inc("cancelled_$target");
                    } catch (\Throwable $e) {
                        $ctx->inc('cancel_threw');
                    }
                });
            });

        // When coroutine "X" throws
        $r->on('/^coroutine "([^"]+)" throws$/',
            function(Context $ctx, string $coro) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro) {
                    $ctx->inc('throw_attempts');
                    $ctx->inc("threw_$coro");
                    throw new \RuntimeException("planned error from $coro");
                });
            });

        // When coroutine "X" prints "msg"
        $r->on('/^coroutine "([^"]+)" prints "([^"]*)"$/',
            function(Context $ctx, string $coro, string $msg) {
                $ctx->planAction($coro, function(Context $ctx) use ($msg) {
                    $ctx->events[] = $msg;
                    $ctx->inc('printed_total');
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

        // Then counter "X" plus counter "Y" equals N  (sum invariant)
        $r->on('/^counter "([^"]+)" plus counter "([^"]+)" equals (\d+)$/',
            function(Context $ctx, string $a, string $b, string $expected) {
                $sum = $ctx->counter($a) + $ctx->counter($b);
                if ($sum !== (int)$expected) {
                    throw new \RuntimeException(
                        "counter $a + counter $b = " .
                        $ctx->counter($a) . ' + ' . $ctx->counter($b) .
                        " = $sum, expected $expected"
                    );
                }
            });

        // Then counter "X" plus counter "Y" equals counter "Z"
        $r->on('/^counter "([^"]+)" plus counter "([^"]+)" equals counter "([^"]+)"$/',
            function(Context $ctx, string $a, string $b, string $c) {
                $sum = $ctx->counter($a) + $ctx->counter($b);
                $cv = $ctx->counter($c);
                if ($sum !== $cv) {
                    throw new \RuntimeException(
                        "counter $a + counter $b = $sum, but counter $c = $cv"
                    );
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

        // Then counter "X" is at most N
        $r->on('/^counter "([^"]+)" is at most (\d+)$/',
            function(Context $ctx, string $name, string $bound) {
                $v = $ctx->counter($name);
                if ($v > (int)$bound) {
                    throw new \RuntimeException("counter $name = $v exceeds bound $bound");
                }
            });

        // Then channel "ch" is closed
        $r->on('/^channel "([^"]+)" is closed$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->channels[$name]) || !$ctx->channels[$name]->isClosed()) {
                    throw new \RuntimeException("channel $name expected to be closed");
                }
            });

        // Then no orphan coroutines  (await_all completed for every planned coroutine)
        $r->on('/^no orphan coroutines$/',
            function(Context $ctx) {
                // If any coroutine had not finished, await_all() would have either
                // hung or thrown — reaching this step means all completed.
                // We verify the structural fact via Async\get_coroutines()
                // (excluding the main coroutine which is always present).
                $live = \Async\get_coroutines();
                if (count($live) > 1) {
                    $names = [];
                    foreach ($live as $c) {
                        $names[] = $c->getId();
                    }
                    throw new \RuntimeException(
                        'expected only the main coroutine to remain, got: ' . implode(',', $names)
                    );
                }
            });

        // Then scope "S" is finished
        $r->on('/^scope "([^"]+)" is finished$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->scopes[$name]) || !$ctx->scopes[$name]->isFinished()) {
                    throw new \RuntimeException("scope $name expected to be finished");
                }
            });

        // Then scope "S" is cancelled
        $r->on('/^scope "([^"]+)" is cancelled$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->scopes[$name]) || !$ctx->scopes[$name]->isCancelled()) {
                    throw new \RuntimeException("scope $name expected to be cancelled");
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
