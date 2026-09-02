--TEST--
PDO_SQLite Pool: getPool() hands out counters, not connections
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

use function Async\await;
use function Async\spawn;

[$pdo, $path] = AsyncPDOSqliteTest::poolFromTemp([PDO::ATTR_POOL_MIN => 2]);
register_shutdown_function(fn() => AsyncPDOSqliteTest::cleanup($path));

$pdo->exec('CREATE TABLE t (a INT)');
$pool = $pdo->getPool();

function counters(Async\Pool $pool): string
{
    return sprintf("idle=%d active=%d", $pool->idleCount(), $pool->activeCount());
}

echo counters($pool), "\n";

foreach (['acquire', 'tryAcquire'] as $method) {
    try {
        $pool->$method();
        echo "$method: returned\n";
    } catch (Async\PoolException $e) {
        echo "$method: ", $e->getMessage(), "\n";
    }
}

/* release() takes mixed, so every one of these is an argument of the declared type. */
foreach ([42, 'x', null, new stdClass(), $pdo] as $value) {
    try {
        $pool->release($value);
        echo "release: returned\n";
    } catch (Async\PoolException $e) {
        echo "release: refused\n";
    }
}

/* A pool is normally used from a coroutine, where acquire() finds a scheduler already up. */
await(spawn(function () use ($pool) {
    try {
        $pool->acquire();
        echo "acquire in coroutine: returned\n";
    } catch (Async\PoolException $e) {
        echo "acquire in coroutine: refused\n";
    }
}));

/* PDO caches this wrapper, so it has to keep the pool the handle opened. */
try {
    $pool->__construct(fn() => new stdClass());
    echo "__construct: returned\n";
} catch (Async\PoolException $e) {
    echo "__construct: ", $e->getMessage(), "\n";
}

/* A refused acquire must not have taken a connection with it. */
echo counters($pool), "\n";
var_dump($pdo->query('SELECT 1 AS a')->fetch()['a']);
?>
--EXPECT--
idle=2 active=0
acquire: Pool resources are owned by the engine and cannot cross into PHP
tryAcquire: Pool resources are owned by the engine and cannot cross into PHP
release: refused
release: refused
release: refused
release: refused
release: refused
acquire in coroutine: refused
__construct: Pool is already initialized
idle=2 active=0
int(1)
