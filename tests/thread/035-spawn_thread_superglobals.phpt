--TEST--
spawn_thread() - $_SERVER and $_ENV available, parent globals isolated
--SKIPIF--
<?php
if (!PHP_ZTS) die('skip ZTS required');
if (!function_exists('Async\spawn_thread')) die('skip spawn_thread not available');
?>
--FILE--
<?php

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;

$GLOBALS['test_var'] = 'parent_value';

spawn(function() {
    $result = await(spawn_thread(function() {
        return [
            'has_server' => isset($_SERVER),
            'has_php_self' => isset($_SERVER['PHP_SELF']),
            'has_env' => isset($_ENV),
            'getenv_works' => (getenv('PATH') !== false),
            'parent_isolated' => !isset($GLOBALS['test_var']),
        ];
    }));

    echo "has_server: " . ($result['has_server'] ? 'yes' : 'no') . "\n";
    echo "has_php_self: " . ($result['has_php_self'] ? 'yes' : 'no') . "\n";
    echo "has_env: " . ($result['has_env'] ? 'yes' : 'no') . "\n";
    echo "getenv_works: " . ($result['getenv_works'] ? 'yes' : 'no') . "\n";
    echo "parent_isolated: " . ($result['parent_isolated'] ? 'yes' : 'no') . "\n";
});
?>
--EXPECT--
has_server: yes
has_php_self: yes
has_env: yes
getenv_works: yes
parent_isolated: yes
