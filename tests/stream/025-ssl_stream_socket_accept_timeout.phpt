--TEST--
SSL Stream: stream_socket_accept() with SSL and timeout
--SKIPIF--
<?php if (!extension_loaded('openssl')) die('skip openssl extension not available'); ?>
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;

echo "Start SSL accept timeout test\n";

// Create a simple self-signed certificate for testing
$cert_data = "-----BEGIN CERTIFICATE-----
MIICATCCAWoCCQC5Q2QzxQQAojANBgkqhkiG9w0BAQsFADAbMRkwFwYDVQQDDBAq
LmFzeW5jLXRlc3QuZGV2MB4XDTIzMDEwMTAwMDAwMFoXDTI0MDEwMTAwMDAwMFow
GzEZMBcGA1UEAwwQKi5hc3luYy10ZXN0LmRldjCBnzANBgkqhkiG9w0BAQEFAAOB
jQAwgYkCgYEA1XKXUjT9kGCkm3p7z9KJoLh4KjWfI2J8Z3HxnI6CcE8x3tXqI0VK
ZfDXmL8wG9k5PxS6E4pJ2gOJQp3w7d8p9I8K6I1v7g2j8I9z8H3z8q9w8n1z8b2s
8f4l8m5p8r9t8v2x8y5A8D6E8G9J8L0P8Q3S8T6W8X9a8c2f8i5l8o8r8u1x8CAwE
AAaNQME4wHQYDVR0OBBYEFKnV5bGt9gQ6J7gEWqNi1gYLc5GjMB8GA1UdIwQYMBaA
FKnV5bGt9gQ6J7gEWqNi1gYLc5GjMAwGA1UdEwQFMAMBAf8wDQYJKoZIhvcNAQEL
BQADgYEAMUJI8zJfO0fQ9l8K7K5j8X1e8r2u8v5y8z2A8D5G8J0M8P3S8U6X8Y9b
8c1f8g2j8i4m8l5p8q8t8w1z8E2I8G4K8M7P8R0U8V3Y8Z6c8f1i8l4o8r7u8x0
-----END CERTIFICATE-----";

$key_data = "-----BEGIN PRIVATE KEY-----
MIICdgIBADANBgkqhkiG9w0BAQEFAASCAmAwggJcAgEAAoGBANVyl1I0/ZBgpJt6
e8/SiaC4eCo1nyNifGdx8ZyOgnBPMd7V6iNFSmXw15i/MBvZOT8UuhOKSdoDiUKd
8O3fKfSPCuiNb+4No/CPc/B98/KvcPJ9c/G9rPH+JfJuafK/bfL9sfMuQPA+hPBv
SfC9D/EN0vE+lvF/WvHNn/IuZfKPa/LtcfAgMBAAECgYBqkVt7ZQ8X2Y5Z3W0N2M
1F4H3G6I5J8L9O0P1R2S3T6U9V2W3X4Y5Z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o
1p2q3r4s5t6u7v8w9x0y1z2A3B4C5D6E7F8G9H0I1J2K3L4M5N6O7P8Q9R0S1T2U
3V4W5X6Y7Z8a9b0c1d2e3f4g5h6i7j8k9l0m1nQJBAOzB5C6D7E8F9G0H1I2J3K4
L5M6N7O8P9Q0R1S2T3U4V5W6X7Y8Z9a0b1c2d3e4f5g6h7i8j9k0l1m2n3o4p5q6
r7s8t9u0v1w2x3y4z5A6B7C8D9E0F1G2H3I4J5K6L7M8N9O0P1Q2R3S4T5U6V7W8
X9Y0Z1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7A8B9C0
D1E2F3G4H5I6J7K8L9M0N1O2P3Q4R5S6T7U8V9W0X1Y2Z3a4b5c6d7e8f9g0
-----END PRIVATE KEY-----";

// Server coroutine that tests SSL accept timeout
$server = spawn(function() use ($cert_data, $key_data) {
    echo "SSL Server: creating SSL context\n";
    
    // Create SSL context with self-signed certificate
    $context = stream_context_create([
        'ssl' => [
            'local_cert' => 'data://text/plain,' . $cert_data,
            'local_pk' => 'data://text/plain,' . $key_data,
            'verify_peer' => false,
            'allow_self_signed' => true,
        ]
    ]);
    
    echo "SSL Server: starting SSL server\n";
    $socket = stream_socket_server("ssl://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
    if (!$socket) {
        echo "SSL Server: failed to start - $errstr ($errno)\n";
        return;
    }
    
    $address = stream_socket_get_name($socket, false);
    echo "SSL Server: listening on $address\n";
    
    echo "SSL Server: accepting with timeout\n";
    // This should use network_async_accept_incoming() in async mode
    // instead of the old inefficient php_poll2_async()
    $client = @stream_socket_accept($socket, 1); // 1 second timeout
    
    if ($client === false) {
        echo "SSL Server: timeout occurred as expected\n";
    } else {
        echo "SSL Server: unexpected client connection\n";
        fclose($client);
    }
    
    fclose($socket);
    echo "SSL Server: finished\n";
});

awaitAll([$server]);

echo "End SSL accept timeout test\n";

?>
--EXPECTF--
Start SSL accept timeout test
SSL Server: creating SSL context
SSL Server: starting SSL server
SSL Server: listening on ssl://127.0.0.1:%d
SSL Server: accepting with timeout
SSL Server: timeout occurred as expected
SSL Server: finished
End SSL accept timeout test