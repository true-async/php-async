--TEST--
cURL exec with coroutine switching
--EXTENSIONS--
curl
--FILE--
<?php
include "../../sapi/cli/tests/php_cli_server.inc";

use function Async\spawn;
use function Async\await;

php_cli_server_start();

function test_curl() {
    echo "coroutine start\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "http://" . PHP_CLI_SERVER_ADDRESS);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    curl_close($ch);

    var_dump($response);
    echo "coroutine end\n";
}

function test_simple() {
    echo "coroutine 2\n";
}

echo "start\n";

$coroutine1 = spawn(test_curl(...));
$coroutine2 = spawn(test_simple(...));

await($coroutine1);
await($coroutine2);


echo "end\n";
?>
--EXPECT--
start
coroutine start
coroutine 2
string(11) "Hello world"
coroutine end
end