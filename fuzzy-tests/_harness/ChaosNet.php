<?php
/**
 * ChaosNet — the network-fixture layer of a chaos scenario.
 *
 * Owns every "external endpoint fronted for chaos":
 *   - in-process and forked EvilPeers (raw-TCP / HTTP misbehaving peers);
 *   - the Toxiproxy proxies fronting peers and databases;
 *   - the real database servers reached through Toxiproxy.
 *
 * Extracted from Context so that class stays the scenario state + orchestrator
 * and does not also carry the whole peer / proxy / DB lifecycle. Context holds
 * one ChaosNet as `$ctx->net`; steps reach fixtures through it —
 * `$ctx->net->evilPeerDefs`, `$ctx->net->defineEvilDb()`, and so on.
 *
 * Context::run() drives two entry points: setUp() in the prep phase (bind
 * peers, spawn serve coroutines, front peers + DBs with Toxiproxy) and
 * tearDown() in the cleanup phase (close sockets, reap forked processes,
 * delete proxies, drop pooled handles).
 */

namespace Async\Chaos;

use function Async\spawn;

require_once __DIR__ . '/../_peers/EvilPeer.php';
require_once __DIR__ . '/../_peers/ToxiproxyClient.php';

final class ChaosNet {
    // ---- EvilPeer fixtures ----

    /** @var array<string, array{payload:string,slice:int,delay:int,reset:int,hold:int,hardReset:bool,mode:string,forked:bool,toxiproxy:bool,httpStatus:int,httpChunked:bool,httpClenLie:int,httpHeaderDelay:int}>
     * EvilPeer fault tables, keyed by peer name. */
    public array $evilPeerDefs = [];

    /** @var array<string, string> peer name => "host:port" — populated by setUp() */
    public array $evilPeerAddr = [];

    /** @var array<string, resource> peer name => listening socket */
    public array $evilPeerServers = [];

    /** @var array<string, array{proc:resource,pipes:array}> peer name =>
     * forked peer process handle (only for peers run as a forked peer) */
    public array $evilPeerProcs = [];

    /** @var array<string, array<int,array{type:string,stream:string,attributes:array}>>
     * Toxiproxy toxics layered onto a fronted peer, keyed by peer name. */
    public array $evilPeerToxics = [];

    // ---- Toxiproxy ----

    /** @var string[] Toxiproxy proxy names created in setUp(), torn down after. */
    public array $toxiproxyProxies = [];

    /** Toxiproxy admin client — constructed lazily when a peer/DB is fronted. */
    public ?ToxiproxyClient $toxiproxy = null;

    // ---- Database-under-chaos fixtures ----

    /** @var array<string,array{driver:string,pool:bool,poolMax:int}>
     * Database-under-chaos definitions, keyed by name. Unlike an EvilPeer, the
     * upstream is a real DB server — the harness only fronts it with Toxiproxy
     * so the chaos lands on the driver's wire I/O. */
    public array $evilDbDefs = [];

    /** @var array<string,array<int,array{type:string,stream:string,attributes:array}>>
     * Toxiproxy toxics layered onto a fronted database, keyed by db name. */
    public array $evilDbToxics = [];

    /** @var array<string,string> db name => proxy "host:port" the client
     * connects through — populated by setUp() once the proxy is up. */
    public array $evilDbAddr = [];

    /** @var array<string,\PDO> shared pool-enabled PDO handles, keyed by db
     * name — one handle many coroutines share, created in setUp(). */
    public array $evilDbPool = [];

    /** Define an EvilPeer; idempotent. Toxics are layered on by later steps. */
    public function defineEvilPeer(string $name): void {
        if (!isset($this->evilPeerDefs[$name])) {
            $this->evilPeerDefs[$name] = [
                'payload' => '', 'slice' => 0, 'delay' => 0, 'reset' => -1,
                'hold' => 0, 'hardReset' => false, 'mode' => 'serve', 'forked' => false,
                'toxiproxy' => false,
                'httpStatus' => 200, 'httpChunked' => false,
                'httpClenLie' => 0, 'httpHeaderDelay' => 0,
            ];
        }
    }

    /**
     * Front an EvilPeer with Toxiproxy and, optionally, append one transport
     * toxic. `stream` may be 'auto' — resolved at proxy-creation time to
     * 'downstream' for a serve peer or 'upstream' for a consume peer.
     */
    public function addEvilPeerToxic(
        string $name,
        ?string $type = null,
        string $stream = 'auto',
        array $attributes = []
    ): void {
        $this->defineEvilPeer($name);
        $this->evilPeerDefs[$name]['toxiproxy'] = true;
        if ($type !== null) {
            $this->evilPeerToxics[$name][] = [
                'type' => $type, 'stream' => $stream, 'attributes' => $attributes,
            ];
        }
    }

