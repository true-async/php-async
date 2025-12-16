--TEST--
file_get_contents with HTTP stream and coroutine switching
--INI--
allow_url_fopen=1
--SKIPIF--
<?php
if (!function_exists('php_cli_server_start')) {
    die('skip php_cli_server_start not available');
}
?>
--FILE--
<?php

use function Async\spawn;
use function Async\await_all;

// Start CLI server
include __DIR__ . "/../../../../sapi/cli/tests/php_cli_server.inc";
php_cli_server_start();

echo "Start\n";

// Coroutine 1: makes HTTP request
$http_coroutine = spawn(function() {
    echo "HTTP: starting request\n";
    $content = file_get_contents("http://" . PHP_CLI_SERVER_ADDRESS);
    echo "HTTP: got response: '$content'\n";
});

// Coroutine 2: works in parallel
$worker = spawn(function() {
    echo "Worker: working while HTTP request is made\n";
    echo "Worker: still working\n";
    echo "Worker: finished\n";
});

await_all([$http_coroutine, $worker]);
echo "End\n";

?>
--EXPECT--
Start
HTTP: starting request
Worker: working while HTTP request is made
Worker: still working
Worker: finished
HTTP: got response: 'Hello world'
End