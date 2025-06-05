--TEST--
Socket IPv6 hostname resolution with real hostname in async context
--SKIPIF--
<?php
if (!extension_loaded('sockets')) {
    die('skip sockets extension not available');
}
if (!extension_loaded('async')) {
    die('skip async extension not available');
}
if (!defined('AF_INET6')) {
    die('skip IPv6 not supported');
}
// Check if we can resolve IPv6 addresses
$ips = @gethostbynamel('google.com');
if (!$ips) {
    die('skip DNS resolution not working');
}
?>
--FILE--
<?php
async(function () {
    echo "Testing IPv6 hostname resolution with socket functions\n";
    
    // Test socket_connect with hostname
    $socket = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
    if ($socket) {
        // Try to connect to a hostname - this will test async hostname resolution
        $start_time = microtime(true);
        $result = @socket_connect($socket, "google.com", 80);
        $end_time = microtime(true);
        
        $elapsed = ($end_time - $start_time) * 1000; // Convert to milliseconds
        echo "Connect attempt took: " . round($elapsed, 2) . " ms\n";
        
        if ($result === false) {
            $error = socket_last_error($socket);
            // Expected errors for IPv6 connection to hostname that may not have IPv6
            if ($error === SOCKET_ECONNREFUSED || 
                $error === SOCKET_ENETUNREACH || 
                $error === SOCKET_ETIMEDOUT ||
                $error === SOCKET_EAFNOSUPPORT) {
                echo "Hostname resolution attempted (connection failed as expected)\n";
            } else {
                echo "Connect failed with error: " . socket_strerror($error) . "\n";
            }
        } else {
            echo "IPv6 connection to hostname succeeded\n";
        }
        
        socket_close($socket);
    }
    
    // Test socket_sendto with hostname
    $udp_socket = socket_create(AF_INET6, SOCK_DGRAM, SOL_UDP);
    if ($udp_socket) {
        $message = "test";
        $result = @socket_sendto($udp_socket, $message, strlen($message), 0, "google.com", 53);
        
        if ($result === false) {
            $error = socket_last_error($udp_socket);
            echo "UDP sendto to hostname failed: " . socket_strerror($error) . "\n";
        } else {
            echo "UDP sendto to hostname succeeded, sent $result bytes\n";
        }
        
        socket_close($udp_socket);
    }
});

echo "IPv6 hostname resolution test completed\n";
?>
--EXPECTF--
Testing IPv6 hostname resolution with socket functions
Connect attempt took: %f ms
%s
%s
IPv6 hostname resolution test completed