    /** Declare a database-under-chaos; idempotent. Toxics are layered on by
     * later steps; setUp() fronts it with a Toxiproxy proxy. */
    public function setEvilDbStmtCache(string $name, int $size): void {
        $this->defineEvilDb($name);
        $this->evilDbDefs[$name]['stmtCache'] = $size;
    }

    public function defineEvilDb(string $name, string $driver = 'mysql', bool $pool = false, int $poolMax = 4): void {
        if (!isset($this->evilDbDefs[$name])) {
            $this->evilDbDefs[$name] = ['driver' => $driver, 'pool' => false, 'poolMax' => $poolMax];
        }
        if ($pool) {
            $this->evilDbDefs[$name]['pool']    = true;
            $this->evilDbDefs[$name]['poolMax'] = $poolMax;
        }
    }

    /** Append one Toxiproxy toxic to a fronted database. `stream` defaults to
     * 'downstream' — the server→client direction that carries the result set;
     * Toxiproxy rejects any value other than 'upstream' / 'downstream'. */
    public function addEvilDbToxic(string $name, string $type, array $attributes, string $stream = 'downstream'): void {
        $this->defineEvilDb($name);
        $this->evilDbToxics[$name][] = [
            'type' => $type, 'stream' => $stream, 'attributes' => $attributes,
        ];
    }

    /**
     * The real DB-server address a driver's chaos databases are fronted onto.
     * Comes from the environment so the suite adapts to whatever the CI / dev
     * box exposes; chaos-friendly defaults match the local setup.
     */
    public function dbUpstream(string $driver): string {
        return $driver === 'pgsql'
            ? (getenv('CHAOS_PGSQL') ?: '127.0.0.1:5432')
            : (getenv('CHAOS_MYSQL') ?: '127.0.0.1:3306');
    }

    /**
     * Open a PDO connection to a fronted database, through its Toxiproxy proxy.
     * Driver-aware (mysql / pgsql); connection parameters come from the
     * environment — CHAOS_{MYSQL,PGSQL}[_USER|_PASS|_DB]. A pool-enabled handle
     * is created with POOL_MIN 0, so the constructor opens no socket eagerly.
     */
    public function openDbConnection(string $db, bool $pool): \PDO {
        $driver = $this->evilDbDefs[$db]['driver'] ?? 'mysql';
        $opts   = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];

        if ($pool) {
            $opts[\PDO::ATTR_POOL_ENABLED] = true;
            $opts[\PDO::ATTR_POOL_MIN]     = 0;
            $opts[\PDO::ATTR_POOL_MAX]     = $this->evilDbDefs[$db]['poolMax'] ?? 4;
            $cacheSize = $this->evilDbDefs[$db]['stmtCache'] ?? 0;
            if ($cacheSize > 0 && defined('PDO::ATTR_POOL_STMT_CACHE_SIZE')) {
                $opts[\PDO::ATTR_POOL_STMT_CACHE_SIZE] = $cacheSize;
            }
        }

        if ($driver === 'sqlite') {
            return new \PDO('sqlite:' . $this->evilDbAddr[$db], null, null, $opts);
        }

        $addr   = $this->evilDbAddr[$db] ?? '';
        $colon  = strrpos($addr, ':');
        $pgsql  = $driver === 'pgsql';
        $host   = $colon === false ? $addr : substr($addr, 0, $colon);
        $port   = $colon === false ? ($pgsql ? 5432 : 3306) : (int) substr($addr, $colon + 1);
        $envPfx = $pgsql ? 'CHAOS_PGSQL' : 'CHAOS_MYSQL';
        $user   = getenv("{$envPfx}_USER") ?: 'test';
        $pass   = getenv("{$envPfx}_PASS") ?: 'test';
        $name   = getenv("{$envPfx}_DB")   ?: 'chaos_test';
        $dsn    = sprintf('%s:host=%s;port=%d;dbname=%s',
            $pgsql ? 'pgsql' : 'mysql', $host, $port, $name);

        $opts[\PDO::ATTR_TIMEOUT] = 5;

