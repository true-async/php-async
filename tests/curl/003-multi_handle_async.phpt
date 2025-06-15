--TEST--
Async cURL multi-handle operations
--EXTENSIONS--
curl
--FILE--
<?php
require_once __DIR__ . '/../common/http_server.php';

use function Async\spawn;
use function Async\await;

$server = async_test_server_start();

function test_multi_handle($server) {
    echo "Starting multi-handle test\n";
    
    $mh = curl_multi_init();
    
    // Create multiple cURL handles
    $ch1 = curl_init();
    curl_setopt($ch1, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
    curl_multi_add_handle($mh, $ch1);
    
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_multi_add_handle($mh, $ch2);
    
    $ch3 = curl_init();
    curl_setopt($ch3, CURLOPT_URL, "http://localhost:{$server->port}/");
    curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
    curl_multi_add_handle($mh, $ch3);
    
    // Execute requests
    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($status !== CURLM_OK) {
            echo "Multi exec error: " . curl_multi_strerror($status) . "\n";
            break;
        }
        
        if ($active > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active > 0);
    
    // Get results
    $response1 = curl_multi_getcontent($ch1);
    $response2 = curl_multi_getcontent($ch2);
    $response3 = curl_multi_getcontent($ch3);
    
    // Clean up
    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_remove_handle($mh, $ch3);
    curl_multi_close($mh);
    
    curl_close($ch1);
    curl_close($ch2);
    curl_close($ch3);
    
    echo "Response 1: $response1\n";
    echo "Response 2: $response2\n";
    echo "Response 3: $response3\n";
    
    return [$response1, $response2, $response3];
}

echo "Test start\n";

$coroutine = spawn(fn() => test_multi_handle($server));
$results = await($coroutine);

echo "Test end\n";

async_test_server_stop($server);
?>
--EXPECT--
Test start
Starting multi-handle test
Response 1: Hello World
Response 2: Hello World
Response 3: Hello World
Test end