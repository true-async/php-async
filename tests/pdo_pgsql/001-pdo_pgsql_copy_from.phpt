--TEST--
PDO PgSQL: Async COPY FROM with Pdo\Pgsql (copyFromArray and copyFromFile)
--EXTENSIONS--
pdo_pgsql
true_async
--SKIPIF--
<?php
require_once __DIR__ . '/inc/async_pdo_pgsql_test.inc';
AsyncPDOPgSQLTest::skipIfNoAsync();
AsyncPDOPgSQLTest::skip();
?>
--FILE--
<?php
require_once __DIR__ . '/inc/async_pdo_pgsql_test.inc';

use function Async\spawn;
use function Async\await;

$coroutine = spawn(function() {
    $db = AsyncPDOPgSQLTest::factory();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);

    $db->exec('CREATE TABLE test_async_copy_from (a integer not null primary key, b text, c integer)');

    $tableRows = [];
    $tableRowsCustom = [];
    for ($i = 0; $i < 3; $i++) {
        $tableRows[] = "{$i}\ttest insert {$i}\t\\N";
        $tableRowsCustom[] = "{$i};test insert {$i};NULL";
    }

    // Test copyFromArray with default parameters
    echo "Testing copyFromArray() with defaults\n";
    $db->beginTransaction();
    var_dump($db->copyFromArray('test_async_copy_from', $tableRows));

    $stmt = $db->query("SELECT * FROM test_async_copy_from ORDER BY a");
    foreach ($stmt as $r) {
        var_dump($r);
    }
    $db->rollback();

    // Test copyFromArray with custom separator and null
    echo "Testing copyFromArray() with custom separator\n";
    $db->beginTransaction();
    var_dump($db->copyFromArray('test_async_copy_from', $tableRowsCustom, ";", "NULL"));

    $stmt = $db->query("SELECT * FROM test_async_copy_from ORDER BY a");
    foreach ($stmt as $r) {
        var_dump($r);
    }
    $db->rollback();

    // Test copyFromArray with selected fields
    echo "Testing copyFromArray() with selected fields\n";
    $tableRowsFields = [];
    for ($i = 0; $i < 3; $i++) {
        $tableRowsFields[] = "{$i};NULL";
    }
    $db->beginTransaction();
    var_dump($db->copyFromArray('test_async_copy_from', $tableRowsFields, ";", "NULL", "a,c"));

    $stmt = $db->query("SELECT * FROM test_async_copy_from ORDER BY a");
    foreach ($stmt as $r) {
        var_dump($r);
    }
    $db->rollback();

    // Test copyFromFile
    echo "Testing copyFromFile() with defaults\n";
    $filename = __DIR__ . '/test_async_copy.csv';
    file_put_contents($filename, implode("\n", $tableRows));

    $db->beginTransaction();
    var_dump($db->copyFromFile('test_async_copy_from', $filename));

    $stmt = $db->query("SELECT * FROM test_async_copy_from ORDER BY a");
    foreach ($stmt as $r) {
        var_dump($r);
    }
    $db->rollback();

    @unlink($filename);

    // Test copyFromArray with error (non-existing table)
    echo "Testing copyFromArray() with error\n";
    $db->beginTransaction();
    try {
        $db->copyFromArray('test_nonexistent_table', $tableRows);
    } catch (Exception $e) {
        echo "Exception: caught\n";
    }
    $db->rollback();

    $db->exec('DROP TABLE IF EXISTS test_async_copy_from');
    echo "done\n";
});

await($coroutine);
?>
--EXPECT--
Testing copyFromArray() with defaults
bool(true)
array(6) {
  ["a"]=>
  int(0)
  [0]=>
  int(0)
  ["b"]=>
  string(13) "test insert 0"
  [1]=>
  string(13) "test insert 0"
  ["c"]=>
  NULL
  [2]=>
  NULL
}
array(6) {
  ["a"]=>
  int(1)
  [0]=>
  int(1)
  ["b"]=>
  string(13) "test insert 1"
  [1]=>
  string(13) "test insert 1"
  ["c"]=>
  NULL
  [2]=>
  NULL
}
array(6) {
  ["a"]=>
  int(2)
  [0]=>
  int(2)
  ["b"]=>
  string(13) "test insert 2"
  [1]=>
  string(13) "test insert 2"
  ["c"]=>
  NULL
  [2]=>
  NULL
}
Testing copyFromArray() with custom separator
bool(true)
array(6) {
  ["a"]=>
  int(0)
  [0]=>
  int(0)
  ["b"]=>
  string(13) "test insert 0"
  [1]=>
  string(13) "test insert 0"
  ["c"]=>
  NULL
  [2]=>
  NULL
}
array(6) {
  ["a"]=>
  int(1)
  [0]=>
  int(1)
  ["b"]=>
  string(13) "test insert 1"
  [1]=>
  string(13) "test insert 1"
  ["c"]=>
  NULL
  [2]=>
  NULL
}
array(6) {
  ["a"]=>
  int(2)
  [0]=>
  int(2)
  ["b"]=>
  string(13) "test insert 2"
  [1]=>
  string(13) "test insert 2"
  ["c"]=>
  NULL
  [2]=>
  NULL
}
Testing copyFromArray() with selected fields
bool(true)
array(6) {
  ["a"]=>
  int(0)
  [0]=>
  int(0)
  ["b"]=>
  NULL
  [1]=>
  NULL
  ["c"]=>
  NULL
  [2]=>
  NULL
}
array(6) {
  ["a"]=>
  int(1)
  [0]=>
  int(1)
  ["b"]=>
  NULL
  [1]=>
  NULL
  ["c"]=>
  NULL
  [2]=>
  NULL
}
array(6) {
  ["a"]=>
  int(2)
  [0]=>
  int(2)
  ["b"]=>
  NULL
  [1]=>
  NULL
  ["c"]=>
  NULL
  [2]=>
  NULL
}
Testing copyFromFile() with defaults
bool(true)
array(6) {
  ["a"]=>
  int(0)
  [0]=>
  int(0)
  ["b"]=>
  string(13) "test insert 0"
  [1]=>
  string(13) "test insert 0"
  ["c"]=>
  NULL
  [2]=>
  NULL
}
array(6) {
  ["a"]=>
  int(1)
  [0]=>
  int(1)
  ["b"]=>
  string(13) "test insert 1"
  [1]=>
  string(13) "test insert 1"
  ["c"]=>
  NULL
  [2]=>
  NULL
}
array(6) {
  ["a"]=>
  int(2)
  [0]=>
  int(2)
  ["b"]=>
  string(13) "test insert 2"
  [1]=>
  string(13) "test insert 2"
  ["c"]=>
  NULL
  [2]=>
  NULL
}
Testing copyFromArray() with error
Exception: caught
done