        return new \PDO($dsn, $user, $pass, $opts);
    }

    /**
     * Open a mysqli connection to a fronted database, through its Toxiproxy
     * proxy. mysqli has no connection pool, so every chaos query opens (and
     * closes) its own connection. Same environment-driven parameters as
     * openDbConnection(); MYSQLI_REPORT_ERROR|STRICT makes connect/query
     * failures throw mysqli_sql_exception, so the chaos steps can bucket them.
     */
    public function openMysqliConnection(string $db): \mysqli {
        $addr  = $this->evilDbAddr[$db] ?? '';
        $colon = strrpos($addr, ':');
        $host  = $colon === false ? $addr : substr($addr, 0, $colon);
        $port  = $colon === false ? 3306  : (int) substr($addr, $colon + 1);
        $user  = getenv('CHAOS_MYSQL_USER') ?: 'test';
        $pass  = getenv('CHAOS_MYSQL_PASS') ?: 'test';
        $name  = getenv('CHAOS_MYSQL_DB')   ?: 'chaos_test';
        \mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        // @ silences mysqlnd's raw E_WARNING when the connect fails under a
        // toxic — MYSQLI_REPORT_STRICT still raises mysqli_sql_exception,
        // which the chaos client routine catches and buckets.
        return @new \mysqli($host, $user, $pass, $name, $port);
    }

    /**
     * Prep phase: bind every in-process peer's listening socket synchronously
     * (so the client address is known before any client coroutine runs), spawn
     * one accept-and-serve coroutine per peer, then front the marked peers and
     * every declared database with a Toxiproxy proxy.
     *
     * @return \Async\Coroutine[] the peer serve coroutines, for run() to await
     */
    public function setUp(Context $ctx): array {
        $peerCoros = [];

        // Each in-process peer: bind, then serve one connection from a
        // coroutine awaited like a user coroutine.
        foreach ($this->evilPeerDefs as $name => $spec) {
            // A forked peer runs in its own OS process — no in-process socket
            // or accept coroutine; proc_open binds and serves it externally.
            if (!empty($spec['forked'])) {
                $this->startForkedPeer($name, $spec);
                continue;
            }
            $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($server === false) {
                throw new \RuntimeException("EvilPeer $name: cannot listen: $errstr");
            }
            $this->evilPeerServers[$name] = $server;
            $this->evilPeerAddr[$name] = stream_socket_get_name($server, false);
            $peerCoros[] = spawn(function() use ($ctx, $name, $server, $spec) {
                $ctx->inc("evil_peer_accept_attempts_$name");
                $conn = @stream_socket_accept($server, 5);
                if ($conn === false) {
                    $ctx->inc("evil_peer_accept_failed_$name");
                    return;
                }
                $ctx->inc("evil_peer_served_$name");
                match ($spec['mode'] ?? 'serve') {
                    'consume' => EvilPeer::consume($conn, $spec, $ctx, $name),
                    'http'    => EvilPeer::serveHttp($conn, $spec, $ctx, $name),
                    default   => EvilPeer::serve($conn, $spec, $ctx, $name),
                };
            });
        }

        // Toxiproxy fronting: for every peer marked `is fronted by Toxiproxy`,
        // create a proxy whose upstream is the peer's real listening socket,
        // attach the declared toxics, and rewrite $evilPeerAddr to the proxy's
        // listen address. The client then connects through Toxiproxy without
        // knowing it — the peer-address indirection makes this transparent.
        // Generated .phpt for these scenarios carry a Toxiproxy --SKIPIF--
        // probe, so by the time we get here Toxiproxy is known to be up.
        foreach ($this->evilPeerDefs as $name => $spec) {
            if (empty($spec['toxiproxy'])) {
                continue;
            }
            $this->toxiproxy ??= new ToxiproxyClient();
            $upstream  = $this->evilPeerAddr[$name];
            $proxyName = sprintf('chaos_%d_%s_%s', getmypid(), bin2hex(random_bytes(3)), $name);
            $listen    = $this->toxiproxy->createProxy($proxyName, '127.0.0.1:0', $upstream);
            $this->toxiproxyProxies[] = $proxyName;
            $defaultStream = ($spec['mode'] ?? 'serve') === 'consume' ? 'upstream' : 'downstream';
            foreach ($this->evilPeerToxics[$name] ?? [] as $i => $tox) {
                $stream = $tox['stream'] === 'auto' ? $defaultStream : $tox['stream'];
                $this->toxiproxy->addToxic(
                    $proxyName, $proxyName . '_t' . $i,
                    $tox['type'], $stream, $tox['attributes']);
            }
            // From here on the client connects through the proxy, not the peer.
            $this->evilPeerAddr[$name] = $listen;
            $ctx->events[] = sprintf(
                'toxiproxy %s: proxy %s upstream=%s listen=%s toxics=%d',
                $name, $proxyName, $upstream, $listen,
                count($this->evilPeerToxics[$name] ?? []));
        }

        // Database-under-chaos fronting: a real DB server cannot be an
        // in-process peer, so every declared DB is reached only through a
        // Toxiproxy proxy (upstream = the real server). The toxics then land on
        // the driver's wire I/O — latency / bandwidth / RST mid-query. A
        // pool-enabled DB also gets its one shared PDO handle built here.
        foreach ($this->evilDbDefs as $name => $spec) {
            $driver = $spec['driver'] ?? 'mysql';

            if ($driver === 'sqlite') {
                $path = sprintf('%s/chaos_sqlite_%d_%s.db', sys_get_temp_dir(), getmypid(), $name);
                @unlink($path);
                $seed = new \PDO('sqlite:' . $path);
                $seed->exec('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT, n INT)');
                $seed->exec("INSERT INTO items (label, n) VALUES ('alpha',1),('beta',2),('gamma',3),('delta',4),('epsilon',5)");
                $seed = null;
                $this->evilDbAddr[$name] = $path;

                if ($spec['pool']) {
                    $this->evilDbPool[$name] = $this->openDbConnection($name, true);
                }

                continue;
            }

            $this->toxiproxy ??= new ToxiproxyClient();
            $upstream  = $this->dbUpstream($driver);
            $proxyName = sprintf('chaosdb_%d_%s_%s', getmypid(), bin2hex(random_bytes(3)), $name);
            $listen    = $this->toxiproxy->createProxy($proxyName, '127.0.0.1:0', $upstream);
            $this->toxiproxyProxies[] = $proxyName;
            $this->evilDbAddr[$name] = $listen;
            // Build the shared pooled handle through the still-clean proxy,
            // BEFORE any toxic is attached — a connection-killing toxic
            // (reset_peer) must land on the chaos queries, not on the pooled
            // PDO constructor's own validation connect.
            if ($spec['pool']) {
                $this->evilDbPool[$name] = $this->openDbConnection($name, true);
            }
            foreach ($this->evilDbToxics[$name] ?? [] as $i => $tox) {
                $this->toxiproxy->addToxic(
                    $proxyName, $proxyName . '_t' . $i,
                    $tox['type'], $tox['stream'], $tox['attributes']);
            }
            $ctx->events[] = sprintf(
                'toxiproxy-db %s: proxy %s upstream=%s listen=%s pool=%d toxics=%d',
                $name, $proxyName, $upstream, $listen, (int) $spec['pool'],
                count($this->evilDbToxics[$name] ?? []));
        }

        return $peerCoros;
    }

    /**
     * Cleanup phase: drop pooled PDO handles (the pool destructor releases
     * every per-coroutine connection), close peer listening sockets, reap
     * forked peer processes, and delete every Toxiproxy proxy.
     */
    public function tearDown(): void {
        // Drop shared pool-enabled PDO handles — the PDO pool destructor
        // releases every per-coroutine connection it still holds.
        $this->evilDbPool = [];

        // Delete per-scenario SQLite files.
        foreach ($this->evilDbDefs as $name => $spec) {
            if (($spec['driver'] ?? '') === 'sqlite' && isset($this->evilDbAddr[$name])) {
                @unlink($this->evilDbAddr[$name]);
            }
        }

        // Close every EvilPeer listening socket left open.
        foreach ($this->evilPeerServers as $server) {
            if (is_resource($server)) {
                @fclose($server);
            }
        }

        // Reap forked peer processes — each exits on its own after serving
        // one connection; terminate is a safety net for an unconnected peer.
        foreach ($this->evilPeerProcs as $entry) {
            foreach ([1, 2] as $fd) {
                if (isset($entry['pipes'][$fd]) && is_resource($entry['pipes'][$fd])) {
                    @fclose($entry['pipes'][$fd]);
                }
            }
            if (is_resource($entry['proc'])) {
                @proc_terminate($entry['proc']);
                @proc_close($entry['proc']);
            }
        }

        // Delete every Toxiproxy proxy created for this scenario.
        if ($this->toxiproxy !== null) {
            foreach ($this->toxiproxyProxies as $proxyName) {
                $this->toxiproxy->deleteProxy($proxyName);
            }
        }
    }

    /** Launch a forked EvilPeer in its own process and record its address.
     * The fault table is handed over on the child's stdin; the child prints
     * its bound "host:port" as the first stdout line. */
    private function startForkedPeer(string $name, array $spec): void {
        $script = __DIR__ . '/../_peers/forked_peer.php';
        $descriptors = [
            0 => ['pipe', 'r'],   // child stdin  — the serialized fault table
            1 => ['pipe', 'w'],   // child stdout — the bound address
            2 => ['pipe', 'w'],   // child stderr
        ];
        $pipes = [];
        $proc = @proc_open([PHP_BINARY, $script], $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException("EvilPeer $name: cannot fork peer process");
        }
        fwrite($pipes[0], base64_encode(serialize($spec)));
        fclose($pipes[0]);
        $addr = trim((string) fgets($pipes[1]));
        if ($addr === '') {
            @proc_terminate($proc);
            @proc_close($proc);
            throw new \RuntimeException("EvilPeer $name: forked peer did not report an address");
        }
        $this->evilPeerAddr[$name] = $addr;
        $this->evilPeerProcs[$name] = ['proc' => $proc, 'pipes' => $pipes];
    }
}
