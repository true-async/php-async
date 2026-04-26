--TEST--
PDO_SQLite Pool: classic ":memory:" DSN is rejected (cannot be shared between slots)
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php
try {
    Pdo\Sqlite::connect("sqlite::memory:", null, null, [
        PDO::ATTR_POOL_ENABLED => true,
        PDO::ATTR_ERRMODE      => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "UNEXPECTED: accepted\n";
} catch (PDOException $e) {
    echo "rejected: ", str_contains($e->getMessage(), 'memory') ? "memory" : "other", "\n";
}
echo "Done\n";
?>
--EXPECT--
rejected: memory
Done
