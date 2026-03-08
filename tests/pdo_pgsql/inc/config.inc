<?php
if (false !== getenv('PDO_PGSQL_TEST_DSN')) {
    $config['ENV']['PDOTEST_DSN'] = getenv('PDO_PGSQL_TEST_DSN');
} else {
    $config['ENV']['PDOTEST_DSN'] = 'pgsql:host=localhost port=5432 dbname=test user=postgres password=postgres';
}

foreach ($config['ENV'] as $k => $v) {
    putenv("$k=$v");
}
?>
