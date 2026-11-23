--TEST--
PDO_SQLite Pool: a statement outliving its PDO object reports to the strategy without the freed pool object
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_sqlite_test.inc';

use function Async\await;
use function Async\spawn;

// The PDO object, and with it the Async\Pool object getPool() returned, is
// freed before the statement it shares a cycle with. The statement's release
// then reaches the strategy, which must not be handed the freed object.

final class Strategy implements Async\CircuitBreakerStrategy
{
    public function reportSuccess(mixed $source): void
    {
        echo "reportSuccess ", get_debug_type($source), "\n";
    }

    public function reportFailure(mixed $source, \Throwable $error): void
    {
        echo "reportFailure ", get_debug_type($source), "\n";
    }

    public function shouldRecover(): bool
    {
        return true;
    }
}

[$dsn, $path] = AsyncPDOSqliteTest::fileDsn();
$pdo = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_POOL_ENABLED => true,
    PDO::ATTR_POOL_MIN => 0,
    PDO::ATTR_POOL_MAX => 1,
]);
register_shutdown_function(fn() => AsyncPDOSqliteTest::cleanup($path));
$pdo->getPool()->setCircuitBreakerStrategy(new Strategy());

await(spawn(function () use ($pdo) {
    $stmt = $pdo->query("SELECT 1");
    $cycle = new stdClass();
    $cycle->self = $cycle;
    $cycle->pdo = $pdo;
    $cycle->stmt = $stmt;
}));

unset($pdo);
echo "collect\n";
gc_collect_cycles();
echo "done\n";
?>
--EXPECT--
collect
done
reportSuccess null
