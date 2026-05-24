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

        // Given an evil peer "EP" serving "payload"
        // Declares an EvilPeer that, on its single accepted connection,
        // delivers the given payload. Later "evil peer" Given steps layer
        // toxics (slicing, delay) onto this fault table.
        $r->on('/^an evil peer "([^"]+)" serving "([^"]*)"$/',
            function(Context $ctx, string $name, string $payload) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['payload'] = $payload;
            });

        // Given an evil peer "EP" serving N bytes
        // Same, but the payload is N deterministic bytes (a repeating
        // pattern) — convenient for large-payload slicing scenarios.
        $r->on('/^an evil peer "([^"]+)" serving (\S+) bytes$/',
            function(Context $ctx, string $name, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->net->defineEvilPeer($name);
                $payload = '';
                for ($i = 0; $i < $n; $i++) {
                    $payload .= chr(33 + ($i % 94)); // printable ASCII cycle
                }
                $ctx->net->evilPeerDefs[$name]['payload'] = $payload;
            });

        // Given evil peer "EP" slices output into N-byte chunks
        $r->on('/^evil peer "([^"]+)" slices output into (\S+)-byte chunks$/',
            function(Context $ctx, string $name, string $nExpr) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['slice'] = (int)$ctx->resolver->resolve($nExpr);
            });

        // Given evil peer "EP" delays N ms between chunks
        $r->on('/^evil peer "([^"]+)" delays (\S+) ms between chunks$/',
            function(Context $ctx, string $name, string $nExpr) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['delay'] = (int)$ctx->resolver->resolve($nExpr);
            });

        // Given evil peer "EP" closes abruptly after N bytes
        // The peer drops the connection mid-stream once N bytes are out — the
        // client must see a clean truncation, not a hang or a corrupt buffer.
        $r->on('/^evil peer "([^"]+)" closes abruptly after (\S+) bytes$/',
            function(Context $ctx, string $name, string $nExpr) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['reset'] = (int)$ctx->resolver->resolve($nExpr);
            });

        // Given an evil peer "EP" that never reads
        // A consume-mode peer that accepts the connection and never drains a
        // single byte. The client's send buffer fills and its fwrite()
        // suspends on the reactor's write-wait hook — the back-pressure path.
        $r->on('/^an evil peer "([^"]+)" that never reads$/',
            function(Context $ctx, string $name) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['mode']  = 'consume';
                $ctx->net->evilPeerDefs[$name]['slice'] = 0;
            });

        // Given an evil peer "EP" that reads N bytes at a time
        // A consume-mode peer that drains its receive buffer in fixed-size
        // reads — slow enough (especially with a between-reads delay) to keep
        // the client's writer suspended for a while.
        $r->on('/^an evil peer "([^"]+)" that reads (\S+) bytes at a time$/',
            function(Context $ctx, string $name, string $nExpr) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['mode']  = 'consume';
                $ctx->net->evilPeerDefs[$name]['slice'] = (int)$ctx->resolver->resolve($nExpr);
            });

        // Given evil peer "EP" delays N ms between reads
        $r->on('/^evil peer "([^"]+)" delays (\S+) ms between reads$/',
            function(Context $ctx, string $name, string $nExpr) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['delay'] = (int)$ctx->resolver->resolve($nExpr);
            });

        // Given evil peer "EP" stops reading after N bytes
        // The peer drains N bytes then abandons the connection — the client's
        // writer must see a clean broken-pipe failure, not a hang.
        $r->on('/^evil peer "([^"]+)" stops reading after (\S+) bytes$/',
            function(Context $ctx, string $name, string $nExpr) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['reset'] = (int)$ctx->resolver->resolve($nExpr);
            });

        // Given evil peer "EP" holds the connection for N ms
        // Only meaningful for a never-read peer: how long it keeps the stalled
        // connection open before closing, i.e. the killer's window to cancel.
        $r->on('/^evil peer "([^"]+)" holds the connection for (\S+) ms$/',
            function(Context $ctx, string $name, string $nExpr) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['hold'] = (int)$ctx->resolver->resolve($nExpr);
            });

        // Given evil peer "EP" uses a hard reset
        // Toxic modifier: arms SO_LINGER{l_onoff:1,l_linger:0} so the peer's
        // close emits an immediate RST instead of a graceful FIN — the client
        // faces a real ECONNRESET, not a clean EOF.
        $r->on('/^evil peer "([^"]+)" uses a hard reset$/',
            function(Context $ctx, string $name) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['hardReset'] = true;
            })
            ->requires('sockets');

        // Given evil peer "EP" runs as a forked peer
        // Toxic modifier: the peer runs in a separate OS process (proc_open)
        // instead of an in-process coroutine — a genuinely independent TCP
        // endpoint, no shared reactor. The same fault table applies.
        $r->on('/^evil peer "([^"]+)" runs as a forked peer$/',
            function(Context $ctx, string $name) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['forked'] = true;
            });

        // ---- Toxiproxy: external transport-level fault injection ----
        // These steps front an EvilPeer with a Toxiproxy proxy and apply
        // transport toxics a pure-PHP peer cannot reproduce precisely (real
        // bandwidth throttling, latency+jitter, TCP-segment slicing, byte-
        // counted truncation). Every Toxiproxy step is tagged ->requires(
        // 'toxiproxy'); the generator emits a --SKIPIF-- probe so the test
        // skips wherever no Toxiproxy admin endpoint answers.

        // Given evil peer "EP" is fronted by Toxiproxy
        // Routes the client through a Toxiproxy proxy with no toxics — the
        // pass-through baseline (the proxy must be transparent on its own).
        $r->on('/^evil peer "([^"]+)" is fronted by Toxiproxy$/',
            function(Context $ctx, string $name) {
                $ctx->net->addEvilPeerToxic($name);
            })
            ->requires('toxiproxy');

        // Given Toxiproxy throttles peer "EP" to N KB/s
        // bandwidth toxic — caps real throughput; the payload still arrives
        // intact, just slowly. This is the headline toxic PHP cannot do.
        $r->on('/^Toxiproxy throttles peer "([^"]+)" to (\S+) KB\/s$/',
            function(Context $ctx, string $name, string $rateExpr) {
                $rate = (int)$ctx->resolver->resolve($rateExpr);
                $ctx->net->addEvilPeerToxic($name, 'bandwidth', 'auto', ['rate' => $rate]);
            })
            ->requires('toxiproxy');

        // Given Toxiproxy adds N ms latency with M ms jitter to peer "EP"
        // latency toxic with jitter — random per-packet delay in [N-M, N+M].
        $r->on('/^Toxiproxy adds (\S+) ms latency with (\S+) ms jitter to peer "([^"]+)"$/',
            function(Context $ctx, string $latExpr, string $jitExpr, string $name) {
                $lat = (int)$ctx->resolver->resolve($latExpr);
                $jit = (int)$ctx->resolver->resolve($jitExpr);
                $ctx->net->addEvilPeerToxic($name, 'latency', 'auto',
                    ['latency' => $lat, 'jitter' => $jit]);
            })
            ->requires('toxiproxy');

        // Given Toxiproxy adds N ms latency to peer "EP"
        // latency toxic — fixed per-packet delay; payload arrives intact.
        $r->on('/^Toxiproxy adds (\S+) ms latency to peer "([^"]+)"$/',
            function(Context $ctx, string $latExpr, string $name) {
                $lat = (int)$ctx->resolver->resolve($latExpr);
                $ctx->net->addEvilPeerToxic($name, 'latency', 'auto', ['latency' => $lat]);
            })
            ->requires('toxiproxy');

        // Given Toxiproxy slices peer "EP" into N-byte TCP segments
        // slicer toxic — chops the TCP stream into ~N-byte packets at the
        // transport level (distinct from EvilPeer's application-level slice).
        $r->on('/^Toxiproxy slices peer "([^"]+)" into (\S+)-byte TCP segments$/',
            function(Context $ctx, string $name, string $sizeExpr) {
                $size = (int)$ctx->resolver->resolve($sizeExpr);
                $ctx->net->addEvilPeerToxic($name, 'slicer', 'auto', [
                    'average_size'   => $size,
                    'size_variation' => intdiv($size, 4),
                    'delay'          => 0,
                ]);
            })
            ->requires('toxiproxy');

        // Given Toxiproxy cuts peer "EP" off after N bytes
        // limit_data toxic — closes the connection once exactly N bytes have
        // passed; a deterministic truncation (decidable exact byte count).
        $r->on('/^Toxiproxy cuts peer "([^"]+)" off after (\S+) bytes$/',
            function(Context $ctx, string $name, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->net->addEvilPeerToxic($name, 'limit_data', 'auto', ['bytes' => $n]);
            })
            ->requires('toxiproxy');

        // Given Toxiproxy resets peer "EP" after N ms
        // reset_peer toxic — sends a TCP RST N ms into the connection; a
        // time-based (non-deterministic byte count) truncation.
        $r->on('/^Toxiproxy resets peer "([^"]+)" after (\S+) ms$/',
            function(Context $ctx, string $name, string $msExpr) {
                $ms = (int)$ctx->resolver->resolve($msExpr);
                $ctx->net->addEvilPeerToxic($name, 'reset_peer', 'auto', ['timeout' => $ms]);
            })
            ->requires('toxiproxy');

        // ---- Evil HTTP peer: an EvilPeer that speaks HTTP/1.1 ----
        // The peer drains one HTTP request, then writes back a response. The
        // body-level toxics ("slices output", "delays ms between chunks",
        // "closes abruptly after N bytes", "uses a hard reset", "runs as a
        // forked peer", every Toxiproxy step) all reuse the serve-mode steps
        // above — they only set keys, mode-agnostic. The steps below add the
        // HTTP-specific framing and toxics. An async ext/curl client driven by
        // the reactor faces this peer.

        // Given an evil HTTP peer "EP" serving N bytes
        $r->on('/^an evil HTTP peer "([^"]+)" serving (\S+) bytes$/',
            function(Context $ctx, string $name, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['mode'] = 'http';
                $payload = '';
                for ($i = 0; $i < $n; $i++) {
                    $payload .= chr(33 + ($i % 94)); // printable ASCII cycle
                }
                $ctx->net->evilPeerDefs[$name]['payload'] = $payload;
            })
            ->requires('curl');

        // Given an evil HTTP peer "EP" serving "body"
        $r->on('/^an evil HTTP peer "([^"]+)" serving "([^"]*)"$/',
            function(Context $ctx, string $name, string $body) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['mode']    = 'http';
                $ctx->net->evilPeerDefs[$name]['payload'] = $body;
            })
            ->requires('curl');

        // Given evil HTTP peer "EP" responds with status N
        // The peer answers with an arbitrary HTTP status. curl still completes
        // the transaction successfully (errno 0) — a 4xx/5xx is a valid HTTP
        // response, not a transport error.
        $r->on('/^evil HTTP peer "([^"]+)" responds with status (\S+)$/',
            function(Context $ctx, string $name, string $sExpr) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['mode']       = 'http';
                $ctx->net->evilPeerDefs[$name]['httpStatus'] = (int)$ctx->resolver->resolve($sExpr);
            })
            ->requires('curl');

        // Given evil HTTP peer "EP" uses chunked transfer encoding
        // The body arrives Transfer-Encoding: chunked; curl must de-chunk it
        // back to the exact byte stream regardless of how it was framed.
        $r->on('/^evil HTTP peer "([^"]+)" uses chunked transfer encoding$/',
            function(Context $ctx, string $name) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['mode']        = 'http';
                $ctx->net->evilPeerDefs[$name]['httpChunked'] = true;
            })
            ->requires('curl');

        // Given evil HTTP peer "EP" overstates Content-Length by N bytes
        // A mendacious header — the peer promises more than it delivers. curl
        // waits for bytes that never come and must report CURLE_PARTIAL_FILE,
        // never hang.
        $r->on('/^evil HTTP peer "([^"]+)" overstates Content-Length by (\S+) bytes$/',
            function(Context $ctx, string $name, string $nExpr) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['mode']        = 'http';
                $ctx->net->evilPeerDefs[$name]['httpClenLie'] = (int)$ctx->resolver->resolve($nExpr);
            })
            ->requires('curl');

        // Given evil HTTP peer "EP" understates Content-Length by N bytes
        // The peer promises fewer bytes than it sends; curl stops reading at
        // the advertised length, so the client sees a clean prefix.
        $r->on('/^evil HTTP peer "([^"]+)" understates Content-Length by (\S+) bytes$/',
            function(Context $ctx, string $name, string $nExpr) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['mode']        = 'http';
                $ctx->net->evilPeerDefs[$name]['httpClenLie'] = -(int)$ctx->resolver->resolve($nExpr);
            })
            ->requires('curl');

        // Given evil HTTP peer "EP" delays N ms mid-headers
        // Slow-headers toxic: the response status line and headers dribble in
        // over two TCP writes with a pause between. curl's header parser must
        // stay interruptible and reassemble them correctly.
        $r->on('/^evil HTTP peer "([^"]+)" delays (\S+) ms mid-headers$/',
            function(Context $ctx, string $name, string $nExpr) {
                $ctx->net->defineEvilPeer($name);
                $ctx->net->evilPeerDefs[$name]['mode']            = 'http';
                $ctx->net->evilPeerDefs[$name]['httpHeaderDelay'] = (int)$ctx->resolver->resolve($nExpr);
            })
            ->requires('curl');

        // ---- Database under chaos: a real DB server fronted by Toxiproxy ----
        // A DB driver speaks a binary wire protocol, so a pure-PHP mock is not
        // worth it — the chaos lands at the transport level instead: Toxiproxy
        // sits between the async PDO client and a real MySQL server, injecting
        // latency / bandwidth caps / RST mid-query. Every DB step is tagged
        // ->requires('toxiproxy','pdo_mysql','mysql-server'); the generator
        // emits a --SKIPIF-- probe so the test runs only where all three are
        // present (the nightly job) and skips everywhere else.

        // Given a MySQL database "DB"
        // A non-pooled database: each query opens its own PDO connection.
        $r->on('/^a MySQL database "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->net->defineEvilDb($name, 'mysql');
            })
            ->requires('toxiproxy', 'pdo_mysql', 'mysql-server');

        // Given a pooled MySQL database "DB"
        // A pool-enabled database: one shared PDO handle, per-coroutine slots.
        $r->on('/^a pooled MySQL database "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->net->defineEvilDb($name, 'mysql', true);
            })
            ->requires('toxiproxy', 'pdo_mysql', 'mysql-server');

        // Given a pooled MySQL database "DB" with N connections
        $r->on('/^a pooled MySQL database "([^"]+)" with (\S+) connections$/',
            function(Context $ctx, string $name, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->net->defineEvilDb($name, 'mysql', true, $n > 0 ? $n : 1);
            })
            ->requires('toxiproxy', 'pdo_mysql', 'mysql-server');

        // Given Toxiproxy adds N ms latency to database "DB"
        $r->on('/^Toxiproxy adds (\S+) ms latency to database "([^"]+)"$/',
            function(Context $ctx, string $latExpr, string $name) {
                $lat = (int)$ctx->resolver->resolve($latExpr);
                $ctx->net->addEvilDbToxic($name, 'latency', ['latency' => $lat]);
            })
            ->requires('toxiproxy');

        // Given Toxiproxy throttles database "DB" to N KB/s
        $r->on('/^Toxiproxy throttles database "([^"]+)" to (\S+) KB\/s$/',
            function(Context $ctx, string $name, string $rateExpr) {
                $rate = (int)$ctx->resolver->resolve($rateExpr);
                $ctx->net->addEvilDbToxic($name, 'bandwidth', ['rate' => $rate]);
            })
            ->requires('toxiproxy');

        // Given Toxiproxy slices database "DB" into N-byte TCP segments
        $r->on('/^Toxiproxy slices database "([^"]+)" into (\S+)-byte TCP segments$/',
            function(Context $ctx, string $name, string $sizeExpr) {
                $size = (int)$ctx->resolver->resolve($sizeExpr);
                $ctx->net->addEvilDbToxic($name, 'slicer', [
                    'average_size'   => $size,
                    'size_variation' => intdiv($size, 4),
                    'delay'          => 0,
                ]);
            })
            ->requires('toxiproxy');

        // Given Toxiproxy resets database "DB" after N ms
        // reset_peer toxic — a TCP RST N ms into the connection; lands
        // mid-query for any query that runs longer than N ms.
        $r->on('/^Toxiproxy resets database "([^"]+)" after (\S+) ms$/',
            function(Context $ctx, string $name, string $msExpr) {
                $ms = (int)$ctx->resolver->resolve($msExpr);
                $ctx->net->addEvilDbToxic($name, 'reset_peer', ['timeout' => $ms]);
            })
            ->requires('toxiproxy');

        // Given a MySQLi database "DB"
        // The same Toxiproxy-fronted MySQL server, reached through the mysqli
        // extension instead of PDO. mysqli has no connection pool, so every
        // query opens its own connection. The Toxiproxy toxic steps above are
        // driver-agnostic and apply to a MySQLi database too.
        $r->on('/^a MySQLi database "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->net->defineEvilDb($name, 'mysqli');
            })
            ->requires('toxiproxy', 'mysqli', 'mysql-server');

        // Given a PgSQL database "DB"
        // A PostgreSQL server, fronted by Toxiproxy exactly like the MySQL
        // one. The Toxiproxy toxic steps and the `queries / runs a slow query
        // on / runs a transaction on database` client steps are all
        // driver-agnostic — dbRun()/dbTransaction() build a pgsql: DSN when
        // the database's driver is pgsql.
        $r->on('/^a PgSQL database "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->net->defineEvilDb($name, 'pgsql');
            })
            ->requires('toxiproxy', 'pdo_pgsql', 'pgsql-server');

        // Given a pooled PgSQL database "DB"
        $r->on('/^a pooled PgSQL database "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->net->defineEvilDb($name, 'pgsql', true);
            })
            ->requires('toxiproxy', 'pdo_pgsql', 'pgsql-server');

        // Given a pooled PgSQL database "DB" with N connections
        $r->on('/^a pooled PgSQL database "([^"]+)" with (\S+) connections$/',
            function(Context $ctx, string $name, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->net->defineEvilDb($name, 'pgsql', true, $n > 0 ? $n : 1);
            })
            ->requires('toxiproxy', 'pdo_pgsql', 'pgsql-server');

        // Given a [pooled] SQLite database "DB"
        // SQLite is a local file — no Toxiproxy, no network toxics. The chaos
        // surface is the PDO pool itself: per-coroutine sqlite3* slots over one
        // shared file, with the same client steps (queries / runs a transaction
        // on database) as the network drivers.
        $r->on('/^a SQLite database "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->net->defineEvilDb($name, 'sqlite');
            })
            ->requires('pdo_sqlite');

        $r->on('/^a pooled SQLite database "([^"]+)"$/',
            function(Context $ctx, string $name) {
                $ctx->net->defineEvilDb($name, 'sqlite', true);
            })
            ->requires('pdo_sqlite');

        $r->on('/^a pooled SQLite database "([^"]+)" with (\S+) connections$/',
            function(Context $ctx, string $name, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->net->defineEvilDb($name, 'sqlite', true, $n > 0 ? $n : 1);
            })
            ->requires('pdo_sqlite');

        // Given a pooled SQLite database "DB" with N connections and stmt cache C
        // Adds the per-physical-connection prepared-statement LRU cache
        // capacity (PDO::ATTR_POOL_STMT_CACHE_SIZE). C distinct SQL
        // strings stay cached; cache-storm scenarios exceed C to drive
        // LRU eviction. Backstop for the recent stmt cache feature —
        // chaos cancel-mid-prepare must not corrupt the LRU state.
        $r->on('/^a pooled SQLite database "([^"]+)" with (\S+) connections and stmt cache (\S+)$/',
            function(Context $ctx, string $name, string $nExpr, string $cExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $c = (int)$ctx->resolver->resolve($cExpr);
                $ctx->net->defineEvilDb($name, 'sqlite', true, $n > 0 ? $n : 1);
                $ctx->net->setEvilDbStmtCache($name, $c);
            })
            ->requires('pdo_sqlite');

        // When coroutine "X" runs cache-storm of N statements on database "DB"
        // Prepares + executes N distinct SQL strings against the
        // database, each with a unique constant comment so the
        // server-side normalised SQL hashes differently and the stmt
        // cache treats them as distinct entries. If the cache capacity
        // < N (the typical scenario), this forces LRU eviction every
        // iteration past capacity — exactly the surface the cache's
        // release path is most likely to break under.
        //
        // Counters per coroutine:
        //   db_storm_attempts — bumped per prepare attempt
        //   db_storm_ok       — prepare + execute + fetch returned cleanly
        //   db_storm_cancelled — AsyncCancellation caught mid-storm
        //   db_storm_failed   — any other throwable (counts the offending one)
        //   db_storm_rows     — total rows seen across all executes
        $r->on('/^coroutine "([^"]+)" runs cache-storm of (\S+) statements on database "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $db) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $n, $db) {
                    $spec = $ctx->net->evilDbDefs[$db] ?? null;
                    if ($spec === null || !isset($ctx->net->evilDbAddr[$db])) {
                        $ctx->inc("db_storm_no_db_$coro");
                        return;
                    }
                    $pdo = $spec['pool']
                        ? $ctx->net->evilDbPool[$db]
                        : $ctx->net->openDbConnection($db, false);
                    $rows = 0;
                    for ($i = 0; $i < $n; $i++) {
                        try {
                            $ctx->inc("db_storm_attempts_$coro");
                            // Yield once per iteration so the scheduler
                            // can deliver cancellation (drivers like
                            // SQLite have no internal yield points; without
                            // this, a cancel scheduled mid-storm wouldn't
                            // land until the whole loop finished).
                            \Async\delay(1);
                            // Unique-per-i comment + id parameter so the
                            // server caches distinct prepared statements
                            // (post-normalisation the comment is
                            // significant enough to give a distinct hash
                            // in the PDO stmt cache implementation).
                            $sql  = "SELECT id, label FROM items WHERE id = ? /* cs_" . $coro . '_' . $i . " */";
                            $stmt = @$pdo->prepare($sql);
                            if ($stmt === false) {
                                $ctx->inc("db_storm_failed_$coro");
                                continue;
                            }
                            @$stmt->execute([1 + ($i % 5)]);
                            while (@$stmt->fetch(\PDO::FETCH_NUM) !== false) {
                                $rows++;
                            }
                            $ctx->inc("db_storm_ok_$coro");
                        } catch (\Async\AsyncCancellation $e) {
                            $ctx->inc("db_storm_cancelled_$coro");
                            $ctx->inc("db_storm_rows_$coro", $rows);
                            return;
                        } catch (\Throwable $e) {
                            $ctx->inc("db_storm_failed_$coro");
                        }
                    }
                    $ctx->inc("db_storm_rows_$coro", $rows);
                });
            });


        // ---- When: actions inside a coroutine ----

        // When coroutine "X" downloads from peer "EP"
        // Connects and reads until EOF in 8 KiB reads. Cancellation-aware: a
        // cancel mid-download lands in io_download_cancelled with the partial
        // bytes preserved. The liveness invariant
        //   io_download_ok + io_download_cancelled + io_download_failed
        //     + io_download_connect_failed + io_download_no_peer == attempts
        // therefore holds for every interleaving.
        $r->on('/^coroutine "([^"]+)" downloads from peer "([^"]+)"$/',
            function(Context $ctx, string $coro, string $peer) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $peer) {
                    StandardSteps::ioDownload($ctx, $coro, $peer, 8192);
                });
            });

        // When coroutine "X" downloads from peer "EP" byte by byte
        // Same, but one byte per read — hammers the reactor's read path and
        // the partial-read reassembly far harder. A logic-chaos alternative
        // to the bulk download above.
        $r->on('/^coroutine "([^"]+)" downloads from peer "([^"]+)" byte by byte$/',
            function(Context $ctx, string $coro, string $peer) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $peer) {
                    StandardSteps::ioDownload($ctx, $coro, $peer, 1);
                });
            });

        // When coroutine "X" fetches peer "EP" over HTTP
        // Runs an async ext/curl GET against the evil HTTP peer. The body is
        // captured incrementally through CURLOPT_WRITEFUNCTION, so a truncated
        // or cancelled transfer still leaves the prefix that did arrive.
        // Cancellation-aware: a cancel mid-request lands in curl_get_cancelled.
        // The liveness invariant
        //   curl_get_ok + curl_get_cancelled + curl_get_failed
        //     + curl_get_no_peer == curl_get_attempts
        // therefore holds for every interleaving.
        $r->on('/^coroutine "([^"]+)" fetches peer "([^"]+)" over HTTP$/',
            function(Context $ctx, string $coro, string $peer) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $peer) {
                    StandardSteps::curlGet($ctx, $coro, $peer);
                });
            })
            ->requires('curl');

        // When coroutine "X" fetches peers "EP1","EP2",... via curl_multi
        // Builds one curl_multi handle, attaches one easy handle per named
        // peer, then runs the standard exec/select loop. The
        // curl_multi_select() call is where the reactor parks the coroutine
        // — that's the cancel surface and the same code path covered by
        // tests/curl/003 and 010 deterministically. Outcome family
        // curl_multi_*; per-handle outcomes are bumped via CURLMSG_DONE.
        $r->on('/^coroutine "([^"]+)" fetches peers "([^"]+(?:"\s*,\s*"[^"]+)*)" via curl_multi$/',
            function(Context $ctx, string $coro, string $peerList) {
                $peers = preg_split('/"\s*,\s*"/', $peerList);
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $peers) {
                    StandardSteps::curlMulti($ctx, $coro, $peers);
                });
            })
            ->requires('curl');

        // When coroutine "X" queries database "DB"
        // Runs a SELECT over the async PDO MySQL driver — connect + query I/O
        // go through the libuv reactor. The query reads the five seed rows
        // (ids 1..5), stable regardless of what transaction scenarios append.
        // Cancellation-aware; the liveness invariant
        //   db_query_ok + db_query_cancelled + db_query_failed
        //     + db_query_no_db == db_query_attempts
        // holds for every interleaving.
        $r->on('/^coroutine "([^"]+)" queries database "([^"]+)"$/',
            function(Context $ctx, string $coro, string $db) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $db) {
                    StandardSteps::dbRun($ctx, $coro, $db,
                        'SELECT id, label, n FROM items WHERE id <= 5 ORDER BY id', 'query');
                });
            })
            ->requires('toxiproxy');

        // When coroutine "X" runs a slow query on database "DB"
        // A ~2 s server-side sleep — keeps the coroutine parked in the reactor
        // on the DB socket long enough for a killer to cancel it or a
        // reset_peer toxic to land mid-query. The sleep SQL is driver-specific
        // (MySQL SLEEP() vs PostgreSQL pg_sleep()), resolved when the action
        // runs — by then the database's driver is known.
        $r->on('/^coroutine "([^"]+)" runs a slow query on database "([^"]+)"$/',
            function(Context $ctx, string $coro, string $db) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $db) {
                    $driver = $ctx->net->evilDbDefs[$db]['driver'] ?? 'mysql';
                    $sql = $driver === 'pgsql' ? 'SELECT pg_sleep(2)' : 'SELECT SLEEP(2)';
                    StandardSteps::dbRun($ctx, $coro, $db, $sql, 'slow_query');
                });
            })
            ->requires('toxiproxy');

        // When coroutine "X" runs a transaction on database "DB"
        // BEGIN → INSERT → COMMIT. A connection fault mid-transaction must
        // surface as a clean error and leave neither the connection nor the
        // pool slot wedged; the server rolls the transaction back on the
        // dropped connection.
        $r->on('/^coroutine "([^"]+)" runs a transaction on database "([^"]+)"$/',
            function(Context $ctx, string $coro, string $db) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $db) {
                    StandardSteps::dbTransaction($ctx, $coro, $db);
                });
            })
            ->requires('toxiproxy');

        // When coroutine "X" queries via mysqli "DB"
        // Same SELECT as the PDO query step, but over the mysqli extension —
        // connect + query I/O go through the libuv reactor. Liveness invariant
        //   mysqli_query_ok + cancelled + failed + no_db == mysqli_query_attempts.
        $r->on('/^coroutine "([^"]+)" queries via mysqli "([^"]+)"$/',
            function(Context $ctx, string $coro, string $db) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $db) {
                    StandardSteps::mysqliRun($ctx, $coro, $db,
                        'SELECT id, label, n FROM items WHERE id <= 5 ORDER BY id', 'query');
                });
            })
            ->requires('toxiproxy', 'mysqli', 'mysql-server');

        // When coroutine "X" runs a slow query via mysqli "DB"
        // SELECT SLEEP(2) over mysqli — parks the coroutine in the reactor on
        // the DB socket for a killer to cancel or a reset_peer toxic to hit.
        $r->on('/^coroutine "([^"]+)" runs a slow query via mysqli "([^"]+)"$/',
            function(Context $ctx, string $coro, string $db) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $db) {
                    StandardSteps::mysqliRun($ctx, $coro, $db, 'SELECT SLEEP(2)', 'slow_query');
                });
            })
            ->requires('toxiproxy', 'mysqli', 'mysql-server');

        // When coroutine "X" runs a transaction via mysqli "DB"
        // begin_transaction → prepared INSERT → commit over mysqli.
        $r->on('/^coroutine "([^"]+)" runs a transaction via mysqli "([^"]+)"$/',
            function(Context $ctx, string $coro, string $db) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $db) {
                    StandardSteps::mysqliTransaction($ctx, $coro, $db);
                });
            })
            ->requires('toxiproxy', 'mysqli', 'mysql-server');

        // When coroutine "X" uploads N bytes to peer "EP"
        // Connects and writes N bytes in a single fwrite(). Against a slow or
        // never-reading consume-mode peer that fwrite() suspends on a full
        // send buffer. Cancellation-aware: a cancel mid-write lands in
        // io_upload_cancelled with the partial byte count preserved. The
        // liveness invariant
        //   io_upload_ok + io_upload_cancelled + io_upload_failed
        //     + io_upload_connect_failed + io_upload_no_peer == attempts
        // therefore holds for every interleaving.
        $r->on('/^coroutine "([^"]+)" uploads (\S+) bytes to peer "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $peer) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $peer, $n) {
                    StandardSteps::ioUpload($ctx, $coro, $peer, $n, $n);
                });
            });

        // When coroutine "X" uploads N bytes to peer "EP" in M-byte writes
        // Same, but chunked into M-byte fwrite() calls — a logic-chaos
        // alternative that crosses the write-path with different call shapes.
        $r->on('/^coroutine "([^"]+)" uploads (\S+) bytes to peer "([^"]+)" in (\S+)-byte writes$/',
            function(Context $ctx, string $coro, string $nExpr, string $peer, string $mExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $m = (int)$ctx->resolver->resolve($mExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $peer, $n, $m) {
                    StandardSteps::ioUpload($ctx, $coro, $peer, $n, $m > 0 ? $m : $n);
                });
            });

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

        // Given a shared pipe "P"
        // Creates a stream_socket_pair stored on the Context — both ends
        // outlive any one coroutine, so a reader / writer / closer can be
        // distributed across coroutines. Pair is created synchronously at
        // step-eval time so reader and closer coroutines see the same fds.
        // Cleanup is best-effort in Context::run() teardown.
        $r->on('/^a shared pipe "([^"]+)"$/',
            function(Context $ctx, string $name) {
                if (isset($ctx->pipes[$name])) {
                    return; // idempotent
                }
                $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if ($pair === false) {
                    throw new \RuntimeException("shared pipe $name: stream_socket_pair failed");
                }
                $ctx->pipes[$name] = $pair;
            })
            ->requires('unix-sockets');

        // When coroutine "X" reads from shared pipe "P"
        // Blocks on fread() of the shared pair's reader. Distinct counter
        // family (io_pread_*) so concurrent readers / cross-coro close paths
        // don't collide with the local-pipe step's io_read_* counters.
        $r->on('/^coroutine "([^"]+)" reads from shared pipe "([^"]+)"$/',
            function(Context $ctx, string $coro, string $pipe) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $pipe) {
                    if (!isset($ctx->pipes[$pipe])) {
                        $ctx->inc("io_pread_setup_failed_$coro");
                        return;
                    }
                    [$reader,] = $ctx->pipes[$pipe];
                    try {
                        $ctx->inc("io_pread_attempts_$coro");
                        $data = @fread($reader, 4096); /* blocks */
                        if ($data === false || $data === '') {
                            $ctx->inc("io_pread_eof_$coro");
                        } else {
                            $ctx->inc("io_pread_ok_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("io_pread_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("io_pread_failed_$coro");
                    }
                });
            })
            ->requires('unix-sockets');

        // When coroutine "X" writes "<msg>" to shared pipe "P"
        // Pushes a bounded payload into the shared writer end. Used to wake
        // readers in the concurrent-readers scenarios. Counter family
        // io_pwrite_*.
        $r->on('/^coroutine "([^"]+)" writes "([^"]*)" to shared pipe "([^"]+)"$/',
            function(Context $ctx, string $coro, string $payload, string $pipe) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $pipe, $payload) {
                    if (!isset($ctx->pipes[$pipe])) {
                        $ctx->inc("io_pwrite_setup_failed_$coro");
                        return;
                    }
                    [, $writer] = $ctx->pipes[$pipe];
                    try {
                        $ctx->inc("io_pwrite_attempts_$coro");
                        $n = @fwrite($writer, $payload);
                        if ($n === false || $n === 0) {
                            $ctx->inc("io_pwrite_failed_$coro");
                        } else {
                            $ctx->inc("io_pwrite_ok_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("io_pwrite_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("io_pwrite_failed_$coro");
                    }
                });
            })
            ->requires('unix-sockets');

        // When coroutine "X" closes shared pipe "P"
        // fclose() on both ends of the shared pair from inside a coroutine.
        // Race target: a parked reader on this same pair must be released by
        // the reactor without UAF / leaked watcher. Tolerates a peer that has
        // already closed one side. Counter io_pclose_*.
        $r->on('/^coroutine "([^"]+)" closes shared pipe "([^"]+)"$/',
            function(Context $ctx, string $coro, string $pipe) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $pipe) {
                    if (!isset($ctx->pipes[$pipe])) {
                        $ctx->inc("io_pclose_setup_failed_$coro");
                        return;
                    }
                    $ctx->inc("io_pclose_attempts_$coro");
                    [$reader, $writer] = $ctx->pipes[$pipe];
                    if (is_resource($writer)) @fclose($writer);
                    if (is_resource($reader)) @fclose($reader);
                    $ctx->inc("io_pclose_ok_$coro");
                });
            })
            ->requires('unix-sockets');

        // When coroutine "X" closes the read end of shared pipe "P"
        // Closes only the reader. The parked fread() on that fd must wake —
        // not the writer half — and the reactor request list must stay
        // consistent.
        $r->on('/^coroutine "([^"]+)" closes the read end of shared pipe "([^"]+)"$/',
            function(Context $ctx, string $coro, string $pipe) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $pipe) {
                    if (!isset($ctx->pipes[$pipe])) {
                        $ctx->inc("io_pclose_setup_failed_$coro");
                        return;
                    }
                    $ctx->inc("io_pclose_attempts_$coro");
                    [$reader,] = $ctx->pipes[$pipe];
                    if (is_resource($reader)) @fclose($reader);
                    $ctx->inc("io_pclose_ok_$coro");
                });
            })
            ->requires('unix-sockets');

        // When coroutine "X" closes the write end of shared pipe "P"
        // Closes only the writer. Parked readers must observe EOF (clean
        // fread() == "") rather than hang.
        $r->on('/^coroutine "([^"]+)" closes the write end of shared pipe "([^"]+)"$/',
            function(Context $ctx, string $coro, string $pipe) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $pipe) {
                    if (!isset($ctx->pipes[$pipe])) {
                        $ctx->inc("io_pclose_setup_failed_$coro");
                        return;
                    }
                    $ctx->inc("io_pclose_attempts_$coro");
                    [, $writer] = $ctx->pipes[$pipe];
                    if (is_resource($writer)) @fclose($writer);
                    $ctx->inc("io_pclose_ok_$coro");
                });
            })
            ->requires('unix-sockets');

        // When coroutine "X" socket_connects to TCP blackhole "ADDR"
        // ext/sockets API: socket_create + socket_connect to a routable
        // but unreachable address (RFC 5737 TEST-NET-1 by convention).
        // Goes through xp_socket.c connect-watcher just like the streams
        // version, but exercises the ext/sockets entry point. Outcome
        // family sock_connect_*.
        $r->on('/^coroutine "([^"]+)" socket_connects to TCP blackhole "([^"]+)"$/',
            function(Context $ctx, string $coro, string $addr) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $addr) {
                    [$host, $port] = explode(':', $addr, 2);
                    $ctx->inc("sock_connect_attempts_$coro");
                    $s = null;
                    try {
                        $s = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                        if (!$s) {
                            $ctx->inc("sock_connect_failed_$coro");
                            return;
                        }
                        if (@socket_connect($s, $host, (int)$port)) {
                            $ctx->inc("sock_connect_ok_$coro");
                        } else {
                            $ctx->inc("sock_connect_failed_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("sock_connect_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("sock_connect_failed_$coro");
                    } finally {
                        if ($s !== null && $s !== false) {
                            @socket_close($s);
                        }
                    }
                });
            })
            ->requires('sockets', 'tcp-blackhole');

        // When coroutine "X" socket_recvfroms on a fresh UDP socket
        // Bind ephemeral UDP socket, park in socket_recvfrom. Outcome
        // family sock_recv_*.
        $r->on('/^coroutine "([^"]+)" socket_recvfroms on a fresh UDP socket$/',
            function(Context $ctx, string $coro) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro) {
                    $ctx->inc("sock_recv_attempts_$coro");
                    $s = null;
                    try {
                        $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                        if (!$s || !@socket_bind($s, '127.0.0.1', 0)) {
                            $ctx->inc("sock_recv_failed_$coro");
                            return;
                        }
                        $buf = '';
                        $from = '';
                        $fromPort = 0;
                        $n = @socket_recvfrom($s, $buf, 4096, 0, $from, $fromPort);
                        if ($n === false) {
                            $ctx->inc("sock_recv_failed_$coro");
                        } else {
                            $ctx->inc("sock_recv_ok_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("sock_recv_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("sock_recv_failed_$coro");
                    } finally {
                        if ($s !== null && $s !== false) {
                            @socket_close($s);
                        }
                    }
                });
            })
            ->requires('sockets');

        // When coroutine "X" socket_accepts on a fresh TCP listener
        // Bind ephemeral TCP listener via ext/sockets, park in socket_accept.
        // Outcome family sock_accept_*.
        $r->on('/^coroutine "([^"]+)" socket_accepts on a fresh TCP listener$/',
            function(Context $ctx, string $coro) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro) {
                    $ctx->inc("sock_accept_attempts_$coro");
                    $s = null;
                    try {
                        $s = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                        if (!$s
                            || !@socket_bind($s, '127.0.0.1', 0)
                            || !@socket_listen($s, 8)) {
                            $ctx->inc("sock_accept_failed_$coro");
                            return;
                        }
                        $c = @socket_accept($s);
                        if ($c === false) {
                            $ctx->inc("sock_accept_failed_$coro");
                        } else {
                            $ctx->inc("sock_accept_ok_$coro");
                            @socket_close($c);
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("sock_accept_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("sock_accept_failed_$coro");
                    } finally {
                        if ($s !== null && $s !== false) {
                            @socket_close($s);
                        }
                    }
                });
            })
            ->requires('sockets');

        // Given a shared lock file "L"
        // Creates a fresh tempfile and stashes its path on
        // Context::$lockFiles. Each coroutine opens its OWN fd to that
        // path so flock() sees cross-fd contention (LOCK_EX is per-fd
        // under POSIX fcntl/flock semantics). Tempfile is unlinked in
        // Context::__destruct().
        $r->on('/^a shared lock file "([^"]+)"$/',
            function(Context $ctx, string $name) {
                if (isset($ctx->lockFiles[$name])) return;
                $path = tempnam(sys_get_temp_dir(), 'fuzzy_flock_');
                if ($path === false) {
                    throw new \RuntimeException("shared lock file $name: tempnam failed");
                }
                $ctx->lockFiles[$name] = $path;
            })
            ->requires('unix-sockets'); // POSIX flock — reuse the unix-sockets skip on Windows

        // When coroutine "X" acquires LOCK_EX on "L" then releases after N ms
        // Opens a fresh fd, takes LOCK_EX (blocking — the reactor parks
        // the coroutine on the flock thread-pool worker), holds for N ms,
        // unlocks, closes. Counter family:
        //   flock_attempts — once per coroutine
        //   flock_ok       — lock acquired and released cleanly
        //   flock_cancelled — AsyncCancellation delivered during the wait
        //   flock_failed   — any other throwable / flock() returned false
        // Sum holds for every interleaving.
        $r->on('/^coroutine "([^"]+)" acquires LOCK_EX on "([^"]+)" then releases after (\S+) ms$/',
            function(Context $ctx, string $coro, string $lock, string $msExpr) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $lock, $msExpr) {
                    if (!isset($ctx->lockFiles[$lock])) {
                        $ctx->inc("flock_setup_failed_$coro");
                        return;
                    }
                    $path = $ctx->lockFiles[$lock];
                    $ms   = (int)$ctx->resolver->resolve($msExpr);
                    $ctx->inc("flock_attempts_$coro");
                    $fp = null;
                    try {
                        $fp = @fopen($path, 'r');
                        if (!$fp) {
                            $ctx->inc("flock_failed_$coro");
                            return;
                        }
                        if (!@flock($fp, LOCK_EX)) {
                            $ctx->inc("flock_failed_$coro");
                            return;
                        }
                        \Async\delay($ms);
                        @flock($fp, LOCK_UN);
                        $ctx->inc("flock_ok_$coro");
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("flock_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("flock_failed_$coro");
                    } finally {
                        if (is_resource($fp)) {
                            @flock($fp, LOCK_UN);
                            @fclose($fp);
                        }
                    }
                });
            })
            ->requires('unix-sockets');

        // When coroutine "X" drains shared pipe "P" with feof loop
        // Canonical `while (!feof) fread` drain — reads the reader end of
        // the shared pipe in 4096-byte chunks until feof() reports true.
        // Counters: feof_drain_attempts (once), feof_drain_done (clean
        // exit), feof_drain_cancelled, feof_drain_failed, feof_drain_bytes
        // (total bytes received before feof / cancel). Sums hold for any
        // interleaving: done+cancelled+failed == attempts.
        $r->on('/^coroutine "([^"]+)" drains shared pipe "([^"]+)" with feof loop$/',
            function(Context $ctx, string $coro, string $pipe) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $pipe) {
                    if (!isset($ctx->pipes[$pipe])) {
                        $ctx->inc("feof_drain_setup_failed_$coro");
                        return;
                    }
                    [$reader,] = $ctx->pipes[$pipe];
                    $ctx->inc("feof_drain_attempts_$coro");
                    $bytes = 0;
                    try {
                        while (is_resource($reader) && !@feof($reader)) {
                            $chunk = @fread($reader, 4096);
                            if ($chunk === false) break;
                            $bytes += strlen($chunk);
                            if ($chunk === '' && @feof($reader)) break;
                        }
                        $ctx->inc("feof_drain_done_$coro");
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("feof_drain_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("feof_drain_failed_$coro");
                    } finally {
                        $ctx->inc("feof_drain_bytes_$coro", $bytes);
                    }
                });
            })
            ->requires('unix-sockets');

        // When coroutine "X" polls feof of shared pipe "P" N times
        // Spams feof() against the reader end of the shared pipe from a
        // sibling coroutine context. Yields via Async\suspend() between
        // polls so other coroutines interleave. The feof_poll_true /
        // _false split is informational; the invariant is that
        // poll_true + poll_false == attempts.
        $r->on('/^coroutine "([^"]+)" polls feof of shared pipe "([^"]+)" (\S+) times$/',
            function(Context $ctx, string $coro, string $pipe, string $nExpr) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $pipe, $nExpr) {
                    if (!isset($ctx->pipes[$pipe])) {
                        $ctx->inc("feof_poll_setup_failed_$coro");
                        return;
                    }
                    [$reader,] = $ctx->pipes[$pipe];
                    $n = (int)$ctx->resolver->resolve($nExpr);
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("feof_poll_attempts_$coro");
                        if (!is_resource($reader)) {
                            $ctx->inc("feof_poll_failed_$coro");
                            continue;
                        }
                        if (@feof($reader)) {
                            $ctx->inc("feof_poll_true_$coro");
                        } else {
                            $ctx->inc("feof_poll_false_$coro");
                        }
                        \Async\suspend();
                    }
                });
            })
            ->requires('unix-sockets');

        // Given a shared file "F"
        // Opens a fresh tmp file in write mode and stashes [handle, path]
        // on the Context — many coroutines write the SAME $handle so they
        // park on its single shared async-IO event. That's exactly the
        // spurious-wakeup race that #129 / #133 fixed (php_stdiop_write
        // must re-suspend until its OWN request completed; otherwise a
        // libuv write in flight is disposed and writes into freed memory
        // → bytes silently lost or heap corruption).
        $r->on('/^a shared file "([^"]+)"$/',
            function(Context $ctx, string $name) {
                if (isset($ctx->files[$name])) {
                    return; // idempotent
                }
                $path = tempnam(sys_get_temp_dir(), 'fuzzy_sf_');
                $fh   = @fopen($path, 'w');
                if (!$fh) {
                    throw new \RuntimeException("shared file $name: fopen failed");
                }
                $ctx->files[$name] = [$fh, $path];
            });

        // When coroutine "X" writes N chunks of M bytes to shared file "F"
        // Each chunk is a fixed-size deterministic payload (per-coroutine
        // letter repeated). Counters per coroutine:
        //   io_fwrite_attempts — bumped once per fwrite() call
        //   io_fwrite_ok       — full-size chunk written (fwrite() == M)
        //   io_fwrite_short    — short write (0 < returned < M)
        //   io_fwrite_failed   — fwrite returned false / 0
        //   io_fwrite_bytes    — total bytes the coroutine got past fwrite
        // The cross-coroutine invariant — sum(io_fwrite_bytes_*) ==
        // filesize(path) — is the #129/#133 backstop: under the spurious-
        // wakeup bug, writes are silently dropped and the file ends up
        // smaller than the bytes everyone "successfully" wrote.
        $r->on('/^coroutine "([^"]+)" writes (\S+) chunks of (\S+) bytes to shared file "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $mExpr, string $name) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $m = (int)$ctx->resolver->resolve($mExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $n, $m, $name) {
                    if (!isset($ctx->files[$name])) {
                        $ctx->inc("io_fwrite_setup_failed_$coro");
                        return;
                    }
                    [$fh,] = $ctx->files[$name];
                    // Deterministic per-coroutine payload — first char of
                    // $coro repeated, so each chunk is uniquely attributable
                    // when debugging a corrupted file.
                    $payload = str_repeat($coro[0] ?? 'X', $m);
                    for ($i = 0; $i < $n; $i++) {
                        try {
                            $ctx->inc("io_fwrite_attempts_$coro");
                            $w = @fwrite($fh, $payload);
                            if ($w === false || $w === 0) {
                                $ctx->inc("io_fwrite_failed_$coro");
                                return; // handle bad — stop
                            }
                            $ctx->inc("io_fwrite_bytes_$coro", $w);
                            if ($w === $m) {
                                $ctx->inc("io_fwrite_ok_$coro");
                            } else {
                                $ctx->inc("io_fwrite_short_$coro");
                            }
                        } catch (\Async\AsyncCancellation $e) {
                            $ctx->inc("io_fwrite_cancelled_$coro");
                            return;
                        } catch (\Throwable $e) {
                            $ctx->inc("io_fwrite_failed_$coro");
                            return;
                        }
                    }
                });
            });

        // When coroutine "X" closes shared file "F"
        // Drives an fclose() from inside a coroutine — typically chained
        // after all writers have completed (sequenced via sleeps in the
        // feature). The handle is also closed in teardown; this step
        // exists for scenarios that need a clean fclose before the file-
        // size assertion runs.
        $r->on('/^coroutine "([^"]+)" closes shared file "([^"]+)"$/',
            function(Context $ctx, string $coro, string $name) {
                $ctx->planAction($coro, function(Context $ctx) use ($name) {
                    if (!isset($ctx->files[$name])) return;
                    [$fh, $path] = $ctx->files[$name];
                    if (is_resource($fh)) @fclose($fh);
                    $ctx->files[$name] = [null, $path];
                });
            });

        // Then shared file "F" byte size equals counter "A" plus counter "B"
        // ... plus counter "N" — variadic sum invariant. The point is to
        // assert that the kernel-observed file size matches the sum of
        // bytes every coroutine claims fwrite() accepted. If they diverge,
        // the spurious-wakeup bug ate someone's write.
        $r->on('/^shared file "([^"]+)" byte size equals counter "([^"]+)"((?: plus counter "[^"]+")+)$/',
            function(Context $ctx, string $name, string $first, string $rest) {
                if (!isset($ctx->files[$name])) {
                    throw new \RuntimeException("shared file $name not defined");
                }
                [$fh, $path] = $ctx->files[$name];
                if (is_resource($fh)) @fflush($fh);
                clearstatcache(true, $path);
                $actual = (int)@filesize($path);
                $sum = $ctx->counter($first);
                preg_match_all('/counter "([^"]+)"/', $rest, $m);
                $names = [$first];
                foreach ($m[1] as $cn) { $sum += $ctx->counter($cn); $names[] = $cn; }
                if ($actual !== $sum) {
                    throw new \RuntimeException(
                        "shared file $name byte size = $actual, sum of " .
                        implode('+', $names) . " = $sum (data loss)");
                }
            });

        // When coroutine "X" resolves nonexistent hostname "H" with timeout N ms
        // stream_socket_client('tcp://H:80') against an .invalid hostname
        // drives the async getaddrinfo path (php_network_getaddrinfo_async,
        // libuv worker thread). The hostname always NXDOMAINs — so the
        // dominant outcome with no cancel is dns_failed. The chaos value
        // is the cancel-during-async-resolve path: AsyncCancellation
        // must release the DNS request without leaking the libuv work
        // handle. tcp-blackhole-style sync paths don't apply: by the
        // time DNS returns, no connect is attempted.
        //
        // Outcomes (exactly one per attempt):
        //   dns_ok        — surprise success (host briefly resolved on a
        //                   misconfigured resolver; tolerated)
        //   dns_failed    — NXDOMAIN / other resolver error returned
        //   dns_cancelled — AsyncCancellation injected during resolve
        //   dns_timeout   — explicit timeout fired (rare; .invalid is
        //                   usually fast-NXDOMAIN past the probe gate)
        $r->on('/^coroutine "([^"]+)" resolves nonexistent hostname "([^"]+)" with timeout (\S+) ms$/',
            function(Context $ctx, string $coro, string $host, string $msExpr) {
                $ms = (int)$ctx->resolver->resolve($msExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $host, $ms) {
                    $ctx->inc("dns_attempts_$coro");
                    $sock = null;
                    $sec  = $ms / 1000.0;
                    try {
                        $sock = @stream_socket_client(
                            'tcp://' . $host . ':80', $errno, $errstr, $sec);
                        if ($sock !== false) {
                            $ctx->inc("dns_ok_$coro");
                        } elseif (stripos((string)$errstr, 'timed out') !== false
                                || stripos((string)$errstr, 'timeout') !== false) {
                            $ctx->inc("dns_timeout_$coro");
                        } else {
                            $ctx->inc("dns_failed_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("dns_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("dns_failed_$coro");
                    } finally {
                        if (is_resource($sock)) @fclose($sock);
                    }
                });
            })
            ->requires('tcp', 'dns-async-engages');

        // Given a long-lived child process "C" sleeping N ms
        // proc_open()s the test PHP binary running a sleep loop on stdout-
        // open-but-silent. The child stdout pipe stays open for the parked
        // fread() reader — no data ever arrives during the wait window, so
        // the reader is guaranteed to be in the reactor's pipe-poll at the
        // moment the killer fires. Pipes + proc handle are stashed on
        // Context::$processes; teardown @proc_terminate+@proc_close.
        //
        // The "<ms> ms" is the child's wall-clock budget; the chaos window
        // (killer sleeps + race surface) must comfortably fit inside it.
        $r->on('/^a long-lived child process "([^"]+)" sleeping (\S+) ms$/',
            function(Context $ctx, string $name, string $msExpr) {
                if (isset($ctx->processes[$name])) return; // idempotent
                $php = getenv('TEST_PHP_EXECUTABLE');
                if ($php === false) {
                    throw new \RuntimeException(
                        "child process $name: TEST_PHP_EXECUTABLE not set");
                }
                $ms   = (int)$ctx->resolver->resolve($msExpr);
                $code = 'usleep(' . ($ms * 1000) . ');';
                $proc = @proc_open(
                    [$php, '-r', $code],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes);
                if (!is_resource($proc)) {
                    throw new \RuntimeException(
                        "child process $name: proc_open failed");
                }
                // Non-blocking writes; reads stay default (blocking → reactor).
                @stream_set_blocking($pipes[0], false);
                $ctx->processes[$name] = ['proc' => $proc, 'pipes' => $pipes];
            })
            ->requires('proc-open');

        // When coroutine "X" reads stdout of child process "C"
        // Parks on fread() of the child's stdout pipe. Outcome family
        // proc_read_*: ok / eof / cancelled / failed sum to attempts.
        $r->on('/^coroutine "([^"]+)" reads stdout of child process "([^"]+)"$/',
            function(Context $ctx, string $coro, string $proc) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $proc) {
                    if (!isset($ctx->processes[$proc])) {
                        $ctx->inc("proc_read_setup_failed_$coro");
                        return;
                    }
                    $stdout = $ctx->processes[$proc]['pipes'][1] ?? null;
                    if (!is_resource($stdout)) {
                        $ctx->inc("proc_read_setup_failed_$coro");
                        return;
                    }
                    try {
                        $ctx->inc("proc_read_attempts_$coro");
                        $data = @fread($stdout, 4096); /* blocks via reactor */
                        if ($data === false || $data === '') {
                            $ctx->inc("proc_read_eof_$coro");
                        } else {
                            $ctx->inc("proc_read_ok_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("proc_read_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("proc_read_failed_$coro");
                    }
                });
            })
            ->requires('proc-open');

        // When coroutine "X" closes child process "C"
        // proc_terminate(SIGTERM) + proc_close to reap. We deliberately
        // do NOT fclose() the pipes here: a parked reader on stdout owns
        // the same stream resource and closing it from a sibling
        // coroutine is the user-error / UAF path that
        // stream_close_during_read.feature documents as out-of-scope
        // pending php-async#130. Killing the child causes the kernel to
        // close the child's stdout end; the parked fread() returns ""
        // (EOF). Pipes are dropped in Context::run() teardown.
        // Race target for the libuv process_event release path
        // (tests/exec/011-proc_open_handle_reuse_uaf).
        $r->on('/^coroutine "([^"]+)" closes child process "([^"]+)"$/',
            function(Context $ctx, string $coro, string $proc) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $proc) {
                    if (!isset($ctx->processes[$proc])) {
                        $ctx->inc("proc_close_setup_failed_$coro");
                        return;
                    }
                    $ctx->inc("proc_close_attempts_$coro");
                    $entry = &$ctx->processes[$proc];
                    if (is_resource($entry['proc'])) {
                        @proc_terminate($entry['proc'], 15);
                        @proc_close($entry['proc']);
                        $entry['proc'] = null;
                    }
                    unset($entry);
                    $ctx->inc("proc_close_ok_$coro");
                });
            })
            ->requires('proc-open');

        // When coroutine "X" sends SIGTERM to child process "C"
        // proc_terminate(SIGTERM). The child exits, kernel closes its end
        // of the stdout pipe, and a parked reader gets EOF. Does NOT close
        // the handle — that's the killer step's job, kept separate so
        // scenarios can interleave terminate/close ordering.
        $r->on('/^coroutine "([^"]+)" sends SIGTERM to child process "([^"]+)"$/',
            function(Context $ctx, string $coro, string $proc) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $proc) {
                    if (!isset($ctx->processes[$proc])) {
                        $ctx->inc("proc_term_setup_failed_$coro");
                        return;
                    }
                    $ctx->inc("proc_term_attempts_$coro");
                    $p = $ctx->processes[$proc]['proc'] ?? null;
                    if (is_resource($p) && @proc_terminate($p, 15)) {
                        $ctx->inc("proc_term_ok_$coro");
                    } else {
                        $ctx->inc("proc_term_failed_$coro");
                    }
                });
            })
            ->requires('proc-open');

        // When coroutine "X" runs proc_open + proc_close N times
        // Rapid open/close storm — backstop for the UAF in
        // tests/exec/011-proc_open_handle_reuse_uaf. Each iteration spawns
        // a short-lived child (exit(0);), closes its pipes, proc_close()s,
        // and yields via Async\suspend() so the reactor's process-event
        // dispose path runs interleaved with peer coroutines doing the
        // same. Counter family proc_storm_*.
        $r->on('/^coroutine "([^"]+)" runs proc_open \+ proc_close (\S+) times$/',
            function(Context $ctx, string $coro, string $nExpr) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $nExpr) {
                    $php = getenv('TEST_PHP_EXECUTABLE');
                    if ($php === false) {
                        $ctx->inc("proc_storm_setup_failed_$coro");
                        return;
                    }
                    $n = (int)$ctx->resolver->resolve($nExpr);
                    for ($i = 0; $i < $n; $i++) {
                        $ctx->inc("proc_storm_attempts_$coro");
                        try {
                            $p = @proc_open(
                                [$php, '-r', 'exit(0);'],
                                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                                $pipes);
                            if (!is_resource($p)) {
                                $ctx->inc("proc_storm_failed_$coro");
                                continue;
                            }
                            foreach ($pipes as $fd) {
                                if (is_resource($fd)) @fclose($fd);
                            }
                            @proc_close($p);
                            $ctx->inc("proc_storm_ok_$coro");
                            \Async\suspend();
                        } catch (\Async\AsyncCancellation $e) {
                            $ctx->inc("proc_storm_cancelled_$coro");
                            return;
                        } catch (\Throwable $e) {
                            $ctx->inc("proc_storm_failed_$coro");
                        }
                    }
                });
            })
            ->requires('proc-open');

        // Given a filesystem watcher "W" on a fresh temp directory
        // Creates an empty tmpdir and an Async\FileSystemWatcher on it
        // (recursive=false, coalesce=true — the default coalesce mode).
        // Stashes both on Context::$fsWatchers. The watcher is
        // constructed synchronously so iterator coroutines see it
        // ready in their planned action.
        $r->on('/^a filesystem watcher "([^"]+)" on a fresh temp directory$/',
            function(Context $ctx, string $name) {
                if (isset($ctx->fsWatchers[$name])) return;
                $dir = sys_get_temp_dir() . '/fuzzy_fsw_' . getmypid() . '_' . bin2hex(random_bytes(4));
                if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                    throw new \RuntimeException("fs watcher $name: mkdir failed: $dir");
                }
                $watcher = new \Async\FileSystemWatcher($dir);
                $ctx->fsWatchers[$name] = ['watcher' => $watcher, 'dir' => $dir];
            });

        // When coroutine "X" iterates filesystem watcher "W"
        // foreach loop — counts every FileSystemEvent until the watcher
        // is closed by another coroutine (or until the iteration is
        // cancelled). Counters per coroutine: fsw_iter_attempts,
        // fsw_events (count of events seen), fsw_iter_done (clean exit),
        // fsw_iter_cancelled, fsw_iter_failed.
        $r->on('/^coroutine "([^"]+)" iterates filesystem watcher "([^"]+)"$/',
            function(Context $ctx, string $coro, string $name) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $name) {
                    if (!isset($ctx->fsWatchers[$name])
                        || !is_object($ctx->fsWatchers[$name]['watcher'])) {
                        $ctx->inc("fsw_iter_setup_failed_$coro");
                        return;
                    }
                    $w = $ctx->fsWatchers[$name]['watcher'];
                    $ctx->inc("fsw_iter_attempts_$coro");
                    try {
                        foreach ($w as $event) {
                            $ctx->inc("fsw_events_$coro");
                            // Sanity: every event is a FileSystemEvent
                            // with at least one flag set. A regression
                            // here would corrupt the event count.
                            if (!($event instanceof \Async\FileSystemEvent)
                                || !($event->renamed || $event->changed)) {
                                $ctx->inc("fsw_bad_event_$coro");
                            }
                        }
                        $ctx->inc("fsw_iter_done_$coro");
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("fsw_iter_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("fsw_iter_failed_$coro");
                    }
                });
            });

        // When coroutine "X" touches file "F" in filesystem watcher "W" directory
        // file_put_contents into the watched dir — should produce a
        // FileSystemEvent for the iterator. Counter fsw_touch_*.
        $r->on('/^coroutine "([^"]+)" touches file "([^"]+)" in filesystem watcher "([^"]+)" directory$/',
            function(Context $ctx, string $coro, string $file, string $name) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $file, $name) {
                    if (!isset($ctx->fsWatchers[$name])) {
                        $ctx->inc("fsw_touch_setup_failed_$coro");
                        return;
                    }
                    $dir = $ctx->fsWatchers[$name]['dir'];
                    $ctx->inc("fsw_touch_attempts_$coro");
                    if (@file_put_contents($dir . '/' . $file, 'x') !== false) {
                        $ctx->inc("fsw_touch_ok_$coro");
                    } else {
                        $ctx->inc("fsw_touch_failed_$coro");
                    }
                });
            });

        // When coroutine "X" closes filesystem watcher "W"
        // Idempotent close(). The iterator coroutine's foreach must
        // exit cleanly on close — release path lives in fs_watcher.c.
        $r->on('/^coroutine "([^"]+)" closes filesystem watcher "([^"]+)"$/',
            function(Context $ctx, string $coro, string $name) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $name) {
                    if (!isset($ctx->fsWatchers[$name])) return;
                    $w = $ctx->fsWatchers[$name]['watcher'];
                    if (is_object($w) && !$w->isClosed()) {
                        try { $w->close(); } catch (\Throwable $e) {}
                    }
                });
            });

        // Given a UDP endpoint "U"
        // Binds an ephemeral UDP socket on 127.0.0.1 and stashes
        // [socket, "127.0.0.1:port"] on Context::$udpEndpoints. UDP is
        // connectionless, so the same socket is used both for recvfrom
        // (by a receiver coroutine) and as the destination address by
        // sender coroutines.
        $r->on('/^a UDP endpoint "([^"]+)"$/',
            function(Context $ctx, string $name) {
                if (isset($ctx->udpEndpoints[$name])) return;
                $sock = @stream_socket_server(
                    'udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
                if (!$sock) {
                    throw new \RuntimeException(
                        "UDP endpoint $name bind failed: $errstr ($errno)");
                }
                $addr = stream_socket_get_name($sock, false);
                $ctx->udpEndpoints[$name] = ['sock' => $sock, 'addr' => $addr];
            })
            ->requires('tcp'); // any IP socket capability — UDP loopback is portable

        // When coroutine "X" recvs from UDP endpoint "U"
        // Blocking stream_socket_recvfrom on the bound socket; suspends
        // in the reactor's sock_async_poll(PHP_POLLREADABLE). Counters:
        //   udp_recv_attempts / udp_recv_ok / udp_recv_cancelled / udp_recv_failed.
        // Buffer is 1500 (typical MTU); peer address is recorded but
        // not asserted on (loopback ephemeral port varies).
        $r->on('/^coroutine "([^"]+)" recvs from UDP endpoint "([^"]+)"$/',
            function(Context $ctx, string $coro, string $name) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $name) {
                    if (!isset($ctx->udpEndpoints[$name])) {
                        $ctx->inc("udp_recv_setup_failed_$coro");
                        return;
                    }
                    $sock = $ctx->udpEndpoints[$name]['sock'];
                    $ctx->inc("udp_recv_attempts_$coro");
                    try {
                        $peer = '';
                        $data = @stream_socket_recvfrom($sock, 1500, 0, $peer);
                        if ($data === false || $data === '') {
                            $ctx->inc("udp_recv_failed_$coro");
                        } else {
                            $ctx->inc("udp_recv_ok_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("udp_recv_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("udp_recv_failed_$coro");
                    }
                });
            })
            ->requires('tcp');

        // When coroutine "X" sends "PAYLOAD" to UDP endpoint "U"
        // Opens a separate udp client socket and stream_socket_sendto's
        // the payload. Fire-and-forget — UDP has no ack. Counters:
        //   udp_send_attempts / udp_send_ok / udp_send_failed.
        $r->on('/^coroutine "([^"]+)" sends "([^"]*)" to UDP endpoint "([^"]+)"$/',
            function(Context $ctx, string $coro, string $payload, string $name) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $payload, $name) {
                    if (!isset($ctx->udpEndpoints[$name])) {
                        $ctx->inc("udp_send_setup_failed_$coro");
                        return;
                    }
                    $addr = $ctx->udpEndpoints[$name]['addr'];
                    $ctx->inc("udp_send_attempts_$coro");
                    $client = @stream_socket_client(
                        'udp://' . $addr, $errno, $errstr);
                    if (!$client) {
                        $ctx->inc("udp_send_failed_$coro");
                        return;
                    }
                    try {
                        $n = @stream_socket_sendto($client, $payload);
                        if ($n === strlen($payload)) {
                            $ctx->inc("udp_send_ok_$coro");
                        } else {
                            $ctx->inc("udp_send_failed_$coro");
                        }
                    } catch (\Throwable $e) {
                        $ctx->inc("udp_send_failed_$coro");
                    } finally {
                        if (is_resource($client)) @fclose($client);
                    }
                });
            })
            ->requires('tcp');

        // When coroutine "X" stream_selects on shared pipes "P1","P2",... for N ms
        // Calls stream_select() on the read ends of the named shared pipes
        // with $tv = N/1000.0 seconds. Drives the reactor's
        // network_async_stream_select() (a multi-fd poll watcher). Counters:
        //   select_attempts_$coro — bumped before the call
        //   select_woke_$coro     — stream_select returned > 0 (≥1 fd ready)
        //   select_timeout_$coro  — returned 0 (tv elapsed, no fd ready)
        //   select_cancelled_$coro — AsyncCancellation injected mid-wait
        //   select_failed_$coro   — false / other error
        // The watcher must be released cleanly on cancel/timeout — a leaked
        // multi-fd poll handle would surface as orphan-coroutine / abrupt-
        // exit failures on subsequent scenarios.
        $r->on('/^coroutine "([^"]+)" stream_selects on shared pipes "([^"]+(?:"\s*,\s*"[^"]+)*)" for (\S+) ms$/',
            function(Context $ctx, string $coro, string $pipesList, string $msExpr) {
                $ms = (int)$ctx->resolver->resolve($msExpr);
                preg_match_all('/"([^"]+)"/', '"' . $pipesList . '"', $m);
                $pipeNames = $m[1];
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $pipeNames, $ms) {
                    $ctx->inc("select_attempts_$coro");
                    $read = [];
                    foreach ($pipeNames as $pn) {
                        if (!isset($ctx->pipes[$pn])) {
                            $ctx->inc("select_setup_failed_$coro");
                            return;
                        }
                        $read[] = $ctx->pipes[$pn][0]; // reader end
                    }
                    $write = $except = null;
                    $sec   = intdiv($ms, 1000);
                    $usec  = ($ms % 1000) * 1000;
                    try {
                        $n = @stream_select($read, $write, $except, $sec, $usec);
                        if ($n === false) {
                            $ctx->inc("select_failed_$coro");
                        } elseif ($n === 0) {
                            $ctx->inc("select_timeout_$coro");
                        } else {
                            $ctx->inc("select_woke_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("select_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("select_failed_$coro");
                    }
                });
            })
            ->requires('unix-sockets');

        // Given TLS server "S" listening on "SRV" accepting up to N clients
        // Binds an ssl:// listener synchronously (so the address is known
        // for any client step that follows) using the openssl test cert
        // from ext/async/tests/stream/. Spawns the accept loop as the
        // body of the named coroutine SRV. Each accepted connection does
        // the server-side TLS handshake, sends a short "ok" payload, and
        // closes. Counters: tls_accept_attempts_$srv, tls_accept_ok_$srv,
        // tls_accept_failed_$srv. SRV terminates after N accepts OR when
        // the listen socket is closed in teardown — whichever comes first.
        $r->on('/^TLS server "([^"]+)" listening on "([^"]+)" accepting up to (\S+) clients$/',
            function(Context $ctx, string $srvName, string $coro, string $nExpr) {
                if (isset($ctx->tlsServers[$srvName])) {
                    // Already bound; just (re)install the accept loop.
                    $entry = $ctx->tlsServers[$srvName];
                } else {
                    $certDir = __DIR__ . '/../../tests/stream';
                    $sctx = stream_context_create(['ssl' => [
                        'local_cert'        => $certDir . '/ssl_test_cert.pem',
                        'local_pk'          => $certDir . '/ssl_test_key.pem',
                        'verify_peer'       => false,
                        'allow_self_signed' => true,
                        'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_SERVER,
                    ]]);
                    $server = @stream_socket_server(
                        'ssl://127.0.0.1:0', $errno, $errstr,
                        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $sctx);
                    if (!$server) {
                        throw new \RuntimeException(
                            "TLS server $srvName bind failed: $errstr ($errno)");
                    }
                    $addr  = stream_socket_get_name($server, false);
                    $entry = ['server' => $server, 'addr' => $addr];
                    $ctx->tlsServers[$srvName] = $entry;
                }
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($srvName, $n) {
                    if (!isset($ctx->tlsServers[$srvName])) return;
                    $server = $ctx->tlsServers[$srvName]['server'];
                    for ($i = 0; $i < $n; $i++) {
                        if (!is_resource($server)) break;
                        try {
                            $ctx->inc("tls_accept_attempts_$srvName");
                            $client = @stream_socket_accept($server, 5);
                            if ($client) {
                                $ctx->inc("tls_accept_ok_$srvName");
                                @fwrite($client, "ok");
                                @fclose($client);
                            } else {
                                $ctx->inc("tls_accept_failed_$srvName");
                            }
                        } catch (\Async\AsyncCancellation $e) {
                            $ctx->inc("tls_accept_cancelled_$srvName");
                            return;
                        } catch (\Throwable $e) {
                            $ctx->inc("tls_accept_failed_$srvName");
                        }
                    }
                });
            })
            ->requires('tcp', 'openssl');

        // When coroutine "X" connects via TLS to server "S" with timeout N ms
        // stream_socket_client('ssl://...') drives the full TCP connect +
        // TLS handshake. Both phases yield to the reactor; a cancel or
        // timeout must release the connect-watcher AND any crypto-retry
        // poll state without leaking watchers.
        $r->on('/^coroutine "([^"]+)" connects via TLS to server "([^"]+)" with timeout (\S+) ms$/',
            function(Context $ctx, string $coro, string $srvName, string $msExpr) {
                $ms = (int)$ctx->resolver->resolve($msExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $srvName, $ms) {
                    $ctx->inc("io_tls_attempts_$coro");
                    $sock = null;
                    // Whole body in one try so an AsyncCancellation
                    // landing in any setup step (stream_context_create
                    // is sync but cancellation may be delivered at the
                    // first interrupt-check point inside it) still
                    // buckets cleanly — invariant must hold across
                    // every interleaving including aggressive cancel
                    // windows on debug-ZTS-FUZZ builds.
                    try {
                        if (!isset($ctx->tlsServers[$srvName])) {
                            $ctx->inc("io_tls_no_server_$coro");
                            return;
                        }
                        $addr = $ctx->tlsServers[$srvName]['addr'];
                        $sec  = $ms / 1000.0;
                        $cctx = stream_context_create(['ssl' => [
                            'verify_peer'       => false,
                            'verify_peer_name'  => false,
                            'allow_self_signed' => true,
                            'crypto_method'     => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                        ]]);
                        $sock = @stream_socket_client(
                            'ssl://' . $addr, $errno, $errstr, $sec,
                            STREAM_CLIENT_CONNECT, $cctx);
                        if ($sock !== false) {
                            // Read the short server reply so the handshake
                            // and one record-layer roundtrip are both fully
                            // exercised before close.
                            @fread($sock, 16);
                            $ctx->inc("io_tls_ok_$coro");
                        } elseif (stripos((string)$errstr, 'timed out') !== false
                                || stripos((string)$errstr, 'timeout') !== false) {
                            $ctx->inc("io_tls_timeout_$coro");
                        } else {
                            $ctx->inc("io_tls_failed_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("io_tls_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("io_tls_failed_$coro");
                    } finally {
                        if (is_resource($sock)) @fclose($sock);
                    }
                });
            })
            ->requires('tcp', 'openssl');

        // When coroutine "X" awaits signal SIGUSR1|SIGUSR2
        // Wraps Async\signal(Signal::SIG…) in an await. Counters per
        // coroutine: signal_attempts / signal_received / signal_cancelled /
        // signal_failed. The signal must be raised by a sibling coroutine
        // via the "raises signal …" step. Backstop for php-async #109
        // (multi-thread race in zend_signal_activate, fixed by the
        // ZEND_ASYNC_REACTOR_IS_ENABLED skip — chaos exercises the
        // post-fix code path under random scheduling).
        $r->on('/^coroutine "([^"]+)" awaits signal (SIGUSR1|SIGUSR2)$/',
            function(Context $ctx, string $coro, string $sigName) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $sigName) {
                    $sig = constant("Async\\Signal::$sigName"); // enum case
                    $ctx->inc("signal_attempts_$coro");
                    try {
                        \Async\await(\Async\signal($sig));
                        $ctx->inc("signal_received_$coro");
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("signal_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("signal_failed_$coro");
                    }
                });
            })
            ->requires('signal');

        // When coroutine "X" raises signal SIGUSR1|SIGUSR2
        // posix_kill(getmypid(), $sig). All Async\signal() waiters parked
        // on that signal wake up; pcntl_signal handlers (if installed)
        // also fire. The raise is fire-and-forget — no per-waiter ack.
        $r->on('/^coroutine "([^"]+)" raises signal (SIGUSR1|SIGUSR2)$/',
            function(Context $ctx, string $coro, string $sigName) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $sigName) {
                    $sig = constant($sigName); // global int (e.g. SIGUSR1)
                    $ctx->inc("signal_raise_attempts_$coro");
                    if (@posix_kill(posix_getpid(), $sig)) {
                        $ctx->inc("signal_raise_ok_$coro");
                    } else {
                        $ctx->inc("signal_raise_failed_$coro");
                    }
                });
            })
            ->requires('signal');

        // When coroutine "X" connects to TCP blackhole "ADDR" with timeout N ms
        // stream_socket_client() against an RFC 5737 TEST-NET-1 address that
        // routes nowhere — the SYN goes out, no response comes back, so the
        // kernel returns EINPROGRESS and the connect suspends in
        // network_async_await_stream_socket() (the connect-watcher). This is
        // the precise reactor surface this step exists to chaos-test: a
        // killer cancel or the explicit timeout must release the connect
        // request without UAF or leaked poll watcher.
        //
        // Outcomes (exactly one per attempt):
        //   io_connect_ok        — connect succeeded (race: address briefly
        //                          routable from a CI box; tolerated)
        //   io_connect_timeout   — stream_socket_client returned false with
        //                          ETIMEDOUT / explicit timeout fired
        //   io_connect_cancelled — AsyncCancellation injected mid-wait
        //   io_connect_failed    — any other failure (ENETUNREACH from a
        //                          synchronous ICMP, etc.)
        $r->on('/^coroutine "([^"]+)" connects to TCP blackhole "([^"]+)" with timeout (\S+) ms$/',
            function(Context $ctx, string $coro, string $addr, string $msExpr) {
                $ms = (int)$ctx->resolver->resolve($msExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $addr, $ms) {
                    $ctx->inc("io_connect_attempts_$coro");
                    $sock = null;
                    $sec = $ms / 1000.0;
                    try {
                        $sock = @stream_socket_client(
                            'tcp://' . $addr, $errno, $errstr, $sec);
                        if ($sock !== false) {
                            $ctx->inc("io_connect_ok_$coro");
                        } elseif (stripos((string)$errstr, 'timed out') !== false
                                || stripos((string)$errstr, 'timeout') !== false) {
                            $ctx->inc("io_connect_timeout_$coro");
                        } else {
                            $ctx->inc("io_connect_failed_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("io_connect_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("io_connect_failed_$coro");
                    } finally {
                        if (is_resource($sock)) @fclose($sock);
                    }
                });
            })
            ->requires('tcp', 'tcp-blackhole');

        // When coroutine "X" connects to IPv6 blackhole "ADDR" with timeout N ms
        // Identical semantics to the IPv4 step, but uses an AF_INET6 socket
        // (ADDR must be a bracketed v6 literal, e.g. [::ffff:192.0.2.1]:81).
        // Exercises the v6 branch in xp_socket open + the same shared
        // network_async_await_stream_socket() connect-watcher. SKIPIF tag
        // tcp-blackhole-v6 guarantees the v6 connect actually parks on this
        // host — see the rationale in generate.php.
        $r->on('/^coroutine "([^"]+)" connects to IPv6 blackhole "([^"]+)" with timeout (\S+) ms$/',
            function(Context $ctx, string $coro, string $addr, string $msExpr) {
                $ms = (int)$ctx->resolver->resolve($msExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($coro, $addr, $ms) {
                    $ctx->inc("io_connect_attempts_$coro");
                    $sock = null;
                    $sec = $ms / 1000.0;
                    try {
                        $sock = @stream_socket_client(
                            'tcp://' . $addr, $errno, $errstr, $sec);
                        if ($sock !== false) {
                            $ctx->inc("io_connect_ok_$coro");
                        } elseif (stripos((string)$errstr, 'timed out') !== false
                                || stripos((string)$errstr, 'timeout') !== false) {
                            $ctx->inc("io_connect_timeout_$coro");
                        } else {
                            $ctx->inc("io_connect_failed_$coro");
                        }
                    } catch (\Async\AsyncCancellation $e) {
                        $ctx->inc("io_connect_cancelled_$coro");
                    } catch (\Throwable $e) {
                        $ctx->inc("io_connect_failed_$coro");
                    } finally {
                        if (is_resource($sock)) @fclose($sock);
                    }
                });
            })
            ->requires('tcp', 'tcp-blackhole-v6');

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

        // When coroutine "X" awaits all threads inspecting remote exceptions
        // A thread that throws surfaces to the awaiter as Async\RemoteException;
        // getRemoteClass() names the original class and getRemoteException()
        // returns the original Throwable (or null if it could not be loaded).
        $r->on('/^coroutine "([^"]+)" awaits all threads inspecting remote exceptions$/',
            function(Context $ctx, string $coro) {
                $ctx->planAction($coro, function(Context $ctx) use ($coro) {
                    $handles = $ctx->threadHandles[$coro] ?? [];
                    foreach ($handles as $h) {
                        $ctx->inc("thr_inspect_attempts_$coro");
                        try {
                            \Async\await($h);
                            $ctx->inc("thr_inspect_ok_$coro");
                        } catch (\Async\RemoteException $e) {
                            $ctx->inc("thr_remote_$coro");
                            $cls = $e->getRemoteClass();
                            if (is_string($cls) && $cls !== '') {
                                $ctx->inc("thr_remote_class_ok_$coro");
                            }
                            $remote = $e->getRemoteException();
                            if ($remote === null || $remote instanceof \Throwable) {
                                $ctx->inc("thr_remote_exc_ok_$coro");
                            }
                        } catch (\Throwable $e) {
                            $ctx->inc("thr_inspect_other_$coro");
                        }
                    }
                });
            })
            ->requires('zts');

        // ---- ThreadChannel cross-thread traffic ----

        // When coroutine "X" runs a thread that sends N to thread channel "tc"
        // A real OS-thread worker pushes N values into the ThreadChannel; the
        // calling coroutine drains them on the main thread. The worker returns
        // the count it sent — checked once joined.
        $r->on('/^coroutine "([^"]+)" runs a thread that sends (\S+) to thread channel "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $tc) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $tc) {
                    if (!isset($ctx->threadChannels[$tc])) {
                        $ctx->inc("tc_target_missing_$tc");
                        return;
                    }
                    $ch = $ctx->threadChannels[$tc];
                    $ctx->inc("tc_thread_send_attempts_$tc");
                    $h = \Async\spawn_thread(static function() use ($ch, $n): int {
                        for ($i = 0; $i < $n; $i++) {
                            $ch->send($i);
                        }
                        return $n;
                    });
                    for ($i = 0; $i < $n; $i++) {
                        try {
                            $ch->recv();
                            $ctx->inc("tc_main_received_$tc");
                        } catch (\Throwable $e) {
                            $ctx->inc("tc_main_recv_failed_$tc");
                        }
                    }
                    try {
                        if (\Async\await($h) === $n) {
                            $ctx->inc("tc_thread_send_ok_$tc");
                        }
                    } catch (\Throwable $e) {
                        $ctx->inc("tc_thread_send_failed_$tc");
                    }
                });
            })
            ->requires('zts');

        // When coroutine "X" runs a thread that receives N from thread channel "tc"
        // Mirror of the above: the worker drains N values, the calling coroutine
        // feeds them from the main thread.
        $r->on('/^coroutine "([^"]+)" runs a thread that receives (\S+) from thread channel "([^"]+)"$/',
            function(Context $ctx, string $coro, string $nExpr, string $tc) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n, $tc) {
                    if (!isset($ctx->threadChannels[$tc])) {
                        $ctx->inc("tc_target_missing_$tc");
                        return;
                    }
                    $ch = $ctx->threadChannels[$tc];
                    $ctx->inc("tc_thread_recv_attempts_$tc");
                    $h = \Async\spawn_thread(static function() use ($ch, $n): int {
                        for ($i = 0; $i < $n; $i++) {
                            $ch->recv();
                        }
                        return $n;
                    });
                    for ($i = 0; $i < $n; $i++) {
                        try {
                            $ch->send($i);
                            $ctx->inc("tc_main_sent_$tc");
                        } catch (\Throwable $e) {
                            $ctx->inc("tc_main_send_failed_$tc");
                        }
                    }
                    try {
                        if (\Async\await($h) === $n) {
                            $ctx->inc("tc_thread_recv_ok_$tc");
                        }
                    } catch (\Throwable $e) {
                        $ctx->inc("tc_thread_recv_failed_$tc");
                    }
                });
            })
            ->requires('zts');

        // When coroutine "X" runs a thread that blocks on closed thread channel "tc"
        // The worker parks on recv() of an empty ThreadChannel; the calling
        // coroutine closes it from the main thread. The worker's recv() must
        // unblock with a ChannelException — exercising cross-thread close.
        $r->on('/^coroutine "([^"]+)" runs a thread that blocks on closed thread channel "([^"]+)"$/',
            function(Context $ctx, string $coro, string $tc) {
                $ctx->planAction($coro, function(Context $ctx) use ($tc) {
                    if (!isset($ctx->threadChannels[$tc])) {
                        $ctx->inc("tc_target_missing_$tc");
                        return;
                    }
                    $ch = $ctx->threadChannels[$tc];
                    $ctx->inc("tc_close_race_attempts_$tc");
                    $h = \Async\spawn_thread(static function() use ($ch): string {
                        try {
                            $ch->recv();
                            return "no-throw";
                        } catch (\Throwable $e) {
                            return "threw";
                        }
                    });
                    $ch->close();
                    try {
                        $outcome = \Async\await($h);
                        $ctx->inc($outcome === "threw"
                            ? "tc_close_race_threw_$tc"
                            : "tc_close_race_no_throw_$tc");
                    } catch (\Throwable $e) {
                        $ctx->inc("tc_close_race_await_failed_$tc");
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

        // ---- Scope extras: asNotSafely / getChildScopes / handlers ----

        // When coroutine "X" marks a fresh scope as not-safely
        // asNotSafely() flips the cancellation-safety flag and returns the
        // SAME Scope — identity must hold.
        $r->on('/^coroutine "([^"]+)" marks a fresh scope as not-safely$/',
            function(Context $ctx, string $coro) {
                $ctx->planAction($coro, function(Context $ctx) {
                    $ctx->inc("scope_not_safely_attempts");
                    $scope = \Async\Scope::inherit();
                    $same = $scope->asNotSafely();
                    $ctx->inc($same === $scope ? "scope_not_safely_ok" : "scope_not_safely_bad");
                    // provideScope() on a Scope returns the scope itself.
                    $provided = $scope->provideScope();
                    $ctx->inc($provided === $scope ? "scope_provide_ok" : "scope_provide_bad");
                    $scope->dispose();
                });
            });

        // When coroutine "X" counts child scopes of a fresh parent of N
        // A parent created with N inheriting children reports exactly N via
        // getChildScopes(), each entry a Scope.
        $r->on('/^coroutine "([^"]+)" counts child scopes of a fresh parent of (\S+)$/',
            function(Context $ctx, string $coro, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n) {
                    $ctx->inc("child_scopes_attempts");
                    $parent = \Async\Scope::inherit();
                    $children = [];
                    for ($i = 0; $i < $n; $i++) {
                        $children[] = \Async\Scope::inherit($parent);
                    }
                    $reported = $parent->getChildScopes();
                    $ok = is_array($reported) && count($reported) === $n;
                    foreach ($reported as $c) {
                        $ok = $ok && ($c instanceof \Async\Scope);
                    }
                    $ctx->inc($ok ? "child_scopes_ok" : "child_scopes_bad");
                    $ctx->inc("child_scopes_count", is_array($reported) ? count($reported) : 0);
                    foreach ($children as $c) { $c->dispose(); }
                    $parent->dispose();
                });
            });

        // When coroutine "X" exercises a child-scope exception handler
        // A child scope coroutine throws; the parent's child-scope handler,
        // installed via setChildScopeExceptionHandler(), must receive it.
        $r->on('/^coroutine "([^"]+)" exercises a child-scope exception handler$/',
            function(Context $ctx, string $coro) {
                $ctx->planAction($coro, function(Context $ctx) {
                    $ctx->inc("csh_attempts");
                    $handled = 0;
                    $parent = \Async\Scope::inherit();
                    $parent->setChildScopeExceptionHandler(
                        function($scope, $co, \Throwable $e) use (&$handled) { $handled++; });
                    $child = \Async\Scope::inherit($parent);
                    $child->spawn(function() { throw new \RuntimeException("child boom"); });
                    for ($i = 0; $i < 4 && $handled === 0; $i++) {
                        \Async\suspend();
                    }
                    if ($handled >= 1) $ctx->inc("csh_handled");
                    $ctx->inc("csh_done");
                    if (!$parent->isClosed()) { $parent->dispose(); }
                });
            });

        // When coroutine "X" awaits a scope after cancellation
        // Cancels a fresh scope with a sleeping child, then drains it via
        // awaitAfterCancellation() — the scope ends finished.
        $r->on('/^coroutine "([^"]+)" awaits a scope after cancellation$/',
            function(Context $ctx, string $coro) {
                $ctx->planAction($coro, function(Context $ctx) {
                    $ctx->inc("aac_attempts");
                    $scope = \Async\Scope::inherit();
                    $scope->spawn(function() {
                        try { \Async\delay(5000); }
                        catch (\Throwable $e) { /* cancelled */ }
                    });
                    $scope->cancel();
                    \Async\suspend();
                    try {
                        $scope->awaitAfterCancellation(
                            function(\Throwable $e) use ($ctx) { $ctx->inc("aac_error_seen"); });
                        $ctx->inc("aac_done");
                    } catch (\Throwable $e) {
                        $ctx->inc("aac_threw");
                    }
                    if ($scope->isFinished()) $ctx->inc("aac_finished");
                    if (!$scope->isClosed()) { $scope->dispose(); }
                });
            });

        // When coroutine "X" schedules dispose of a scope after T ms
        // disposeAfterTimeout() arms a timer; once it fires the scope is
        // disposed and its sleeping child is cancelled. The scope is built
        // with asNotSafely() — on a *safe* scope a started child is turned
        // into a zombie instead of being cancelled (it would outlive the
        // scope, parked in delay()), so a not-safely scope is required to
        // exercise the real reap path.
        $r->on('/^coroutine "([^"]+)" schedules dispose of a scope after (\S+) ms$/',
            function(Context $ctx, string $coro, string $tExpr) {
                $t = (int)$ctx->resolver->resolve($tExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($t) {
                    $ctx->inc("dat_attempts");
                    $scope = \Async\Scope::inherit()->asNotSafely();
                    $scope->spawn(function() {
                        try { \Async\delay(5000); }
                        catch (\Throwable $e) { /* cancelled by dispose */ }
                    });
                    $scope->disposeAfterTimeout($t);
                    // Give the timer ample room to fire AND let the internal
                    // cancellation coroutine run to completion — do not bail
                    // out early on isFinished(), the child may still unwind.
                    for ($i = 0; $i < 12; $i++) {
                        \Async\delay($t);
                    }
                    for ($i = 0; $i < 4; $i++) {
                        \Async\suspend();
                    }
                    if ($scope->isFinished()) $ctx->inc("dat_finished");
                    $ctx->inc("dat_done");
                    if (!$scope->isClosed()) { $scope->dispose(); }
                });
            });

        // When coroutine "X" spawns a strategy-driven coroutine labelled "L"
        // spawn_with() drives the SpawnStrategy hooks: provideScope, then
        // beforeCoroutineEnqueue / afterCoroutineEnqueue around the enqueue.
        $r->on('/^coroutine "([^"]+)" spawns a strategy-driven coroutine labelled "([^"]+)"$/',
            function(Context $ctx, string $coro, string $label) {
                $ctx->planAction($coro, function(Context $ctx) use ($label) {
                    $ctx->inc("ss_attempts_$label");
                    $scope = new \Async\Scope();
                    $strategy = new ChaosSpawnStrategy($ctx, $label, $scope);
                    try {
                        $h = \Async\spawn_with($strategy, function() use ($ctx, $label) {
                            $ctx->inc("ss_body_ran_$label");
                            return "ok";
                        });
                        \Async\await($h);
                        $ctx->inc("ss_spawn_ok_$label");
                    } catch (\Throwable $e) {
                        $ctx->inc("ss_spawn_failed_$label");
                    }
                    if (!$scope->isClosed()) { $scope->dispose(); }
                });
            });

        // When coroutine "X" builds a composite exception with N parts
        // CompositeException::addException() accumulates Throwables;
        // getExceptions() returns every one.
        $r->on('/^coroutine "([^"]+)" builds a composite exception with (\S+) parts$/',
            function(Context $ctx, string $coro, string $nExpr) {
                $n = (int)$ctx->resolver->resolve($nExpr);
                $ctx->planAction($coro, function(Context $ctx) use ($n) {
                    $ctx->inc("composite_attempts");
                    $ce = new \Async\CompositeException("composite");
                    for ($i = 0; $i < $n; $i++) {
                        $ce->addException(new \RuntimeException("part $i"));
                    }
                    $parts = $ce->getExceptions();
                    $ok = is_array($parts) && count($parts) === $n;
                    foreach ($parts as $p) {
                        $ok = $ok && ($p instanceof \Throwable);
                    }
                    $ctx->inc($ok ? "composite_ok" : "composite_bad");
                    $ctx->inc("composite_count", is_array($parts) ? count($parts) : 0);
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

        // Then counter "X" is at least N
        // Turns a sum-only invariant into a dominant-bucket check: lets a
        // feature assert that a specific code path was actually taken on
        // every interleaving, not just that the outcomes summed.
        $r->on('/^counter "([^"]+)" is at least (\d+)$/',
            function(Context $ctx, string $name, string $bound) {
                $v = $ctx->counter($name);
                if ($v < (int)$bound) {
                    throw new \RuntimeException("counter $name = $v below bound $bound");
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

        // Then coroutine "X" was cancelled or finished cleanly
        // Interleaving-safe invariant for a cancel race: a short-bodied
        // coroutine may finish before the cancel lands. Either outcome is
        // valid — assert only that it terminated consistently.
        $r->on('/^coroutine "([^"]+)" was cancelled or finished cleanly$/',
            function(Context $ctx, string $name) {
                if (!isset($ctx->coroutineHandles[$name])) {
                    throw new \RuntimeException("coroutine $name not defined");
                }
                $h = $ctx->coroutineHandles[$name];
                if (!$h->isCompleted()) {
                    throw new \RuntimeException("coroutine $name expected isCompleted=true");
                }
                $e = $h->getException();
                $isCancel = $e instanceof \Async\AsyncCancellation;
                if ($e !== null && !$isCancel) {
                    throw new \RuntimeException(
                        "coroutine $name terminated with unexpected " . get_class($e));
                }
                if ($h->isCancelled() !== $isCancel) {
                    throw new \RuntimeException(
                        "coroutine $name: isCancelled / getException disagree");
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

        // Then coroutine "X" received the payload of peer "EP" intact
        // Whatever toxics the peer applied (slicing, drip delay), a correct
        // reactor reassembles the byte stream exactly — the received bytes
        // must equal the peer's declared payload, byte for byte.
        $r->on('/^coroutine "([^"]+)" received the payload of peer "([^"]+)" intact$/',
            function(Context $ctx, string $coro, string $peer) {
                if (!isset($ctx->net->evilPeerDefs[$peer])) {
                    throw new \RuntimeException("evil peer $peer not defined");
                }
                $expected = $ctx->net->evilPeerDefs[$peer]['payload'];
                $got = $ctx->ioData[$coro] ?? null;
                if ($got === null) {
                    throw new \RuntimeException("coroutine $coro received nothing from peer $peer");
                }
                if ($got !== $expected) {
                    throw new \RuntimeException(sprintf(
                        "coroutine %s payload mismatch: expected %d bytes, got %d bytes",
                        $coro, strlen($expected), strlen($got)));
                }
            });

        // Then coroutine "X" received a clean prefix of peer "EP"
        // After an abrupt mid-stream close the client gets fewer bytes than
        // the full payload — but those bytes must be an exact prefix of it
        // (correct partial data, no corruption), and non-empty up to the
        // payload length.
        $r->on('/^coroutine "([^"]+)" received a clean prefix of peer "([^"]+)"$/',
            function(Context $ctx, string $coro, string $peer) {
                if (!isset($ctx->net->evilPeerDefs[$peer])) {
                    throw new \RuntimeException("evil peer $peer not defined");
                }
                $payload = $ctx->net->evilPeerDefs[$peer]['payload'];
                $got = $ctx->ioData[$coro] ?? null;
                if ($got === null) {
                    throw new \RuntimeException("coroutine $coro received nothing from peer $peer");
                }
                if (strlen($got) > strlen($payload)) {
                    throw new \RuntimeException(sprintf(
                        "coroutine %s got %d bytes — more than the %d-byte payload",
                        $coro, strlen($got), strlen($payload)));
                }
                if ($got !== substr($payload, 0, strlen($got))) {
                    throw new \RuntimeException(
                        "coroutine $coro received bytes are not a prefix of peer $peer's payload");
                }
            });

        // Then coroutine "X" received HTTP status N
        // The curl client stashes the response status code into the
        // curl_http_code_$coro counter; a 4xx/5xx is a valid response, so this
        // is decidable independently of the transport-level outcome bucket.
        $r->on('/^coroutine "([^"]+)" received HTTP status (\S+)$/',
            function(Context $ctx, string $coro, string $sExpr) {
                $want = (int)$ctx->resolver->resolve($sExpr);
                $got  = $ctx->counters["curl_http_code_$coro"] ?? 0;
                if ($got !== $want) {
                    throw new \RuntimeException(sprintf(
                        "coroutine %s expected HTTP status %d, got %d", $coro, $want, $got));
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

    /**
     * Shared EvilPeer download routine used by the "downloads from peer" steps.
     * Connects to the peer, reads until EOF in `$readSize`-byte reads, and
     * records the outcome into per-coroutine counters + $ctx->ioData.
     *
     * Cancellation-aware: a cancel mid-read is caught, the partial bytes are
     * still stashed, and the outcome is bucketed — so liveness/safety
     * invariants hold across the transport × logic × scheduler cross-product.
     */
    public static function ioDownload(Context $ctx, string $coro, string $peer, int $readSize): void {
        $ctx->inc("io_download_attempts_$coro");
        // Define the received-bytes slot up front so a clean-prefix assertion
        // stays valid even when the download never gets past connect — a hard
        // RST can reset the connection before stream_socket_client() returns.
        $ctx->ioData[$coro] = '';
        $addr = $ctx->net->evilPeerAddr[$peer] ?? null;
        if ($addr === null) {
            $ctx->inc("io_download_no_peer_$coro");
            return;
        }
        $buf = '';
        $reads = 0;
        $outcome = 'ok';
        $sock = null;
        try {
            // connect() is itself a yield point — wrapping it in the try means
            // a cancel landing during connect lands in io_download_cancelled
            // rather than escaping uncaught.
            $sock = @stream_socket_client('tcp://' . $addr, $errno, $errstr, 5);
            if ($sock === false) {
                $ctx->inc("io_download_connect_failed_$coro");
                $outcome = 'connect_failed';
                return;
            }
            while (!feof($sock)) {
                $chunk = @fread($sock, $readSize);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buf .= $chunk;
                $reads++;
                $ctx->inc("io_read_calls_$coro");
            }
            $ctx->inc("io_download_ok_$coro");
        } catch (\Async\AsyncCancellation $e) {
            $outcome = 'cancelled';
            $ctx->inc("io_download_cancelled_$coro");
        } catch (\Throwable $e) {
            $outcome = 'failed';
            $ctx->inc("io_download_failed_$coro");
        } finally {
            if (is_resource($sock)) {
                @fclose($sock);
            }
            // Stash whatever was reassembled — partial on cancel/failure.
            $ctx->ioData[$coro] = $buf;
            $ctx->inc("io_recv_bytes_$coro", strlen($buf));
            // Record the low-level client sequence for the chaos event log.
            $ctx->events[] = sprintf(
                'client %s: peer=%s readsize=%d reads=%d recv=%dB outcome=%s',
                $coro, $peer, $readSize, $reads, strlen($buf), $outcome);
        }
    }

    /**
     * Shared EvilPeer upload routine used by the "uploads ... to peer" steps.
     * Connects to a consume-mode peer and writes `$bytes` bytes in `$writeSize`
     * -byte fwrite() calls. Against a slow / never-reading peer the writes
     * suspend on a full send buffer — this is what exercises the reactor's
     * write-wait hook.
     *
     * Cancellation-aware: a cancel mid-write is caught, the partial sent count
     * is still stashed, and the outcome is bucketed into exactly one counter
     * so the liveness invariant holds across every interleaving. A peer that
     * abandons the connection mid-stream surfaces as a clean io_upload_failed
     * (broken pipe), never a hang.
     */
    public static function ioUpload(Context $ctx, string $coro, string $peer, int $bytes, int $writeSize): void {
        $ctx->inc("io_upload_attempts_$coro");
        $addr = $ctx->net->evilPeerAddr[$peer] ?? null;
        if ($addr === null) {
            $ctx->inc("io_upload_no_peer_$coro");
            return;
        }
        $sent    = 0;
        $writes  = 0;
        $outcome = 'ok';
        $sock    = null;
        try {
            $sock = @stream_socket_client('tcp://' . $addr, $errno, $errstr, 5);
            if ($sock === false) {
                $ctx->inc("io_upload_connect_failed_$coro");
                $outcome = 'connect_failed';
                return;
            }
            // Deterministic payload — printable ASCII cycle (built fast so a
            // multi-MiB upload does not dominate the test runtime).
            $block = '';
            for ($i = 0; $i < 94; $i++) {
                $block .= chr(33 + $i);
            }
            $payload = substr(str_repeat($block, intdiv($bytes, 94) + 1), 0, $bytes);
            while ($sent < $bytes) {
                $n = @fwrite($sock, substr($payload, $sent, $writeSize)); /* may block */
                if ($n === false || $n === 0) {
                    break; // peer gone — broken pipe
                }
                $sent += $n;
                $writes++;
                $ctx->inc("io_write_calls_$coro");
            }
            if ($sent >= $bytes) {
                $ctx->inc("io_upload_ok_$coro");
            } else {
                $outcome = 'failed';
                $ctx->inc("io_upload_failed_$coro");
            }
        } catch (\Async\AsyncCancellation $e) {
            $outcome = 'cancelled';
            $ctx->inc("io_upload_cancelled_$coro");
        } catch (\Throwable $e) {
            $outcome = 'failed';
            $ctx->inc("io_upload_failed_$coro");
        } finally {
            if (is_resource($sock)) {
                @fclose($sock);
            }
            $ctx->inc("io_sent_bytes_$coro", $sent);
            $ctx->events[] = sprintf(
                'client %s: peer=%s upload=%dB writesize=%d writes=%d sent=%dB outcome=%s',
                $coro, $peer, $bytes, $writeSize, $writes, $sent, $outcome);
        }
    }

    /**
     * Shared async-curl routine used by the "fetches peer over HTTP" step.
     * Runs one ext/curl GET against an evil HTTP peer; ext/async drives the
     * transfer through the libuv reactor, so the coroutine yields for the
     * duration and a concurrent killer can cancel it mid-request.
     *
     * The body is captured incrementally via CURLOPT_WRITEFUNCTION into
     * $ctx->ioData so a truncated / cancelled transfer still leaves the prefix
     * that arrived — the same clean-prefix invariant the raw-socket download
     * uses. The outcome is bucketed into exactly one counter:
     *   curl_get_ok          — curl_errno() == 0 (a 4xx/5xx still counts)
     *   curl_get_cancelled   — AsyncCancellation delivered into the transfer
     *   curl_get_failed      — any curl error / other throwable
     *   curl_get_no_peer     — peer address never resolved
     * so curl_get_ok + cancelled + failed + no_peer == curl_get_attempts for
     * every interleaving. The response status is stashed separately into
     * curl_http_code_$coro.
     */
    public static function curlGet(Context $ctx, string $coro, string $peer): void {
        $ctx->inc("curl_get_attempts_$coro");
        // Define the body slot up front so a clean-prefix assertion stays valid
        // even when the request never produces a byte.
        $ctx->ioData[$coro] = '';
        $addr = $ctx->net->evilPeerAddr[$peer] ?? null;
        if ($addr === null) {
            $ctx->inc("curl_get_no_peer_$coro");
            return;
        }
        $buf      = '';
        $outcome  = 'ok';
        $errno    = 0;
        $httpCode = 0;
        $ch       = null;
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'http://' . $addr . '/');
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            // Append every delivered chunk; returning a short count would make
            // curl abort, so always report the full length back.
            curl_setopt($ch, CURLOPT_WRITEFUNCTION,
                function($ch, string $data) use (&$buf) {
                    $buf .= $data;
                    return strlen($data);
                });
            curl_exec($ch);
            $errno    = curl_errno($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($errno === 0) {
                $ctx->inc("curl_get_ok_$coro");
            } else {
                $outcome = 'failed';
                $ctx->inc("curl_get_failed_$coro");
            }
        } catch (\Async\AsyncCancellation $e) {
            $outcome = 'cancelled';
            $ctx->inc("curl_get_cancelled_$coro");
        } catch (\Throwable $e) {
            $outcome = 'failed';
            $ctx->inc("curl_get_failed_$coro");
        } finally {
            if ($ch instanceof \CurlHandle) {
                @curl_close($ch);
            }
            $ctx->ioData[$coro] = $buf;
            $ctx->inc("curl_recv_bytes_$coro", strlen($buf));
            $ctx->counters["curl_http_code_$coro"] = $httpCode;
            $ctx->events[] = sprintf(
                'curl %s: peer=%s http=%d errno=%d recv=%dB outcome=%s',
                $coro, $peer, $httpCode, $errno, strlen($buf), $outcome);
        }
    }

    /**
     * Shared async curl_multi routine used by the "fetches peers via
     * curl_multi" step. One curl_multi handle, one easy handle per named
     * peer, standard exec/select loop — the curl_multi_select() call is
     * where the reactor parks and the cancel/timeout surface lives.
     *
     * Per-handle CURLMSG_DONE messages are drained into curl_multi_handles_done
     * (CURLE_OK) / curl_multi_handles_failed (any other). The coroutine-level
     * outcome is bucketed exactly once:
     *   curl_multi_ok        — loop exited normally with active==0
     *   curl_multi_cancelled — AsyncCancellation delivered into curl_multi_select
     *   curl_multi_failed    — curl_multi_exec returned !CURLM_OK or other throw
     *   curl_multi_no_peer   — any named peer's address never resolved
     * so the four sum to curl_multi_attempts.
     */
    public static function curlMulti(Context $ctx, string $coro, array $peers): void {
        $ctx->inc("curl_multi_attempts_$coro");
        $addrs = [];
        foreach ($peers as $peer) {
            $addr = $ctx->net->evilPeerAddr[$peer] ?? null;
            if ($addr === null) {
                $ctx->inc("curl_multi_no_peer_$coro");
                return;
            }
            $addrs[] = $addr;
        }
        $mh   = null;
        $easy = [];
        try {
            $mh = curl_multi_init();
            foreach ($addrs as $addr) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'http://' . $addr . '/');
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                // Drain bodies but discard — only the outcome matters here.
                curl_setopt($ch, CURLOPT_WRITEFUNCTION,
                    static fn($ch, string $d) => strlen($d));
                curl_multi_add_handle($mh, $ch);
                $easy[] = $ch;
            }
            $active = null;
            do {
                $status = curl_multi_exec($mh, $active);
                if ($status !== CURLM_OK) {
                    $ctx->inc("curl_multi_failed_$coro");
                    return;
                }
                // Drain done messages as they arrive — the order in which
                // handles finish is non-deterministic under chaos peers.
                while (($msg = curl_multi_info_read($mh)) !== false) {
                    if ($msg['msg'] === CURLMSG_DONE) {
                        if ((int)$msg['result'] === CURLE_OK) {
                            $ctx->inc("curl_multi_handles_done_$coro");
                        } else {
                            $ctx->inc("curl_multi_handles_failed_$coro");
                        }
                    }
                }
                if ($active > 0) {
                    curl_multi_select($mh, 1.0);  /* reactor yield */
                }
            } while ($active > 0);
            $ctx->inc("curl_multi_ok_$coro");
        } catch (\Async\AsyncCancellation $e) {
            $ctx->inc("curl_multi_cancelled_$coro");
        } catch (\Throwable $e) {
            $ctx->inc("curl_multi_failed_$coro");
        } finally {
            if ($mh !== null) {
                foreach ($easy as $ch) {
                    @curl_multi_remove_handle($mh, $ch);
                    @curl_close($ch);
                }
                @curl_multi_close($mh);
            }
        }
    }

    /**
     * Shared async-PDO routine used by the "queries / runs a slow query"
     * database steps. Connects (pooled: reuses the shared handle; non-pooled:
     * opens a private one), runs $sql, drains the result set, and buckets the
     * outcome into per-coroutine counters keyed by $verb:
     *   db_<verb>_ok        — query completed, rows drained
     *   db_<verb>_cancelled — AsyncCancellation delivered into the query wait
     *   db_<verb>_failed    — PDOException / other throwable (e.g. RST)
     *   db_<verb>_no_db     — database fixture never resolved
     * so the four buckets sum to db_<verb>_attempts for every interleaving.
     * db_<verb>_rows_<coro> records how many rows were drained.
     *
     * The PDO connect and the query both go through the libuv reactor, so a
     * concurrent killer can cancel the coroutine mid-connect or mid-query, and
     * a Toxiproxy reset_peer toxic can drop the connection mid-result.
     */
    public static function dbRun(Context $ctx, string $coro, string $db, string $sql, string $verb): void {
        $ctx->inc("db_{$verb}_attempts_$coro");
        $spec = $ctx->net->evilDbDefs[$db] ?? null;
        if ($spec === null || !isset($ctx->net->evilDbAddr[$db])) {
            $ctx->inc("db_{$verb}_no_db_$coro");
            return;
        }
        $outcome = 'ok';
        $rows    = 0;
        $pdo     = null;
        try {
            // Pooled: one shared handle, the pool hands out a per-coroutine
            // slot. Non-pooled: a private connection opened (and dropped) here.
            $pdo = $spec['pool']
                ? $ctx->net->evilDbPool[$db]
                : $ctx->net->openDbConnection($db, false);
            // A dropped connection is the expected outcome under the toxics —
            // mysqlnd emits a raw E_WARNING for it on top of the PDOException;
            // @ silences that expected noise (the exception is still caught).
            $stmt = @$pdo->query($sql);
            while (@$stmt->fetch(\PDO::FETCH_NUM) !== false) {
                $rows++;
            }
            $ctx->inc("db_{$verb}_ok_$coro");
        } catch (\Async\AsyncCancellation $e) {
            $outcome = 'cancelled';
            $ctx->inc("db_{$verb}_cancelled_$coro");
        } catch (\Throwable $e) {
            $outcome = 'failed';
            $ctx->inc("db_{$verb}_failed_$coro");
        } finally {
            $ctx->inc("db_{$verb}_rows_$coro", $rows);
            // Non-pooled: drop this coroutine's private connection now.
            if (!$spec['pool']) {
                $pdo = null;
            }
            $ctx->events[] = sprintf(
                'db %s: db=%s verb=%s pool=%d rows=%d outcome=%s',
                $coro, $db, $verb, (int) $spec['pool'], $rows, $outcome);
        }
    }

    /**
     * Shared async-PDO routine for the "runs a transaction" database step:
     * BEGIN → INSERT → read-back SELECT → COMMIT. The multi-statement body
     * means several reactor round-trips inside one transaction, so a random
     * scheduler can interleave a sibling coroutine's work between any two of
     * them. A connection fault mid-transaction must surface as a clean error —
     * the coroutine terminates, the connection (or pool slot) is not left
     * wedged, and the server rolls the transaction back on the dropped
     * connection. Outcome buckets mirror dbRun():
     *   db_txn_ok / db_txn_cancelled / db_txn_failed / db_txn_no_db sum to
     *   db_txn_attempts; db_txn_committed counts the transactions that COMMIT
     *   actually acknowledged.
     */
    public static function dbTransaction(Context $ctx, string $coro, string $db): void {
        $ctx->inc("db_txn_attempts_$coro");
        $spec = $ctx->net->evilDbDefs[$db] ?? null;
        if ($spec === null || !isset($ctx->net->evilDbAddr[$db])) {
            $ctx->inc("db_txn_no_db_$coro");
            return;
        }
        $outcome = 'ok';
        $pdo     = null;
        try {
            $pdo = $spec['pool']
                ? $ctx->net->evilDbPool[$db]
                : $ctx->net->openDbConnection($db, false);
            // @ silences mysqlnd's raw E_WARNING on a dropped connection —
            // the expected outcome under the toxics; the PDOException still
            // propagates to the catch blocks below.
            @$pdo->beginTransaction();
            $stmt = @$pdo->prepare('INSERT INTO items (label, n) VALUES (?, ?)');
            @$stmt->execute(["txn-$coro", 0]);
            // Read-back inside the transaction — another reactor round-trip a
            // sibling coroutine can be scheduled across.
            $check = @$pdo->query('SELECT COUNT(*) FROM items WHERE id <= 5');
            @$check->fetch(\PDO::FETCH_NUM);
            @$pdo->commit();
            $ctx->inc("db_txn_committed_$coro");
            $ctx->inc("db_txn_ok_$coro");
        } catch (\Async\AsyncCancellation $e) {
            $outcome = 'cancelled';
            $ctx->inc("db_txn_cancelled_$coro");
        } catch (\Throwable $e) {
            $outcome = 'failed';
            $ctx->inc("db_txn_failed_$coro");
        } finally {
            // Best-effort rollback so a pooled slot is not left mid-transaction
            // for the next coroutine — harmless if the connection already died.
            if ($pdo !== null) {
                try {
                    if (@$pdo->inTransaction()) {
                        @$pdo->rollBack();
                    }
                } catch (\Throwable $e) {
                    /* connection already gone — the server rolled back for us */
                }
            }
            if (!$spec['pool']) {
                $pdo = null;
            }
            $ctx->events[] = sprintf(
                'db-txn %s: db=%s pool=%d outcome=%s',
                $coro, $db, (int) $spec['pool'], $outcome);
        }
    }

    /**
     * Shared async-mysqli routine used by the "queries / runs a slow query
     * via mysqli" steps. mysqli has no connection pool, so each call opens
     * (and closes) its own connection through the Toxiproxy proxy. Outcome
     * buckets mirror dbRun():
     *   mysqli_<verb>_ok / _cancelled / _failed / _no_db sum to
     *   mysqli_<verb>_attempts; mysqli_<verb>_rows records rows drained.
     */
    public static function mysqliRun(Context $ctx, string $coro, string $db, string $sql, string $verb): void {
        $ctx->inc("mysqli_{$verb}_attempts_$coro");
        if (!isset($ctx->net->evilDbDefs[$db]) || !isset($ctx->net->evilDbAddr[$db])) {
            $ctx->inc("mysqli_{$verb}_no_db_$coro");
            return;
        }
        $outcome = 'ok';
        $rows    = 0;
        $my      = null;
        try {
            $my  = $ctx->net->openMysqliConnection($db);
            // @ silences mysqlnd's raw E_WARNING on a dropped connection —
            // the mysqli_sql_exception still propagates to the catch blocks.
            $res = @$my->query($sql);
            if ($res instanceof \mysqli_result) {
                while (@$res->fetch_row() !== null) {
                    $rows++;
                }
                $res->free();
            }
            $ctx->inc("mysqli_{$verb}_ok_$coro");
        } catch (\Async\AsyncCancellation $e) {
            $outcome = 'cancelled';
            $ctx->inc("mysqli_{$verb}_cancelled_$coro");
        } catch (\Throwable $e) {
            $outcome = 'failed';
            $ctx->inc("mysqli_{$verb}_failed_$coro");
        } finally {
            $ctx->inc("mysqli_{$verb}_rows_$coro", $rows);
            if ($my instanceof \mysqli) {
                @$my->close();
            }
            $ctx->events[] = sprintf(
                'mysqli %s: db=%s verb=%s rows=%d outcome=%s',
                $coro, $db, $verb, $rows, $outcome);
        }
    }

    /**
     * Shared async-mysqli routine for the "runs a transaction via mysqli"
     * step: begin_transaction → prepared INSERT → commit. A connection fault
     * mid-transaction must surface as a clean mysqli_sql_exception; the
     * coroutine completes and nothing is left wedged. Outcome buckets:
     *   mysqli_txn_ok / _cancelled / _failed / _no_db sum to
     *   mysqli_txn_attempts; mysqli_txn_committed counts acknowledged COMMITs.
     */
    public static function mysqliTransaction(Context $ctx, string $coro, string $db): void {
        $ctx->inc("mysqli_txn_attempts_$coro");
        if (!isset($ctx->net->evilDbDefs[$db]) || !isset($ctx->net->evilDbAddr[$db])) {
            $ctx->inc("mysqli_txn_no_db_$coro");
            return;
        }
        $outcome = 'ok';
        $my      = null;
        try {
            $my = $ctx->net->openMysqliConnection($db);
            @$my->begin_transaction();
            $stmt  = @$my->prepare('INSERT INTO items (label, n) VALUES (?, ?)');
            $label = "mtxn-$coro";
            $n     = 0;
            @$stmt->bind_param('si', $label, $n);
            @$stmt->execute();
            @$my->commit();
            $ctx->inc("mysqli_txn_committed_$coro");
            $ctx->inc("mysqli_txn_ok_$coro");
        } catch (\Async\AsyncCancellation $e) {
            $outcome = 'cancelled';
            $ctx->inc("mysqli_txn_cancelled_$coro");
        } catch (\Throwable $e) {
            $outcome = 'failed';
            $ctx->inc("mysqli_txn_failed_$coro");
        } finally {
            if ($my instanceof \mysqli) {
                @$my->close();
            }
            $ctx->events[] = sprintf(
                'mysqli-txn %s: db=%s outcome=%s', $coro, $db, $outcome);
        }
    }
}
