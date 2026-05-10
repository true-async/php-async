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

        // Given a child scope "C" of "P"   (Scope::inherit)
        $r->on('/^a child scope "([^"]+)" of "([^"]+)"$/',
            function(Context $ctx, string $child, string $parent) {
                $ctx->defineScope($parent);
                $ctx->defineScope($child);
                $ctx->scopeParent[$child] = $parent;
            });

        // Given a future "F"
        $r->on('/^a future "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->defineFuture($name);
            });

        // Given a thread pool "P" with N workers
        $r->on('/^a thread pool "([^"]+)" with (\S+) workers$/',
            function(Context $ctx, string $name, string $wExpr) {
                $w = (int)$ctx->resolver->resolve($wExpr);
                $ctx->defineThreadPool($name, $w);
            });

        // Given a thread pool "P" with N workers and queue size Q
        $r->on('/^a thread pool "([^"]+)" with (\S+) workers and queue size (\S+)$/',
            function(Context $ctx, string $name, string $wExpr, string $qExpr) {
                $w = (int)$ctx->resolver->resolve($wExpr);
                $q = (int)$ctx->resolver->resolve($qExpr);
                $ctx->defineThreadPool($name, $w, $q);
            });

        // Given a thread channel "X" with capacity N
        $r->on('/^a thread channel "([^"]+)" with capacity (\S+)$/',
            function(Context $ctx, string $name, string $capExpr) {
                $cap = (int)$ctx->resolver->resolve($capExpr);
                $ctx->defineThreadChannel($name, $cap);
            });

        // Given a task group "G"
        $r->on('/^a task group "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->defineTaskGroup($name);
            });

        // Given a task group "G" with concurrency N
        $r->on('/^a task group "([^"]+)" with concurrency (\S+)$/',
            function(Context $ctx, string $name, string $cExpr) {
                $c = (int)$ctx->resolver->resolve($cExpr);
                $ctx->defineTaskGroup($name, $c);
            });

        // Given a task group "G" with concurrency N and queue limit M
        $r->on('/^a task group "([^"]+)" with concurrency (\S+) and queue limit (\S+)$/',
            function(Context $ctx, string $name, string $cExpr, string $qExpr) {
                $c = (int)$ctx->resolver->resolve($cExpr);
                $q = (int)$ctx->resolver->resolve($qExpr);
                $ctx->defineTaskGroup($name, $c, $q);
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

        // When coroutine "X" fails future "F" with "msg"
        $r->on('/^coroutine "([^"]+)" fails future "([^"]+)" with "([^"]*)"$/',
            function(Context $ctx, string $coro, string $f, string $msg) {
                $ctx->planAction($coro, function(Context $ctx) use ($f, $msg) {
                    $ctx->inc("error_attempts_$f");
                    try {
                        $ctx->futureStates[$f]->error(new \RuntimeException($msg));
                        $ctx->inc("errored_$f");
                    } catch (\Throwable $e) {
                        $ctx->inc("error_failed_$f");
                    }
                });
            });

        // When coroutine "X" awaits all of futures "F1,F2,F3"   (await_all_or_fail)
        $r->on('/^coroutine "([^"]+)" awaits all of futures "([^"]+)"$/',
            function(Context $ctx, string $coro, string $list) {
                $names = array_map('trim', explode(',', $list));
                $ctx->planAction($coro, function(Context $ctx) use ($names) {
                    $ctx->inc('await_all_attempts');
                    $futures = [];
                    foreach ($names as $n) {
                        if (isset($ctx->futures[$n])) {
                            $futures[] = $ctx->futures[$n];
                        }
                    }
                    try {
                        $res = \Async\await_all_or_fail($futures);
                        $ctx->inc('await_all_succeeded');
                        $ctx->inc('await_all_received', count($res));
                    } catch (\Throwable $e) {
                        $ctx->inc('await_all_failed');
                    }
                });
            });

        // When coroutine "X" awaits first success of futures "F1,F2,F3"
        $r->on('/^coroutine "([^"]+)" awaits first success of futures "([^"]+)"$/',
            function(Context $ctx, string $coro, string $list) {
                $names = array_map('trim', explode(',', $list));
                $ctx->planAction($coro, function(Context $ctx) use ($names) {
                    $ctx->inc('await_first_attempts');
                    $futures = [];
                    foreach ($names as $n) {
                        if (isset($ctx->futures[$n])) {
                            $futures[] = $ctx->futures[$n];
                        }
                    }
                    try {
                        \Async\await_first_success($futures);
                        $ctx->inc('await_first_succeeded');
                    } catch (\Throwable $e) {
                        $ctx->inc('await_first_failed');
                    }
                });
            });

        // When coroutine "X" awaits K out of futures "F1,F2,F3"   (await_any_of_or_fail)
        $r->on('/^coroutine "([^"]+)" awaits (\S+) out of futures "([^"]+)"$/',
            function(Context $ctx, string $coro, string $kExpr, string $list) {
                $k = (int)$ctx->resolver->resolve($kExpr);
                $names = array_map('trim', explode(',', $list));
                $ctx->planAction($coro, function(Context $ctx) use ($k, $names) {
                    $ctx->inc('await_anyof_attempts');
                    $futures = [];
                    foreach ($names as $n) {
                        if (isset($ctx->futures[$n])) {
                            $futures[] = $ctx->futures[$n];
                        }
                    }
                    try {
                        $res = \Async\await_any_of_or_fail($k, $futures);
                        $ctx->inc('await_anyof_succeeded');
                        $ctx->inc('await_anyof_received', count($res));
                    } catch (\Throwable $e) {
                        $ctx->inc('await_anyof_failed');
                    }
                });
            });

        // When coroutine "X" awaits all mixed triggers "F1,C1,ch1"
        // Names are looked up in futures, then coroutineHandles, then channels.
        $r->on('/^coroutine "([^"]+)" awaits all mixed triggers "([^"]+)"$/',
            function(Context $ctx, string $coro, string $list) {
                $names = array_map('trim', explode(',', $list));
                $ctx->planAction($coro, function(Context $ctx) use ($names) {
                    $ctx->inc('await_mixed_attempts');
                    $triggers = [];
                    foreach ($names as $n) {
                        if (isset($ctx->futures[$n])) {
                            $triggers[] = $ctx->futures[$n];
                        } elseif (isset($ctx->coroutineHandles[$n])) {
                            $triggers[] = $ctx->coroutineHandles[$n];
                        } elseif (isset($ctx->channels[$n])) {
                            $triggers[] = $ctx->channels[$n];
                        }
                    }
                    try {
                        // await_all returns [results, errors]; with fillNull
                        // results contains every slot, including null returns.
                        [$results, $errors] = \Async\await_all($triggers, null, true, true);
                        $ctx->inc('await_mixed_succeeded');
                        $ctx->inc('await_mixed_received', count($results));
                        $ctx->inc('await_mixed_errors', count($errors));
                    } catch (\Throwable $e) {
                        $ctx->inc('await_mixed_failed');
                    }
                });
            });

        // When coroutine "X" awaits any of futures "F1,F2" with cancellation future "FC"
        $r->on('/^coroutine "([^"]+)" awaits any of futures "([^"]+)" with cancellation future "([^"]+)"$/',
            function(Context $ctx, string $coro, string $list, string $cancelName) {
                $names = array_map('trim', explode(',', $list));
                $ctx->planAction($coro, function(Context $ctx) use ($names, $cancelName) {
                    $ctx->inc('await_any_attempts');
                    $futures = [];
                    foreach ($names as $n) {
                        if (isset($ctx->futures[$n])) {
                            $futures[] = $ctx->futures[$n];
                        }
                    }
                    $cancellation = $ctx->futures[$cancelName] ?? null;
                    try {
                        \Async\await_any_or_fail($futures, $cancellation);
                        $ctx->inc('await_any_succeeded');
                    } catch (\Throwable $e) {
                        $ctx->inc('await_any_failed');
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

        // Given scope "S" has an exception handler
        $r->on('/^scope "([^"]+)" has an exception handler$/',
            function(Context $ctx, string $scope) {
                $ctx->defineScope($scope);
                $ctx->scopeExceptionHandler[$scope] = true;
            });

        // Given scope "S" has a finally handler
        $r->on('/^scope "([^"]+)" has a finally handler$/',
            function(Context $ctx, string $scope) {
                $ctx->defineScope($scope);
                $ctx->scopeFinally[$scope] = true;
            });

        // When coroutine "X" disposes scope "S"
        $r->on('/^coroutine "([^"]+)" disposes scope "([^"]+)"$/',
            function(Context $ctx, string $caller, string $scope) {
                $ctx->planAction($caller, function(Context $ctx) use ($scope) {
                    $ctx->inc('scope_dispose_attempts');
                    if (!isset($ctx->scopes[$scope])) {
                        $ctx->inc('scope_dispose_target_missing');
                        return;
                    }
                    try {
                        $ctx->scopes[$scope]->dispose();
                        $ctx->inc("scope_disposed_$scope");
                    } catch (\Throwable $e) {
                        $ctx->inc('scope_dispose_threw');
                    }
                });
            });

        // When coroutine "X" disposes safely scope "S"
        $r->on('/^coroutine "([^"]+)" disposes safely scope "([^"]+)"$/',
            function(Context $ctx, string $caller, string $scope) {
                $ctx->planAction($caller, function(Context $ctx) use ($scope) {
                    $ctx->inc('scope_dispose_safely_attempts');
                    if (!isset($ctx->scopes[$scope])) {
                        $ctx->inc('scope_dispose_safely_target_missing');
                        return;
                    }
                    try {
                        $ctx->scopes[$scope]->disposeSafely();
                        $ctx->inc("scope_disposed_safely_$scope");
                    } catch (\Throwable $e) {
                        $ctx->inc('scope_dispose_safely_threw');
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

        // When coroutine "X" registers finally on coroutine "Y"
        // Increments counter "finally_called_Y" when finally fires —
        // must hold for every termination path: return / throw / cancel.
        $r->on('/^coroutine "([^"]+)" registers finally on coroutine "([^"]+)"$/',
            function(Context $ctx, string $caller, string $target) {
                $ctx->planAction($caller, function(Context $ctx) use ($target) {
                    $ctx->inc("finally_register_attempts_$target");
                    if (!isset($ctx->coroutineHandles[$target])) {
                        $ctx->inc("finally_register_target_missing_$target");
                        return;
                    }
                    /* Increment "registered" first: if the target has already
                     * finished, finally() may fire the callback inline and any
                     * throw from the callback would propagate out of finally()
                     * itself — we still want this to count as registered. */
                    $ctx->inc("finally_registered_$target");
                    try {
                        $ctx->coroutineHandles[$target]->finally(function() use ($ctx, $target) {
                            $ctx->inc("finally_called_$target");
                        });
                    } catch (\Throwable $e) {
                        $ctx->inc("finally_register_threw_$target");
                    }
                });
            });

        // When coroutine "X" registers throwing finally on coroutine "Y"
        // Finally handler that throws — original termination path is preserved
        // but the thrown exception surfaces via scope exception handler.
        $r->on('/^coroutine "([^"]+)" registers throwing finally on coroutine "([^"]+)"$/',
            function(Context $ctx, string $caller, string $target) {
                $ctx->planAction($caller, function(Context $ctx) use ($target) {
                    $ctx->inc("finally_register_attempts_$target");
                    if (!isset($ctx->coroutineHandles[$target])) {
                        $ctx->inc("finally_register_target_missing_$target");
                        return;
                    }
                    $ctx->inc("finally_registered_$target");
                    try {
                        $ctx->coroutineHandles[$target]->finally(function() use ($ctx, $target) {
                            $ctx->inc("finally_called_$target");
                            throw new \RuntimeException("throw from finally on $target");
                        });
                    } catch (\Throwable $e) {
                        $ctx->inc("finally_register_threw_$target");
                    }
                });
            });

        // ---- ThreadPool actions ----

        // When coroutine "X" submits N tasks to pool "P"
        // Each task returns its index. Futures are stored in
        // $ctx->threadPoolFutures[$pool] for a later "awaits all" step.
        $r->on('/^coroutine "([^"]+)" submits (\S+) tasks to pool "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $pool) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $pool) {
                    if (!isset($ctx->threadPools[$pool])) {
                        $ctx->inc("tp_submit_target_missing_$pool");
                        return;
                    }
                    $p = $ctx->threadPools[$pool];
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("tp_submit_attempts_$pool");
                        try {
                            $f = $p->submit(static fn(int $idx): int => $idx, $i);
                            $ctx->threadPoolFutures[$pool][] = $f;
                            $ctx->inc("tp_submitted_$pool");
                        } catch (\Throwable $e) {
                            $ctx->inc("tp_submit_failed_$pool");
                        }
                    }
                });
            });

        // When coroutine "X" awaits all submissions to pool "P"
        $r->on('/^coroutine "([^"]+)" awaits all submissions to pool "([^"]+)"$/',
            function(Context $ctx, string $coro, string $pool) {
                $ctx->planAction($coro, function(Context $ctx) use ($pool) {
                    $futures = $ctx->threadPoolFutures[$pool] ?? [];
                    if (!$futures) return;
                    $ctx->inc("tp_await_attempts_$pool");
                    try {
                        // fillNull=false: results contains only successful
                        // entries; errors contains the failed ones. Their
                        // sum equals the number of futures we actually
                        // awaited.
                        [$results, $errors] = \Async\await_all($futures, null, true, false);
                        $ctx->inc("tp_completed_$pool", count($results));
                        $ctx->inc("tp_failed_$pool", count($errors));
                        $ctx->inc("tp_await_succeeded_$pool");
                    } catch (\Throwable $e) {
                        $ctx->inc("tp_await_failed_$pool");
                    }
                });
            });

        // When coroutine "X" closes pool "P"
        $r->on('/^coroutine "([^"]+)" closes pool "([^"]+)"$/',
            function(Context $ctx, string $coro, string $pool) {
                $ctx->planAction($coro, function(Context $ctx) use ($pool) {
                    $ctx->inc("tp_close_attempts_$pool");
                    try {
                        $ctx->threadPools[$pool]->close();
                        $ctx->inc("tp_closed_$pool");
                    } catch (\Throwable $e) {
                        $ctx->inc("tp_close_failed_$pool");
                    }
                });
            });

        // When coroutine "X" cancels pool "P"
        $r->on('/^coroutine "([^"]+)" cancels pool "([^"]+)"$/',
            function(Context $ctx, string $coro, string $pool) {
                $ctx->planAction($coro, function(Context $ctx) use ($pool) {
                    $ctx->inc("tp_cancel_attempts_$pool");
                    try {
                        $ctx->threadPools[$pool]->cancel();
                        $ctx->inc("tp_cancelled_$pool");
                    } catch (\Throwable $e) {
                        $ctx->inc("tp_cancel_failed_$pool");
                    }
                });
            });

        // When coroutine "X" maps N items via pool "P"
        $r->on('/^coroutine "([^"]+)" maps (\S+) items via pool "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $pool) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $pool) {
                    $items = range(0, $n - 1);
                    $ctx->inc("tp_map_attempts_$pool");
                    try {
                        $res = $ctx->threadPools[$pool]->map($items, static fn(int $i): int => $i * $i);
                        $ctx->inc("tp_map_succeeded_$pool");
                        $ctx->inc("tp_map_results_$pool", count($res));
                    } catch (\Throwable $e) {
                        $ctx->inc("tp_map_failed_$pool");
                    }
                });
            });

        // ---- ThreadChannel actions ----

        // When coroutine "A" sends N messages to thread channel "X"
        $r->on('/^coroutine "([^"]+)" sends (\S+) messages to thread channel "([^"]+)"$/',
            function(Context $ctx, string $coro, string $countExpr, string $ch) {
                $n = (int)$ctx->resolver->resolve($countExpr);
                for ($i = 0; $i < $n; $i++) {
                    $value = $i;
                    $ctx->planAction($coro, function(Context $ctx) use ($ch, $value) {
                        $ctx->inc("tch_send_attempts_$ch");
                        try {
                            $ctx->threadChannels[$ch]->send($value);
                            $ctx->inc("tch_sent_$ch");
                        } catch (\Throwable $e) {
                            $ctx->inc("tch_send_failed_$ch");
                        }
                    });
                }
            });

        // When coroutine "B" receives N messages from thread channel "X"
        $r->on('/^coroutine "([^"]+)" receives (\S+) messages from thread channel "([^"]+)"$/',
            function(Context $ctx, string $coro, string $countExpr, string $ch) {
                $n = (int)$ctx->resolver->resolve($countExpr);
                for ($i = 0; $i < $n; $i++) {
                    $ctx->planAction($coro, function(Context $ctx) use ($ch) {
                        $ctx->inc("tch_recv_attempts_$ch");
                        try {
                            $ctx->threadChannels[$ch]->recv();
                            $ctx->inc("tch_received_$ch");
                        } catch (\Throwable $e) {
                            $ctx->inc("tch_recv_failed_$ch");
                        }
                    });
                }
            });

        // When coroutine "X" closes thread channel "X"
        $r->on('/^coroutine "([^"]+)" closes thread channel "([^"]+)"$/',
            function(Context $ctx, string $coro, string $ch) {
                $ctx->planAction($coro, function(Context $ctx) use ($ch) {
                    $ctx->threadChannels[$ch]->close();
                    $ctx->inc("tch_closed_$ch");
                });
            });

        // ---- TaskGroup actions ----

        // When coroutine "X" spawns N tasks into "G" that print "msg"
        // Each task increments tg_active_G on entry / -1 on exit, bumps
        // tg_max_active_G to track concurrency, and increments tg_done_G.
        $r->on('/^coroutine "([^"]+)" spawns (\S+) tasks into "([^"]+)" that print "([^"]*)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $g, string $msg) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $g, $msg) {
                    if (!isset($ctx->taskGroups[$g])) {
                        $ctx->inc("tg_spawn_target_missing_$g");
                        return;
                    }
                    $tg = $ctx->taskGroups[$g];
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("tg_spawn_attempts_$g");
                        try {
                            $tg->spawn(function() use ($ctx, $g, $msg) {
                                $ctx->inc("tg_active_$g");
                                $ctx->bumpMax("tg_max_active_$g", $ctx->counter("tg_active_$g"));
                                try {
                                    \Async\suspend();
                                    $ctx->events[] = $msg;
                                    $ctx->inc("tg_done_$g");
                                } finally {
                                    $ctx->inc("tg_active_$g", -1);
                                }
                            });
                            $ctx->inc("tg_spawned_$g");
                        } catch (\Throwable $e) {
                            $ctx->inc("tg_spawn_failed_$g");
                        }
                    }
                });
            });

        // When coroutine "X" awaits all of "G"
        $r->on('/^coroutine "([^"]+)" awaits all of "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_await_all_attempts_$g");
                    try {
                        $res = $ctx->taskGroups[$g]->all(true)->await();
                        $ctx->inc("tg_await_all_succeeded_$g");
                        $ctx->inc("tg_await_all_results_$g", count($res));
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_await_all_failed_$g");
                    }
                });
            });

        // When coroutine "X" awaits race of "G"
        $r->on('/^coroutine "([^"]+)" awaits race of "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_race_attempts_$g");
                    try {
                        $ctx->taskGroups[$g]->race()->await();
                        $ctx->inc("tg_race_succeeded_$g");
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_race_failed_$g");
                    }
                });
            });

        // When coroutine "X" awaits any of "G"
        $r->on('/^coroutine "([^"]+)" awaits any of "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_any_attempts_$g");
                    try {
                        $ctx->taskGroups[$g]->any()->await();
                        $ctx->inc("tg_any_succeeded_$g");
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_any_failed_$g");
                    }
                });
            });

        // When coroutine "X" cancels group "G"
        $r->on('/^coroutine "([^"]+)" cancels group "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_cancel_attempts_$g");
                    try {
                        $ctx->taskGroups[$g]->cancel();
                        $ctx->inc("tg_cancelled_$g");
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_cancel_failed_$g");
                    }
                });
            });

        // When coroutine "X" seals group "G"
        $r->on('/^coroutine "([^"]+)" seals group "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_seal_attempts_$g");
                    try {
                        $ctx->taskGroups[$g]->seal();
                        $ctx->inc("tg_sealed_$g");
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_seal_failed_$g");
                    }
                });
            });

        // When coroutine "X" awaits completion of "G"
        $r->on('/^coroutine "([^"]+)" awaits completion of "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_completion_attempts_$g");
                    try {
                        $ctx->taskGroups[$g]->awaitCompletion();
                        $ctx->inc("tg_completion_succeeded_$g");
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_completion_failed_$g");
                    }
                });
            });

        // When coroutine "X" disposes group "G"
        $r->on('/^coroutine "([^"]+)" disposes group "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_dispose_attempts_$g");
                    try {
                        $ctx->taskGroups[$g]->dispose();
                        $ctx->inc("tg_disposed_$g");
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_dispose_failed_$g");
                    }
                });
            });

        // When coroutine "X" recursively spawns to depth N
        // Each level spawns a child that recurses one fewer; counter
        // "rec_depth" increments once per coroutine, so for depth N the
        // counter ends at N+1 (initial coroutine + N descendants).
        $r->on('/^coroutine "([^"]+)" recursively spawns to depth (\S+)$/',
            function(Context $ctx, string $coro, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n) {
                    $rec = null;
                    $rec = function(int $depth) use (&$rec, $ctx) {
                        $ctx->inc('rec_depth');
                        if ($depth > 0) {
                            $h = \Async\spawn($rec, $depth - 1);
                            \Async\await($h);
                        }
                    };
                    $rec($n);
                });
            });

        // When coroutine "X" maps future "F" to counter "K"
        $r->on('/^coroutine "([^"]+)" maps future "([^"]+)" to counter "([^"]+)"$/',
            function(Context $ctx, string $coro, string $f, string $key) {
                $ctx->planAction($coro, function(Context $ctx) use ($f, $key) {
                    $mapped = $ctx->futures[$f]->map(function($v) use ($ctx, $key) {
                        $ctx->inc("map_$key");
                        return $v;
                    });
                    try {
                        $mapped->await();
                        $ctx->inc("map_awaited_$key");
                    } catch (\Throwable $e) {
                        $ctx->inc("map_failed_$key");
                    }
                });
            });

        // When coroutine "X" catches future "F" to counter "K"
        $r->on('/^coroutine "([^"]+)" catches future "([^"]+)" to counter "([^"]+)"$/',
            function(Context $ctx, string $coro, string $f, string $key) {
                $ctx->planAction($coro, function(Context $ctx) use ($f, $key) {
                    $chained = $ctx->futures[$f]->catch(function(\Throwable $e) use ($ctx, $key) {
                        $ctx->inc("catch_$key");
                        return null;
                    });
                    try {
                        $chained->await();
                        $ctx->inc("catch_awaited_$key");
                    } catch (\Throwable $e) {
                        $ctx->inc("catch_failed_$key");
                    }
                });
            });

        // When coroutine "X" finallies future "F" to counter "K"
        $r->on('/^coroutine "([^"]+)" finallies future "([^"]+)" to counter "([^"]+)"$/',
            function(Context $ctx, string $coro, string $f, string $key) {
                $ctx->planAction($coro, function(Context $ctx) use ($f, $key) {
                    $chained = $ctx->futures[$f]->finally(function() use ($ctx, $key) {
                        $ctx->inc("finally_$key");
                    });
                    try {
                        $chained->await();
                        $ctx->inc("finally_awaited_$key");
                    } catch (\Throwable $e) {
                        $ctx->inc("finally_failed_$key");
                    }
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

        // Then group "G" is finished
        $r->on('/^group "([^"]+)" is finished$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->taskGroups[$name]) || !$ctx->taskGroups[$name]->isFinished()) {
                    throw new \RuntimeException("group $name expected to be finished");
                }
            });

        // Then group "G" is sealed
        $r->on('/^group "([^"]+)" is sealed$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->taskGroups[$name]) || !$ctx->taskGroups[$name]->isSealed()) {
                    throw new \RuntimeException("group $name expected to be sealed");
                }
            });

        // Then group "G" count equals N
        $r->on('/^group "([^"]+)" count equals (\d+)$/',
            function(Context $ctx, string $name, string $expected) {
                $c = $ctx->taskGroups[$name]->count();
                if ($c !== (int)$expected) {
                    throw new \RuntimeException("group $name count = $c, expected $expected");
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
