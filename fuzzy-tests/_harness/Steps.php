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

        // Given a channel "ch" with capacity N owned by scope "S"
        // The channel is constructed inside scope S's creator coroutine, so the
        // runtime tags S as the owner. When S is disposed, the channel closes
        // with reason SCOPE_DISPOSED — every blocked send/recv unblocks with
        // ChannelException.
        $r->on('/^a channel "([^"]+)" with capacity (\S+) owned by scope "([^"]+)"$/',
            function(Context $ctx, string $name, string $capExpr, string $scope) {
                $cap = (int)$ctx->resolver->resolve($capExpr);
                $ctx->defineChannel($name, $cap, 0, 0, false, $scope);
            });

        // Given a channel "ch" with capacity N and deadlock timeout T ms
        // Sets both producer and consumer timeouts to T (channel closes with
        // reason DEADLOCK if no progress within T ms while a side is blocked).
        $r->on('/^a channel "([^"]+)" with capacity (\S+) and deadlock timeout (\S+) ms$/',
            function(Context $ctx, string $name, string $capExpr, string $tExpr) {
                $cap = (int)$ctx->resolver->resolve($capExpr);
                $t   = (int)$ctx->resolver->resolve($tExpr);
                $ctx->defineChannel($name, $cap, $t, $t);
            });

        // Given a coroutine "A"
        $r->on('/^a coroutine "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->defineCoroutine($name);
            });

        // Given a non-awaited coroutine "A"
        // Spawned like a regular coroutine but NOT placed in run()'s
        // await_all list. Used to test runtime cleanup of coroutines still
        // pending at request end — the harness fires a cancel sweep over
        // every nonAwaited coroutine right after await_all, simulating the
        // shutdown phase.
        $r->on('/^a non-awaited coroutine "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->defineCoroutine($name);
                $ctx->nonAwaited[$name] = true;
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

        // Given scope "S" seeded with context "key" = "value"
        // The pair is written into S's context in run()'s prep-phase, before
        // any user coroutine runs — inherited-scope coroutines see it without
        // racing the writer.
        $r->on('/^scope "([^"]+)" seeded with context "([^"]+)" = "([^"]*)"$/',
            function(Context $ctx, string $scope, string $key, string $value) {
                $ctx->defineContextSeed($scope, $key, $value);
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
            })
            ->requires('zts');

        // Given a thread pool "P" with N workers and queue size Q
        $r->on('/^a thread pool "([^"]+)" with (\S+) workers and queue size (\S+)$/',
            function(Context $ctx, string $name, string $wExpr, string $qExpr) {
                $w = (int)$ctx->resolver->resolve($wExpr);
                $q = (int)$ctx->resolver->resolve($qExpr);
                $ctx->defineThreadPool($name, $w, $q);
            })
            ->requires('zts');

        // Given a thread channel "X" with capacity N
        $r->on('/^a thread channel "([^"]+)" with capacity (\S+)$/',
            function(Context $ctx, string $name, string $capExpr) {
                $cap = (int)$ctx->resolver->resolve($capExpr);
                $ctx->defineThreadChannel($name, $cap);
            })
            ->requires('zts');

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

        // Given a task set "T"
        $r->on('/^a task set "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->defineTaskSet($name);
            });

        // Given a task set "T" with concurrency N
        $r->on('/^a task set "([^"]+)" with concurrency (\S+)$/',
            function(Context $ctx, string $name, string $cExpr) {
                $ctx->defineTaskSet($name, (int)$ctx->resolver->resolve($cExpr));
            });

        // Given a pool "P"
        $r->on('/^a pool "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->definePool($name);
            });

        // Given a pool "P" with min N and max M
        $r->on('/^a pool "([^"]+)" with min (\S+) and max (\S+)$/',
            function(Context $ctx, string $name, string $minExpr, string $maxExpr) {
                $ctx->definePool($name,
                    (int)$ctx->resolver->resolve($minExpr),
                    (int)$ctx->resolver->resolve($maxExpr));
            });

        // Given a pool "P" that rejects release
        // beforeRelease returns false, so every release destroys the resource
        // and — with a strategy attached — drives reportFailure.
        $r->on('/^a pool "([^"]+)" that rejects release$/',
            function(Context $ctx, string $name) {
                $ctx->definePool($name, 1, 10, true);
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

        // When coroutine "X" tries to send N messages to "ch" without blocking
        // Uses Channel::sendAsync(): true on success, false on full-or-closed.
        // Counters: try_send_attempts_$ch / try_send_ok_$ch / try_send_full_$ch.
        $r->on('/^coroutine "([^"]+)" tries to send (\S+) messages to "([^"]+)" without blocking$/',
            function(Context $ctx, string $coro, string $countExpr, string $ch) {
                $n = (int)$ctx->resolver->resolve($countExpr);
                for ($i = 0; $i < $n; $i++) {
                    $value = $i;
                    $ctx->planAction($coro, function(Context $ctx) use ($ch, $value) {
                        $ctx->inc("try_send_attempts_$ch");
                        if ($ctx->channels[$ch]->sendAsync($value)) {
                            $ctx->inc("try_send_ok_$ch");
                        } else {
                            $ctx->inc("try_send_full_$ch");
                        }
                    });
                }
            });

        // When coroutine "X" awaits recvAsync N times from "ch"
        // Each call returns a Future; we await it and bump async_received or
        // async_recv_failed depending on whether the await throws.
        $r->on('/^coroutine "([^"]+)" awaits recvAsync (\S+) times from "([^"]+)"$/',
            function(Context $ctx, string $coro, string $countExpr, string $ch) {
                $n = (int)$ctx->resolver->resolve($countExpr);
                for ($i = 0; $i < $n; $i++) {
                    $ctx->planAction($coro, function(Context $ctx) use ($ch) {
                        $ctx->inc("async_recv_attempts_$ch");
                        try {
                            $ctx->channels[$ch]->recvAsync()->await();
                            $ctx->inc("async_received_$ch");
                        } catch (\Throwable $e) {
                            $ctx->inc("async_recv_failed_$ch");
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

        // When coroutine "X" inspects locations of future "F"
        // Samples the created/completed location accessors on BOTH the Future
        // and its FutureState. getCreated* is fixed at construction — always a
        // [file,int] pair / "file:line" string. getCompleted* must be well
        // typed at every instant (a 2-element array / string) even before the
        // future settles. Buckets ok/bad so the sum invariant holds for any
        // interleaving relative to the producer.
        $r->on('/^coroutine "([^"]+)" inspects locations of future "([^"]+)"$/',
            function(Context $ctx, string $coro, string $f) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $f) {
                    $ctx->inc("fut_loc_attempts_$f");
                    if (!isset($ctx->futures[$f]) || !isset($ctx->futureStates[$f])) {
                        $ctx->inc("fut_loc_target_missing_$f");
                        return;
                    }
                    $wellFormedPair = static function($fl): bool {
                        return is_array($fl) && count($fl) === 2
                            && (is_string($fl[0]) || $fl[0] === null)
                            && is_int($fl[1]);
                    };
                    $ok = true;
                    foreach ([$ctx->futures[$f], $ctx->futureStates[$f]] as $obj) {
                        // Created location is fixed — must be fully well-formed.
                        $ok = $ok && $wellFormedPair($obj->getCreatedFileAndLine());
                        $cl = $obj->getCreatedLocation();
                        $ok = $ok && is_string($cl) && strpos($cl, ':') !== false;
                        // Completed location may be unset — only require it be
                        // well typed (2-element array / string).
                        $ok = $ok && $wellFormedPair($obj->getCompletedFileAndLine());
                        $ok = $ok && is_string($obj->getCompletedLocation());
                    }
                    $ctx->inc($ok ? "fut_loc_ok_$f" : "fut_loc_bad_$f");
                });
            });

        // Then future "F" has well-formed created and completed locations
        // After run() the future has settled, so getCompleted* on both the
        // Future and the FutureState must be a [file,int] pair / "file:line".
        $r->on('/^future "([^"]+)" has well-formed created and completed locations$/',
            function(Context $ctx, string $f) {
                if (!isset($ctx->futures[$f]) || !isset($ctx->futureStates[$f])) {
                    throw new \RuntimeException("future $f not defined");
                }
                foreach (['Future' => $ctx->futures[$f],
                          'FutureState' => $ctx->futureStates[$f]] as $label => $obj) {
                    foreach (['Created' => 'getCreated', 'Completed' => 'getCompleted'] as $kind => $prefix) {
                        $fl = $obj->{$prefix . 'FileAndLine'}();
                        if (!is_array($fl) || count($fl) !== 2
                            || !(is_string($fl[0]) || $fl[0] === null) || !is_int($fl[1])) {
                            throw new \RuntimeException("$label $f malformed {$prefix}FileAndLine()");
                        }
                        $loc = $obj->{$prefix . 'Location'}();
                        if (!is_string($loc) || strpos($loc, ':') === false) {
                            throw new \RuntimeException(
                                "$label $f malformed {$prefix}Location(): " . var_export($loc, true));
                        }
                    }
                }
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

        // When coroutine "X" awaits future "F" with cancellation future "FC"
        // Either F completes first (await returns / throws based on F) or FC
        // fires first and the await aborts. Counters: await_attempts_F always
        // increments; exactly one of awaited_F / await_cancelled_F /
        // await_failed_F increments per attempt.
        $r->on('/^coroutine "([^"]+)" awaits future "([^"]+)" with cancellation future "([^"]+)"$/',
            function(Context $ctx, string $coro, string $f, string $cancelName) {
                $ctx->planAction($coro, function(Context $ctx) use ($f, $cancelName) {
                    $ctx->inc("await_attempts_$f");
                    $cancellation = $ctx->futures[$cancelName] ?? null;
                    try {
                        $ctx->futures[$f]->await($cancellation);
                        $ctx->inc("awaited_$f");
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("await_cancelled_$f");
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

        // ---- I/O actions (network / pipes) ----

        // When coroutine "X" listens for one connection on a fresh TCP socket
        // Spawns its own loopback server with an ephemeral port, blocks in
        // stream_socket_accept(). Counters: io_accept_attempts_$coro /
        // io_accept_ok_$coro / io_accept_cancelled_$coro / io_accept_failed_$coro.
        $r->on('/^coroutine "([^"]+)" listens for one connection on a fresh socket$/',
            function(Context $ctx, string $coro) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro) {
                    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
                    if (!$server) {
                        $ctx->inc("io_accept_setup_failed_$coro");
                        return;
                    }
                    stream_set_blocking($server, false);
                    /* Bump attempts inside the try so any post-bump outcome
                     * lands in exactly one bucket (cancelled / failed / ok /
                     * timeout). Pre-try cancellation skips both. */
                    try {
                        $ctx->inc("io_accept_attempts_$coro");
                        $client = @stream_socket_accept($server, 30);
                        if ($client) {
                            $ctx->inc("io_accept_ok_$coro");
                            fclose($client);
                        } else {
                            $ctx->inc("io_accept_timeout_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("io_accept_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("io_accept_failed_$coro");
                    } finally {
                        @fclose($server);
                    }
                });
            })
            ->requires('tcp');

        // When coroutine "X" reads from a fresh pipe
        // Creates a stream_socket_pair (kept alive locally), blocks on fread()
        // for the read end. Counters mirror accept: io_read_attempts_$coro /
        // io_read_ok_$coro / io_read_cancelled_$coro / io_read_failed_$coro.
        $r->on('/^coroutine "([^"]+)" reads from a fresh pipe$/',
            function(Context $ctx, string $coro) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro) {
                    $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                    if ($pair === false) {
                        $ctx->inc("io_read_setup_failed_$coro");
                        return;
                    }
                    [$reader, $writer] = $pair;
                    try {
                        $ctx->inc("io_read_attempts_$coro");
                        $data = @fread($reader, 4096); /* blocks */
                        if ($data === false || $data === '') {
                            $ctx->inc("io_read_eof_$coro");
                        } else {
                            $ctx->inc("io_read_ok_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("io_read_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("io_read_failed_$coro");
                    } finally {
                        @fclose($reader);
                        @fclose($writer);
                    }
                });
            })
            ->requires('unix-sockets');

        // When coroutine "X" inspects state of coroutine "Y"
        // Calls every is*() predicate on Y at the moment of the call. Each call
        // bumps a per-state counter; the union covers all observable states.
        // Under random scheduling each call lands on exactly one of the
        // mutually-exclusive states {running, suspended, completed, cancelled,
        // not-yet-started}.
        $r->on('/^coroutine "([^"]+)" inspects state of coroutine "([^"]+)"$/',
            function(Context $ctx, string $caller, string $target) {
                $ctx->planAction($caller, function(Context $ctx) use ($target) {
                    $ctx->inc("state_inspect_attempts_$target");
                    if (!isset($ctx->coroutineHandles[$target])) {
                        $ctx->inc("state_inspect_target_missing_$target");
                        return;
                    }
                    $h = $ctx->coroutineHandles[$target];
                    if ($h->isStarted())                 $ctx->inc("state_started_$target");
                    if ($h->isRunning())                 $ctx->inc("state_running_$target");
                    if ($h->isSuspended())               $ctx->inc("state_suspended_$target");
                    if ($h->isCompleted())               $ctx->inc("state_completed_$target");
                    if ($h->isCancelled())               $ctx->inc("state_cancelled_$target");
                    if ($h->isCancellationRequested())   $ctx->inc("state_cancel_requested_$target");
                });
            });

        // When coroutine "X" inspects trace of coroutine "Y"
        // Records whether Y was suspended (trace is array) or done/not-yet-running
        // (trace is null) at the moment of the call. Under random scheduling
        // both outcomes can occur; the sum invariant lets tests assert without
        // depending on one specific interleaving.
        $r->on('/^coroutine "([^"]+)" inspects trace of coroutine "([^"]+)"$/',
            function(Context $ctx, string $caller, string $target) {
                $ctx->planAction($caller, function(Context $ctx) use ($target) {
                    $ctx->inc("trace_inspect_attempts_$target");
                    if (!isset($ctx->coroutineHandles[$target])) {
                        $ctx->inc("trace_inspect_target_missing_$target");
                        return;
                    }
                    $t = $ctx->coroutineHandles[$target]->getTrace();
                    if (is_array($t)) {
                        $ctx->inc("trace_was_array_$target");
                    } elseif ($t === null) {
                        $ctx->inc("trace_was_null_$target");
                    } else {
                        $ctx->inc("trace_was_other_$target");
                    }
                });
            });

        // When coroutine "X" inspects spawn location of coroutine "Y"
        // Spawn location is fixed at creation: getSpawnFileAndLine() must be a
        // 2-element [file, line] array and getSpawnLocation() a "file:line"
        // string, for every interleaving and every lifecycle phase of Y.
        $r->on('/^coroutine "([^"]+)" inspects spawn location of coroutine "([^"]+)"$/',
            function(Context $ctx, string $caller, string $target) {
                $ctx->planAction($caller, function(Context $ctx) use ($target) {
                    $ctx->inc("spawn_loc_attempts_$target");
                    if (!isset($ctx->coroutineHandles[$target])) {
                        $ctx->inc("spawn_loc_target_missing_$target");
                        return;
                    }
                    $h  = $ctx->coroutineHandles[$target];
                    $fl = $h->getSpawnFileAndLine();
                    $loc = $h->getSpawnLocation();
                    $ok = is_array($fl) && count($fl) === 2
                        && (is_string($fl[0]) || $fl[0] === null)
                        && is_int($fl[1])
                        && is_string($loc) && strpos($loc, ':') !== false;
                    $ctx->inc($ok ? "spawn_loc_ok_$target" : "spawn_loc_bad_$target");
                });
            });

        // When coroutine "X" inspects suspend location of coroutine "Y"
        // getSuspendFileAndLine() is always a 2-element array and
        // getSuspendLocation() always a string — even before Y ever suspends.
        $r->on('/^coroutine "([^"]+)" inspects suspend location of coroutine "([^"]+)"$/',
            function(Context $ctx, string $caller, string $target) {
                $ctx->planAction($caller, function(Context $ctx) use ($target) {
                    $ctx->inc("suspend_loc_attempts_$target");
                    if (!isset($ctx->coroutineHandles[$target])) {
                        $ctx->inc("suspend_loc_target_missing_$target");
                        return;
                    }
                    $h  = $ctx->coroutineHandles[$target];
                    $fl = $h->getSuspendFileAndLine();
                    $loc = $h->getSuspendLocation();
                    $ok = is_array($fl) && count($fl) === 2 && is_string($loc);
                    $ctx->inc($ok ? "suspend_loc_ok_$target" : "suspend_loc_bad_$target");
                });
            });

        // When coroutine "X" inspects awaiting info of coroutine "Y"
        // getAwaitingInfo() returns an array for every observable state.
        $r->on('/^coroutine "([^"]+)" inspects awaiting info of coroutine "([^"]+)"$/',
            function(Context $ctx, string $caller, string $target) {
                $ctx->planAction($caller, function(Context $ctx) use ($target) {
                    $ctx->inc("await_info_attempts_$target");
                    if (!isset($ctx->coroutineHandles[$target])) {
                        $ctx->inc("await_info_target_missing_$target");
                        return;
                    }
                    $info = $ctx->coroutineHandles[$target]->getAwaitingInfo();
                    $ctx->inc(is_array($info) ? "await_info_array_$target" : "await_info_bad_$target");
                });
            });

        // When coroutine "X" inspects queued state of coroutine "Y"
        // isQueued() is a strict bool sampled at the call instant — under
        // random scheduling both buckets are reachable; never a non-bool.
        $r->on('/^coroutine "([^"]+)" inspects queued state of coroutine "([^"]+)"$/',
            function(Context $ctx, string $caller, string $target) {
                $ctx->planAction($caller, function(Context $ctx) use ($target) {
                    $ctx->inc("queued_attempts_$target");
                    if (!isset($ctx->coroutineHandles[$target])) {
                        $ctx->inc("queued_target_missing_$target");
                        return;
                    }
                    $q = $ctx->coroutineHandles[$target]->isQueued();
                    if ($q === true)       $ctx->inc("queued_true_$target");
                    elseif ($q === false)  $ctx->inc("queued_false_$target");
                    else                   $ctx->inc("queued_bad_$target");
                });
            });

        // When coroutine "X" inspects context of coroutine "Y"
        // getContext() yields an Async\Context (or null) — never a malformed
        // value, regardless of where Y is in its lifecycle.
        $r->on('/^coroutine "([^"]+)" inspects context of coroutine "([^"]+)"$/',
            function(Context $ctx, string $caller, string $target) {
                $ctx->planAction($caller, function(Context $ctx) use ($target) {
                    $ctx->inc("ctx_attempts_$target");
                    if (!isset($ctx->coroutineHandles[$target])) {
                        $ctx->inc("ctx_target_missing_$target");
                        return;
                    }
                    $c = $ctx->coroutineHandles[$target]->getContext();
                    if ($c instanceof \Async\Context) $ctx->inc("ctx_ok_$target");
                    elseif ($c === null)              $ctx->inc("ctx_null_$target");
                    else                              $ctx->inc("ctx_bad_$target");
                });
            });

        // When coroutine "X" raises priority of coroutine "Y"
        // asHiPriority() marks Y high priority and must return the very same
        // Coroutine handle — identity holds for every interleaving.
        $r->on('/^coroutine "([^"]+)" raises priority of coroutine "([^"]+)"$/',
            function(Context $ctx, string $caller, string $target) {
                $ctx->planAction($caller, function(Context $ctx) use ($target) {
                    $ctx->inc("hipri_attempts_$target");
                    if (!isset($ctx->coroutineHandles[$target])) {
                        $ctx->inc("hipri_target_missing_$target");
                        return;
                    }
                    $h = $ctx->coroutineHandles[$target];
                    $r = $h->asHiPriority();
                    $ctx->inc($r === $h ? "hipri_identity_ok_$target" : "hipri_identity_bad_$target");
                });
            });

        // When coroutine "X" sets coroutine-context "key" to "value"
        // Writes into the per-coroutine context (coroutine_context()), which is
        // isolated from every sibling — used to test cross-coroutine isolation.
        $r->on('/^coroutine "([^"]+)" sets coroutine-context "([^"]+)" to "([^"]*)"$/',
            function(Context $ctx, string $coro, string $key, string $value) {
                $ctx->planAction($coro, function(Context $ctx) use ($key, $value) {
                    \Async\coroutine_context()->set($key, $value, true);
                });
            });

        // When coroutine "X" verifies coroutine-context "key" is "value"
        // Suspends once (yielding to siblings that may be mutating their own
        // contexts) then reads the key back via get/getLocal/has/hasLocal.
        // Isolation invariant: the value is always X's own, for any interleaving.
        $r->on('/^coroutine "([^"]+)" verifies coroutine-context "([^"]+)" is "([^"]*)"$/',
            function(Context $ctx, string $coro, string $key, string $value) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $key, $value) {
                    \Async\suspend();
                    $cc = \Async\coroutine_context();
                    $ctx->inc("iso_attempts_$coro");
                    $ok = $cc->get($key) === $value
                        && $cc->getLocal($key) === $value
                        && $cc->has($key) === true
                        && $cc->hasLocal($key) === true;
                    $ctx->inc($ok ? "iso_ok_$coro" : "iso_bad_$coro");
                });
            });

        // When coroutine "X" reads inherited context "key" expecting "value"
        // X lives in a scope inheriting a seeded parent. find()/get()/has()
        // must walk up and see the parent value; the *Local() variants must
        // NOT — the seed lives in the parent layer, not X's local layer.
        $r->on('/^coroutine "([^"]+)" reads inherited context "([^"]+)" expecting "([^"]*)"$/',
            function(Context $ctx, string $coro, string $key, string $value) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $key, $value) {
                    \Async\suspend();
                    $cc = \Async\current_context();
                    $ctx->inc("inherit_attempts_$coro");
                    $inheritOk = $cc->find($key) === $value
                        && $cc->get($key) === $value
                        && $cc->has($key) === true;
                    $ctx->inc($inheritOk ? "inherit_hit_$coro" : "inherit_miss_$coro");
                    $localAbsent = $cc->findLocal($key) === null
                        && $cc->getLocal($key) === null
                        && $cc->hasLocal($key) === false;
                    $ctx->inc($localAbsent ? "local_absent_$coro" : "local_present_$coro");
                });
            });

        // When coroutine "X" overrides context "key" with local "value"
        // X is in an inheriting scope; a local set() shadows the parent seed.
        // After the override both inherited and local reads must yield "value".
        $r->on('/^coroutine "([^"]+)" overrides context "([^"]+)" with local "([^"]*)"$/',
            function(Context $ctx, string $coro, string $key, string $value) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $key, $value) {
                    $cc = \Async\current_context();
                    $ctx->inc("override_attempts_$coro");
                    $cc->set($key, $value);
                    \Async\suspend();
                    $ok = $cc->getLocal($key) === $value
                        && $cc->findLocal($key) === $value
                        && $cc->hasLocal($key) === true
                        && $cc->get($key) === $value
                        && $cc->find($key) === $value;
                    $ctx->inc($ok ? "override_ok_$coro" : "override_bad_$coro");
                });
            });

        // When coroutine "X" exercises context replace and unset on "key"
        // Single-coroutine CRUD over coroutine_context(): set, the replace=false
        // collision (must throw AsyncException), replace=true, then unset.
        $r->on('/^coroutine "([^"]+)" exercises context replace and unset on "([^"]+)"$/',
            function(Context $ctx, string $coro, string $key) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $key) {
                    $ctx->inc("crud_attempts_$coro");
                    $cc = \Async\coroutine_context();
                    $pass = true;
                    try {
                        $cc->set($key, 'v1');
                        if ($cc->get($key) !== 'v1') $pass = false;
                        \Async\suspend();
                        $threw = false;
                        try { $cc->set($key, 'v2'); }
                        catch (\Async\AsyncException $e) { $threw = true; }
                        if (!$threw) $pass = false;
                        if ($cc->get($key) !== 'v1') $pass = false;   // unchanged
                        $cc->set($key, 'v2', true);                    // replace
                        if ($cc->get($key) !== 'v2') $pass = false;
                        \Async\suspend();
                        $cc->unset($key);
                        if ($cc->has($key) !== false) $pass = false;
                        if ($cc->hasLocal($key) !== false) $pass = false;
                        if ($cc->get($key) !== null) $pass = false;
                    } catch (\Throwable $e) {
                        $pass = false;
                    }
                    $ctx->inc($pass ? "crud_ok_$coro" : "crud_bad_$coro");
                });
            });

        // When coroutine "X" writes shared context "key" value "value"
        // X writes a UNIQUE key into the shared scope context, suspends so
        // siblings interleave their own writes into the same HashTable, then
        // reads its own key back. Distinct keys => set() never collides.
        $r->on('/^coroutine "([^"]+)" writes shared context "([^"]+)" value "([^"]*)"$/',
            function(Context $ctx, string $coro, string $key, string $value) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $key, $value) {
                    $cc = \Async\current_context();
                    $ctx->inc("shared_attempts_$coro");
                    $cc->set($key, $value, true);
                    \Async\suspend();
                    \Async\suspend();
                    $ok = $cc->get($key) === $value
                        && $cc->find($key) === $value
                        && $cc->has($key) === true;
                    $ctx->inc($ok ? "shared_ok_$coro" : "shared_bad_$coro");
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

        // When coroutine "X" inspects counters of pool "P"
        // Samples getPendingCount/getRunningCount/getCompletedCount/
        // getWorkerCount. Each must be a non-negative int. The sampled values
        // are recorded into tp_seen_* counters (the step runs once) so the
        // feature can assert the drained snapshot after awaiting all work.
        $r->on('/^coroutine "([^"]+)" inspects counters of pool "([^"]+)"$/',
            function(Context $ctx, string $coro, string $pool) {
                $ctx->planAction($coro, function(Context $ctx) use ($pool) {
                    $ctx->inc("tp_counters_attempts_$pool");
                    if (!isset($ctx->threadPools[$pool])) {
                        $ctx->inc("tp_counters_target_missing_$pool");
                        return;
                    }
                    $p = $ctx->threadPools[$pool];
                    $pending   = $p->getPendingCount();
                    $running   = $p->getRunningCount();
                    $completed = $p->getCompletedCount();
                    $workers   = $p->getWorkerCount();
                    $ok = is_int($pending) && $pending >= 0
                        && is_int($running) && $running >= 0
                        && is_int($completed) && $completed >= 0
                        && is_int($workers) && $workers >= 0;
                    $ctx->inc($ok ? "tp_counters_ok_$pool" : "tp_counters_bad_$pool");
                    if ($ok) {
                        $ctx->inc("tp_seen_pending_$pool", $pending);
                        $ctx->inc("tp_seen_running_$pool", $running);
                        $ctx->inc("tp_seen_completed_$pool", $completed);
                        $ctx->inc("tp_seen_workers_$pool", $workers);
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

        // ---- spawn_thread actions ----
        //
        // These exercise the OS-thread result/exception handoff: a worker
        // thread transfers its result into the thread event at the end of
        // async_thread_run. Under the chaos scheduler the awaiting coroutine
        // and the request teardown race the worker, so handles that are left
        // un-awaited drive the "parent detached" branch of the handoff.

        // When coroutine "X" spawns N threads returning their index
        $r->on('/^coroutine "([^"]+)" spawns (\S+) threads returning their index$/',
            function(Context $ctx, string $coro, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $coro) {
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("thr_spawn_attempts_$coro");
                        try {
                            $h = \Async\spawn_thread(static function() use ($i): array {
                                $x = 0.0;
                                for ($j = 0; $j < 20000; $j++) { $x += sqrt($j); }
                                return ['idx' => $i, 'x' => $x];
                            });
                            $ctx->threadHandles[$coro][] = $h;
                            $ctx->inc("thr_spawned_$coro");
                        } catch (\Throwable $e) {
                            $ctx->inc("thr_spawn_failed_$coro");
                        }
                    }
                });
            })
            ->requires('zts');

        // When coroutine "X" spawns N threads that throw
        $r->on('/^coroutine "([^"]+)" spawns (\S+) threads that throw$/',
            function(Context $ctx, string $coro, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $coro) {
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("thr_spawn_attempts_$coro");
                        try {
                            $h = \Async\spawn_thread(static function() use ($i): void {
                                $x = 0.0;
                                for ($j = 0; $j < 20000; $j++) { $x += sqrt($j); }
                                throw new \RuntimeException('thread boom ' . $i);
                            });
                            $ctx->threadHandles[$coro][] = $h;
                            $ctx->inc("thr_spawned_$coro");
                        } catch (\Throwable $e) {
                            $ctx->inc("thr_spawn_failed_$coro");
                        }
                    }
                });
            })
            ->requires('zts');

        // When coroutine "X" spawns N detached threads
        // Handles are intentionally dropped: the workers are still inside
        // async_thread_run when the harness tears down -> the worker hits the
        // "parent detached" handoff branch and must release its own result.
        $r->on('/^coroutine "([^"]+)" spawns (\S+) detached threads$/',
            function(Context $ctx, string $coro, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $coro) {
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("thr_spawn_attempts_$coro");
                        try {
                            \Async\spawn_thread(static function() use ($i): array {
                                $x = 0.0;
                                for ($j = 0; $j < 40000; $j++) { $x += sqrt($j); }
                                return ['idx' => $i, 'x' => $x, 'buf' => str_repeat('w', 64)];
                            });
                            $ctx->inc("thr_spawned_$coro");
                        } catch (\Throwable $e) {
                            $ctx->inc("thr_spawn_failed_$coro");
                        }
                    }
                });
            })
            ->requires('zts');

        // When coroutine "X" awaits all threads
        $r->on('/^coroutine "([^"]+)" awaits all threads$/',
            function(Context $ctx, string $coro) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro) {
                    $handles = $ctx->threadHandles[$coro] ?? [];
                    foreach ($handles as $h) {
                        try {
                            \Async\await($h);
                            $ctx->inc("thr_completed_$coro");
                        } catch (\Throwable $e) {
                            $ctx->inc("thr_failed_$coro");
                        }
                    }
                });
            })
            ->requires('zts');

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

        // When coroutine "X" spawns N keyed tasks into "G"
        // Uses spawnWithKey() with explicit keys "k0".."k(N-1)"; each task
        // suspends then returns a distinct value "r<i>" so getResults() /
        // the iterator can be checked against the keys.
        $r->on('/^coroutine "([^"]+)" spawns (\S+) keyed tasks into "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $g) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $g) {
                    if (!isset($ctx->taskGroups[$g])) {
                        $ctx->inc("tg_spawn_target_missing_$g");
                        return;
                    }
                    $tg = $ctx->taskGroups[$g];
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("tg_kspawn_attempts_$g");
                        try {
                            $tg->spawnWithKey("k$i", function() use ($ctx, $g, $i) {
                                \Async\suspend();
                                $ctx->inc("tg_kdone_$g");
                                return "r$i";
                            });
                            $ctx->inc("tg_kspawned_$g");
                        } catch (\Throwable $e) {
                            $ctx->inc("tg_kspawn_failed_$g");
                        }
                    }
                });
            });

        // When coroutine "X" spawns N failing tasks into "G"
        // Each task suspends then throws — used to drive getErrors() /
        // suppressErrors() and the error branch of the iterator.
        $r->on('/^coroutine "([^"]+)" spawns (\S+) failing tasks into "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $g) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $g) {
                    if (!isset($ctx->taskGroups[$g])) {
                        $ctx->inc("tg_spawn_target_missing_$g");
                        return;
                    }
                    $tg = $ctx->taskGroups[$g];
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("tg_fspawn_attempts_$g");
                        try {
                            $tg->spawn(function() use ($ctx, $g) {
                                \Async\suspend();
                                $ctx->inc("tg_fran_$g");
                                throw new \RuntimeException("task boom");
                            });
                            $ctx->inc("tg_fspawned_$g");
                        } catch (\Throwable $e) {
                            $ctx->inc("tg_fspawn_failed_$g");
                        }
                    }
                });
            });

        // When coroutine "X" spawns a duplicate-key task into "G"
        // spawnWithKey() with a key already present must throw AsyncException;
        // the first spawn succeeds, the second is rejected.
        $r->on('/^coroutine "([^"]+)" spawns a duplicate-key task into "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    if (!isset($ctx->taskGroups[$g])) {
                        $ctx->inc("tg_spawn_target_missing_$g");
                        return;
                    }
                    $tg = $ctx->taskGroups[$g];
                    $task = function() { \Async\suspend(); return "dup"; };
                    try {
                        $tg->spawnWithKey("dup", $task);
                        $ctx->inc("tg_dupkey_first_ok_$g");
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_dupkey_first_failed_$g");
                    }
                    try {
                        $tg->spawnWithKey("dup", $task);
                        $ctx->inc("tg_dupkey_second_ok_$g");
                    } catch (\Async\AsyncException $e) {
                        $ctx->inc("tg_dupkey_threw_$g");
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_dupkey_other_throw_$g");
                    }
                });
            });

        // When coroutine "X" reads results of "G"
        // getResults() returns successful task results keyed by task key.
        $r->on('/^coroutine "([^"]+)" reads results of "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_get_results_attempts_$g");
                    $res = $ctx->taskGroups[$g]->getResults();
                    if (is_array($res)) {
                        $ctx->inc("tg_results_count_$g", count($res));
                    } else {
                        $ctx->inc("tg_results_bad_$g");
                    }
                });
            });

        // When coroutine "X" reads errors of "G"
        // getErrors() returns Throwables keyed by task key and marks them handled.
        $r->on('/^coroutine "([^"]+)" reads errors of "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_get_errors_attempts_$g");
                    $err = $ctx->taskGroups[$g]->getErrors();
                    if (is_array($err)) {
                        $ctx->inc("tg_errors_count_$g", count($err));
                        foreach ($err as $e) {
                            if ($e instanceof \Throwable) $ctx->inc("tg_errors_throwable_$g");
                        }
                    } else {
                        $ctx->inc("tg_errors_bad_$g");
                    }
                });
            });

        // When coroutine "X" suppresses errors of "G"
        $r->on('/^coroutine "([^"]+)" suppresses errors of "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_suppress_attempts_$g");
                    try {
                        $ctx->taskGroups[$g]->suppressErrors();
                        $ctx->inc("tg_suppressed_$g");
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_suppress_failed_$g");
                    }
                });
            });

        // When coroutine "X" calls getIterator on "G" directly
        // foreach goes through the C get_iterator handler; the PHP-level
        // getIterator() method is a guard that always throws Error. The guard
        // must hold under the chaos scheduler regardless of group state.
        $r->on('/^coroutine "([^"]+)" calls getIterator on "([^"]+)" directly$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_get_iterator_attempts_$g");
                    try {
                        $ctx->taskGroups[$g]->getIterator();
                        $ctx->inc("tg_get_iterator_no_throw_$g");
                    } catch (\Error $e) {
                        $ctx->inc("tg_get_iterator_threw_$g");
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_get_iterator_other_throw_$g");
                    }
                });
            });

        // When coroutine "X" iterates "G" collecting outcomes
        // foreach over the group yields key => [result, error] as tasks settle;
        // success lands in tg_iter_ok, failure in tg_iter_error. The group must
        // already be closed so iteration terminates.
        $r->on('/^coroutine "([^"]+)" iterates "([^"]+)" collecting outcomes$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_iterate_attempts_$g");
                    foreach ($ctx->taskGroups[$g] as $key => $pair) {
                        $ctx->inc("tg_iter_total_$g");
                        $error = is_array($pair) ? ($pair[1] ?? null) : null;
                        if ($error instanceof \Throwable) {
                            $ctx->inc("tg_iter_error_$g");
                        } else {
                            $ctx->inc("tg_iter_ok_$g");
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

        // When coroutine "X" closes group "G"
        $r->on('/^coroutine "([^"]+)" closes group "([^"]+)"$/',
            function(Context $ctx, string $coro, string $g) {
                $ctx->planAction($coro, function(Context $ctx) use ($g) {
                    $ctx->inc("tg_close_attempts_$g");
                    try {
                        $ctx->taskGroups[$g]->close();
                        $ctx->inc("tg_closed_$g");
                    } catch (\Throwable $e) {
                        $ctx->inc("tg_close_failed_$g");
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

        // ---- TaskSet: joinAll / joinNext / joinAny ----

        // When coroutine "X" spawns N tasks into set "T"
        // Succeeding tasks: each suspends then returns a distinct value.
        $r->on('/^coroutine "([^"]+)" spawns (\S+) tasks into set "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $t) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $t) {
                    if (!isset($ctx->taskSets[$t])) { $ctx->inc("ts_spawn_target_missing_$t"); return; }
                    $set = $ctx->taskSets[$t];
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("ts_spawn_attempts_$t");
                        try {
                            $set->spawn(function() use ($ctx, $t, $i) {
                                \Async\suspend();
                                $ctx->inc("ts_done_$t");
                                return "r$i";
                            });
                            $ctx->inc("ts_spawned_$t");
                        } catch (\Throwable $e) {
                            $ctx->inc("ts_spawn_failed_$t");
                        }
                    }
                });
            });

        // When coroutine "X" spawns N failing tasks into set "T"
        $r->on('/^coroutine "([^"]+)" spawns (\S+) failing tasks into set "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $t) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $t) {
                    if (!isset($ctx->taskSets[$t])) { $ctx->inc("ts_spawn_target_missing_$t"); return; }
                    $set = $ctx->taskSets[$t];
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("ts_fspawn_attempts_$t");
                        try {
                            $set->spawn(function() use ($ctx, $t) {
                                \Async\suspend();
                                $ctx->inc("ts_fran_$t");
                                throw new \RuntimeException("set task boom");
                            });
                            $ctx->inc("ts_fspawned_$t");
                        } catch (\Throwable $e) {
                            $ctx->inc("ts_fspawn_failed_$t");
                        }
                    }
                });
            });

        // When coroutine "X" closes set "T"
        $r->on('/^coroutine "([^"]+)" closes set "([^"]+)"$/',
            function(Context $ctx, string $coro, string $t) {
                $ctx->planAction($coro, function(Context $ctx) use ($t) {
                    $ctx->inc("ts_close_attempts_$t");
                    try {
                        $ctx->taskSets[$t]->close();
                        $ctx->inc("ts_closed_$t");
                    } catch (\Throwable $e) {
                        $ctx->inc("ts_close_failed_$t");
                    }
                });
            });

        // When coroutine "X" joins all of set "T"
        // joinAll(true) resolves with every successful result; the set drains
        // to empty afterwards. The set must already be closed.
        $r->on('/^coroutine "([^"]+)" joins all of set "([^"]+)"$/',
            function(Context $ctx, string $coro, string $t) {
                $ctx->planAction($coro, function(Context $ctx) use ($t) {
                    $ctx->inc("ts_joinall_attempts_$t");
                    try {
                        $res = $ctx->taskSets[$t]->joinAll(true)->await();
                        $ctx->inc("ts_joinall_succeeded_$t");
                        $ctx->inc("ts_joinall_results_$t", is_array($res) ? count($res) : 0);
                    } catch (\Throwable $e) {
                        $ctx->inc("ts_joinall_failed_$t");
                    }
                });
            });

        // When coroutine "X" joins N times from set "T"
        // Each joinNext() delivers one settled task (success or error) and
        // removes its entry. ok + err == N for N spawned tasks.
        $r->on('/^coroutine "([^"]+)" joins (\S+) times from set "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $t) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $t) {
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("ts_joinnext_attempts_$t");
                        try {
                            $ctx->taskSets[$t]->joinNext()->await();
                            $ctx->inc("ts_joinnext_ok_$t");
                        } catch (\Throwable $e) {
                            $ctx->inc("ts_joinnext_err_$t");
                        }
                    }
                });
            });

        // When coroutine "X" joins any from set "T"
        // joinAny() resolves with the first successful task, skipping errors.
        // If every task fails it rejects with CompositeException — caught here
        // and its getExceptions() count recorded.
        $r->on('/^coroutine "([^"]+)" joins any from set "([^"]+)"$/',
            function(Context $ctx, string $coro, string $t) {
                $ctx->planAction($coro, function(Context $ctx) use ($t) {
                    $ctx->inc("ts_joinany_attempts_$t");
                    try {
                        $ctx->taskSets[$t]->joinAny()->await();
                        $ctx->inc("ts_joinany_succeeded_$t");
                    } catch (\Async\CompositeException $e) {
                        $ctx->inc("ts_joinany_composite_$t");
                        $ctx->inc("ts_joinany_composite_count_$t", count($e->getExceptions()));
                    } catch (\Throwable $e) {
                        $ctx->inc("ts_joinany_failed_$t");
                    }
                });
            });

        // ---- Pool: acquire / release / tryAcquire / circuit breaker ----

        // When coroutine "X" acquires and releases N resources from pool "P"
        // Each iteration: acquire (blocking), suspend so siblings interleave,
        // then release. acquired == released when nothing throws.
        $r->on('/^coroutine "([^"]+)" acquires and releases (\S+) resources from pool "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $p) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $p) {
                    if (!isset($ctx->pools[$p])) { $ctx->inc("pool_target_missing_$p"); return; }
                    $pool = $ctx->pools[$p];
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("pool_acquire_attempts_$p");
                        try {
                            $res = $pool->acquire();
                            $ctx->inc("pool_acquired_$p");
                            \Async\suspend();
                            $pool->release($res);
                            $ctx->inc("pool_released_$p");
                        } catch (\Throwable $e) {
                            $ctx->inc("pool_acquire_failed_$p");
                        }
                    }
                });
            });

        // When coroutine "X" tries to acquire from pool "P"
        // tryAcquire() never blocks: a resource or null. A resource is released
        // straight back so the pool stays balanced.
        $r->on('/^coroutine "([^"]+)" tries to acquire from pool "([^"]+)"$/',
            function(Context $ctx, string $coro, string $p) {
                $ctx->planAction($coro, function(Context $ctx) use ($p) {
                    if (!isset($ctx->pools[$p])) { $ctx->inc("pool_target_missing_$p"); return; }
                    $pool = $ctx->pools[$p];
                    $ctx->inc("pool_try_attempts_$p");
                    try {
                        $res = $pool->tryAcquire();
                        if ($res === null) {
                            $ctx->inc("pool_try_null_$p");
                        } else {
                            $ctx->inc("pool_try_got_$p");
                            \Async\suspend();
                            $pool->release($res);
                        }
                    } catch (\Throwable $e) {
                        $ctx->inc("pool_try_failed_$p");
                    }
                });
            });

        // When coroutine "X" inspects pool "P" counts
        // count() == idleCount() + activeCount() must hold at every instant;
        // each counter is a non-negative int.
        $r->on('/^coroutine "([^"]+)" inspects pool "([^"]+)" counts$/',
            function(Context $ctx, string $coro, string $p) {
                $ctx->planAction($coro, function(Context $ctx) use ($p) {
                    $ctx->inc("pool_counts_attempts_$p");
                    if (!isset($ctx->pools[$p])) { $ctx->inc("pool_target_missing_$p"); return; }
                    $pool = $ctx->pools[$p];
                    $total = $pool->count();
                    $idle  = $pool->idleCount();
                    $active = $pool->activeCount();
                    $ok = is_int($total) && $total >= 0
                        && is_int($idle) && $idle >= 0
                        && is_int($active) && $active >= 0
                        && $total === $idle + $active;
                    $ctx->inc($ok ? "pool_counts_ok_$p" : "pool_counts_bad_$p");
                });
            });

        // When coroutine "X" cycles the circuit breaker of pool "P"
        // ACTIVE -> deactivate -> INACTIVE -> recover -> RECOVERING ->
        // activate -> ACTIVE. getState() must report each transition exactly.
        $r->on('/^coroutine "([^"]+)" cycles the circuit breaker of pool "([^"]+)"$/',
            function(Context $ctx, string $coro, string $p) {
                $ctx->planAction($coro, function(Context $ctx) use ($p) {
                    $ctx->inc("cb_cycle_attempts_$p");
                    if (!isset($ctx->pools[$p])) { $ctx->inc("pool_target_missing_$p"); return; }
                    $pool = $ctx->pools[$p];
                    $s0 = $pool->getState();
                    $pool->deactivate(); $s1 = $pool->getState();
                    $pool->recover();    $s2 = $pool->getState();
                    $pool->activate();   $s3 = $pool->getState();
                    $ok = $s0 === \Async\CircuitBreakerState::ACTIVE
                        && $s1 === \Async\CircuitBreakerState::INACTIVE
                        && $s2 === \Async\CircuitBreakerState::RECOVERING
                        && $s3 === \Async\CircuitBreakerState::ACTIVE;
                    $ctx->inc($ok ? "cb_cycle_ok_$p" : "cb_cycle_bad_$p");
                });
            });

        // When coroutine "X" attaches a recording strategy to pool "P"
        // The strategy's reportSuccess/reportFailure fire on each release.
        $r->on('/^coroutine "([^"]+)" attaches a recording strategy to pool "([^"]+)"$/',
            function(Context $ctx, string $coro, string $p) {
                $ctx->planAction($coro, function(Context $ctx) use ($p) {
                    if (!isset($ctx->pools[$p])) { $ctx->inc("pool_target_missing_$p"); return; }
                    $strategy = new ChaosCircuitBreakerStrategy($ctx, $p);
                    $ctx->poolStrategies[$p] = $strategy;
                    $ctx->pools[$p]->setCircuitBreakerStrategy($strategy);
                    $ctx->inc("cb_strategy_attached_$p");
                });
            });

        // When coroutine "X" detaches the strategy from pool "P"
        $r->on('/^coroutine "([^"]+)" detaches the strategy from pool "([^"]+)"$/',
            function(Context $ctx, string $coro, string $p) {
                $ctx->planAction($coro, function(Context $ctx) use ($p) {
                    if (!isset($ctx->pools[$p])) { $ctx->inc("pool_target_missing_$p"); return; }
                    $ctx->pools[$p]->setCircuitBreakerStrategy(null);
                    unset($ctx->poolStrategies[$p]);
                    $ctx->inc("cb_strategy_detached_$p");
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

        // Then counter "X" plus counter "Y" plus counter "Z" equals counter "W"
        $r->on('/^counter "([^"]+)" plus counter "([^"]+)" plus counter "([^"]+)" equals counter "([^"]+)"$/',
            function(Context $ctx, string $a, string $b, string $c, string $d) {
                $sum = $ctx->counter($a) + $ctx->counter($b) + $ctx->counter($c);
                $dv = $ctx->counter($d);
                if ($sum !== $dv) {
                    throw new \RuntimeException(
                        "counter $a + $b + $c = $sum, but counter $d = $dv"
                    );
                }
            });

        // Then counter "X" plus counter "Y" plus counter "Z" equals N
        $r->on('/^counter "([^"]+)" plus counter "([^"]+)" plus counter "([^"]+)" equals (\d+)$/',
            function(Context $ctx, string $a, string $b, string $c, string $expected) {
                $sum = $ctx->counter($a) + $ctx->counter($b) + $ctx->counter($c);
                if ($sum !== (int)$expected) {
                    throw new \RuntimeException(
                        "counter $a + $b + $c = $sum, expected $expected"
                    );
                }
            });

        // Then counter "X" plus counter "Y" plus counter "Z" plus counter "W" equals N
        $r->on('/^counter "([^"]+)" plus counter "([^"]+)" plus counter "([^"]+)" plus counter "([^"]+)" equals (\d+)$/',
            function(Context $ctx, string $a, string $b, string $c, string $d, string $expected) {
                $sum = $ctx->counter($a) + $ctx->counter($b) + $ctx->counter($c) + $ctx->counter($d);
                if ($sum !== (int)$expected) {
                    throw new \RuntimeException(
                        "counter $a + $b + $c + $d = $sum, expected $expected"
                    );
                }
            });

        // Then counter "X" plus counter "Y" plus counter "Z" plus counter "W" equals counter "V"
        $r->on('/^counter "([^"]+)" plus counter "([^"]+)" plus counter "([^"]+)" plus counter "([^"]+)" equals counter "([^"]+)"$/',
            function(Context $ctx, string $a, string $b, string $c, string $d, string $e) {
                $sum = $ctx->counter($a) + $ctx->counter($b) + $ctx->counter($c) + $ctx->counter($d);
                $ev = $ctx->counter($e);
                if ($sum !== $ev) {
                    throw new \RuntimeException(
                        "counter $a + $b + $c + $d = $sum, but counter $e = $ev"
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

        // Then channel "ch" capacity equals N
        // capacity() reports the buffer size fixed at construction — stable for
        // the channel's whole lifetime, including after close.
        $r->on('/^channel "([^"]+)" capacity equals (\d+)$/',
            function(Context $ctx, string $name, string $nExpr) {
                if (!isset($ctx->channels[$name])) {
                    throw new \RuntimeException("channel $name not defined");
                }
                $cap = $ctx->channels[$name]->capacity();
                $want = (int)$nExpr;
                if ($cap !== $want) {
                    throw new \RuntimeException("channel $name capacity expected $want, got "
                        . var_export($cap, true));
                }
            });

        // Then thread channel "tc" capacity equals N
        $r->on('/^thread channel "([^"]+)" capacity equals (\d+)$/',
            function(Context $ctx, string $name, string $nExpr) {
                if (!isset($ctx->threadChannels[$name])) {
                    throw new \RuntimeException("thread channel $name not defined");
                }
                $cap = $ctx->threadChannels[$name]->capacity();
                $want = (int)$nExpr;
                if ($cap !== $want) {
                    throw new \RuntimeException("thread channel $name capacity expected $want, got "
                        . var_export($cap, true));
                }
            });

        // Then channel "ch" is full
        $r->on('/^channel "([^"]+)" is full$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->channels[$name]) || !$ctx->channels[$name]->isFull()) {
                    throw new \RuntimeException("channel $name expected to be full");
                }
            });

        // Then channel "ch" is not full
        $r->on('/^channel "([^"]+)" is not full$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->channels[$name])) {
                    throw new \RuntimeException("channel $name not defined");
                }
                if ($ctx->channels[$name]->isFull()) {
                    throw new \RuntimeException("channel $name expected NOT to be full");
                }
            });

        // Then coroutine "X" has no exception
        $r->on('/^coroutine "([^"]+)" has no exception$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->coroutineHandles[$name])) {
                    throw new \RuntimeException("coroutine $name not defined");
                }
                $e = $ctx->coroutineHandles[$name]->getException();
                if ($e !== null) {
                    throw new \RuntimeException(
                        "coroutine $name expected no exception, got " . get_class($e)
                            . ": " . $e->getMessage()
                    );
                }
            });

        // Then coroutine "X" exception is "ClassName"
        // Asserts getException() returns an instance of the named class.
        $r->on('/^coroutine "([^"]+)" exception is "([^"]+)"$/',
            function(Context $ctx, string $name, string $class) {
                if (!isset($ctx->coroutineHandles[$name])) {
                    throw new \RuntimeException("coroutine $name not defined");
                }
                $e = $ctx->coroutineHandles[$name]->getException();
                if ($e === null) {
                    throw new \RuntimeException("coroutine $name expected $class, got null");
                }
                if (!($e instanceof $class)) {
                    throw new \RuntimeException(
                        "coroutine $name expected $class, got " . get_class($e)
                    );
                }
            });

        // Then coroutine "X" is completed
        // After Context::run() every planned coroutine has terminated.
        // isCompleted must be true; isRunning/isSuspended must be false.
        // isStarted is NOT required — a coroutine that was cancelled before
        // the scheduler ever picked it up reports isStarted=false but is
        // still terminal.
        $r->on('/^coroutine "([^"]+)" is completed$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->coroutineHandles[$name])) {
                    throw new \RuntimeException("coroutine $name not defined");
                }
                $h = $ctx->coroutineHandles[$name];
                if (!$h->isCompleted()) {
                    throw new \RuntimeException("coroutine $name expected isCompleted=true");
                }
                if ($h->isRunning()) {
                    throw new \RuntimeException("coroutine $name expected isRunning=false");
                }
                if ($h->isSuspended()) {
                    throw new \RuntimeException("coroutine $name expected isSuspended=false");
                }
            });

        // Then coroutine "X" is cancelled
        $r->on('/^coroutine "([^"]+)" is cancelled$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->coroutineHandles[$name])) {
                    throw new \RuntimeException("coroutine $name not defined");
                }
                if (!$ctx->coroutineHandles[$name]->isCancelled()) {
                    throw new \RuntimeException("coroutine $name expected isCancelled=true");
                }
            });

        // Then coroutine "X" final trace is null
        // After run() has returned, every planned coroutine is terminated, so
        // getTrace() must report null regardless of how it terminated.
        $r->on('/^coroutine "([^"]+)" final trace is null$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->coroutineHandles[$name])) {
                    throw new \RuntimeException("coroutine $name not defined");
                }
                $t = $ctx->coroutineHandles[$name]->getTrace();
                if ($t !== null) {
                    throw new \RuntimeException(
                        "coroutine $name expected null trace post-termination, got "
                            . (is_array($t) ? 'array(' . count($t) . ')' : gettype($t))
                    );
                }
            });

        // Then coroutine "X" has a well-formed spawn location
        // Post-termination the spawn location is still a [file,int] pair and a
        // "file:line" string — it is captured once at spawn() and never reset.
        $r->on('/^coroutine "([^"]+)" has a well-formed spawn location$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->coroutineHandles[$name])) {
                    throw new \RuntimeException("coroutine $name not defined");
                }
                $h  = $ctx->coroutineHandles[$name];
                $fl = $h->getSpawnFileAndLine();
                $loc = $h->getSpawnLocation();
                if (!is_array($fl) || count($fl) !== 2
                    || !(is_string($fl[0]) || $fl[0] === null) || !is_int($fl[1])) {
                    throw new \RuntimeException("coroutine $name malformed getSpawnFileAndLine()");
                }
                if (!is_string($loc) || strpos($loc, ':') === false) {
                    throw new \RuntimeException(
                        "coroutine $name malformed getSpawnLocation(): " . var_export($loc, true));
                }
            });

        // Then coroutine "X" awaiting info is an array
        $r->on('/^coroutine "([^"]+)" awaiting info is an array$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->coroutineHandles[$name])) {
                    throw new \RuntimeException("coroutine $name not defined");
                }
                $info = $ctx->coroutineHandles[$name]->getAwaitingInfo();
                if (!is_array($info)) {
                    throw new \RuntimeException(
                        "coroutine $name expected getAwaitingInfo() array, got " . gettype($info));
                }
            });

        // Then coroutine "X" context is a Context
        $r->on('/^coroutine "([^"]+)" context is a Context$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->coroutineHandles[$name])) {
                    throw new \RuntimeException("coroutine $name not defined");
                }
                $c = $ctx->coroutineHandles[$name]->getContext();
                if (!($c instanceof \Async\Context)) {
                    throw new \RuntimeException(
                        "coroutine $name expected getContext() Async\\Context, got "
                            . (is_object($c) ? get_class($c) : gettype($c)));
                }
            });

        // Then coroutine "X" result is null
        $r->on('/^coroutine "([^"]+)" result is null$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->coroutineHandles[$name])) {
                    throw new \RuntimeException("coroutine $name not defined");
                }
                $r = $ctx->coroutineHandles[$name]->getResult();
                if ($r !== null) {
                    throw new \RuntimeException(
                        "coroutine $name expected null result, got " . var_export($r, true)
                    );
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

        // Then group "G" is closed
        $r->on('/^group "([^"]+)" is closed$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->taskGroups[$name]) || !$ctx->taskGroups[$name]->isClosed()) {
                    throw new \RuntimeException("group $name expected to be closed");
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

        // Then set "T" is finished
        $r->on('/^set "([^"]+)" is finished$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->taskSets[$name]) || !$ctx->taskSets[$name]->isFinished()) {
                    throw new \RuntimeException("set $name expected to be finished");
                }
            });

        // Then set "T" count equals N
        $r->on('/^set "([^"]+)" count equals (\d+)$/',
            function(Context $ctx, string $name, string $expected) {
                if (!isset($ctx->taskSets[$name])) {
                    throw new \RuntimeException("set $name not defined");
                }
                $c = $ctx->taskSets[$name]->count();
                if ($c !== (int)$expected) {
                    throw new \RuntimeException("set $name count = $c, expected $expected");
                }
            });

        // Then pool "P" active count equals N
        $r->on('/^pool "([^"]+)" active count equals (\d+)$/',
            function(Context $ctx, string $name, string $expected) {
                if (!isset($ctx->pools[$name])) {
                    throw new \RuntimeException("pool $name not defined");
                }
                $c = $ctx->pools[$name]->activeCount();
                if ($c !== (int)$expected) {
                    throw new \RuntimeException("pool $name activeCount = $c, expected $expected");
                }
            });

        // Then pool "P" count equals N
        $r->on('/^pool "([^"]+)" count equals (\d+)$/',
            function(Context $ctx, string $name, string $expected) {
                if (!isset($ctx->pools[$name])) {
                    throw new \RuntimeException("pool $name not defined");
                }
                $c = $ctx->pools[$name]->count();
                if ($c !== (int)$expected) {
                    throw new \RuntimeException("pool $name count = $c, expected $expected");
                }
            });

        // Then pool "P" circuit state is ACTIVE|INACTIVE|RECOVERING
        $r->on('/^pool "([^"]+)" circuit state is (ACTIVE|INACTIVE|RECOVERING)$/',
            function(Context $ctx, string $name, string $expected) {
                if (!isset($ctx->pools[$name])) {
                    throw new \RuntimeException("pool $name not defined");
                }
                $state = $ctx->pools[$name]->getState();
                if ($state->name !== $expected) {
                    throw new \RuntimeException(
                        "pool $name circuit state = {$state->name}, expected $expected");
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
