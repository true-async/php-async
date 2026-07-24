--TEST--
PDO_SQLite Pool: an in-flight exception survives the pool closing during stack unwinding
--EXTENSIONS--
pdo
pdo_sqlite
true_async
--FILE--
<?php

// Destroying a local pooled PDO while an exception unwinds the frame closes the
// pool; the close must not steal the exception from its way to the catch.

$dbfile = tempnam(sys_get_temp_dir(), 'pdo_sqlite_pool_');

function boom(string $dbfile): void
{
    $pdo = new PDO('sqlite:' . $dbfile, null, null, [
        PDO::ATTR_TIMEOUT => 4,
        PDO::ATTR_POOL_ENABLED => true,
    ]);

    throw new RuntimeException('boom');
}

try {
    boom($dbfile);
} catch (Exception $e) {
    echo 'caught: ', $e->getMessage(), "\n";
}

@unlink($dbfile);
echo "done\n";
?>
--EXPECT--
caught: boom
done
