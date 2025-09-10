--TEST--
SSL Stream: full SSL client-server async communication
--SKIPIF--
<?php if (!extension_loaded('openssl')) die('skip openssl extension not available'); ?>
--FILE--
<?php

use function Async\spawn;
use function Async\awaitAll;
use function Async\delay;

echo "Start SSL client-server test\n";

// Create a valid self-signed certificate for testing
$cert_data = "-----BEGIN CERTIFICATE-----
MIIDEzCCAfugAwIBAgIUM6QzqVbtcWFuFC3tUezaYP0p/WkwDQYJKoZIhvcNAQEL
BQAwGTEXMBUGA1UEAwwOYXN5bmMtdGVzdC5kZXYwHhcNMjUwOTEwMjAxNjQzWhcN
MjYwOTEwMjAxNjQzWjAZMRcwFQYDVQQDDA5hc3luYy10ZXN0LmRldjCCASIwDQYJ
KoZIhvcNAQEBBQADggEPADCCAQoCggEBALS+/rtv9RfG2G+JJrX3IVoTKWcHMrld
yn7qP0oFDW8epymmKjmmnCH7Y4t9i/3U6e+wPlpiO6xGcLOyRgqyv0X/FvDACtM1
ymFXI2kIjySIcaa5yyESMFuR13xLXRqaYIiz68uK1ttjR4XFZADSIUC0QJ3S6caY
GwXBcUTOoPFxDPA5luB7gOSRavniGw/EU/ZC4FgV7qxo64CHbDZZBMWWlganPSh8
DBO4CHQO5ZtoFlHMPktzHFZFDyZaZNhtuibqg8DNNW21YkfpGQWmgk3J2/3bGdoh
TQ9nGWQELndRi+0npGkVb5DXrRyz/ChlzhPlNjB2wPr2m6Xvz8y8Om0CAwEAAaNT
MFEwHQYDVR0OBBYEFN++t8je1cBxmZ72HtaSsQbAB4sJMB8GA1UdIwQYMBaAFN++
t8je1cBxmZ72HtaSsQbAB4sJMA8GA1UdEwEB/wQFMAMBAf8wDQYJKoZIhvcNAQEL
BQADggEBAG1+/NXvBiADSFigpYbGNPZfNsBLEOsIr7FyeIDVJm9wgf6HHUdRcif5
O7iROqMzxoaxDkeNvma39VWcAVEaNqG+HVD+grRdWEvTqJT55hcTlCn/RSaTpPMB
QcgS2h/el+VlHMBo1MozD5+5XeNfyk1zGsU/YH4I1ffWc+uP8l68Vr8Li71e2Ldv
ZL8FITD5e3oKj5p2G9qb1bqadZqvGaPfHRgElk8MPDCGzHmJynN6d+W0gMltM9CP
KLueRgg/K677uCvGPJP3jjBqPr4FgpmnZXsLArzl9PiLrJJ/M6IDmKFLIv0Cu9Nf
uLR0cglXQ2Tq5SvmfIj03jS7R16Gy1U=
-----END CERTIFICATE-----";

$key_data = "-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQC0vv67b/UXxthv
iSa19yFaEylnBzK5Xcp+6j9KBQ1vHqcppio5ppwh+2OLfYv91OnvsD5aYjusRnCz
skYKsr9F/xbwwArTNcphVyNpCI8kiHGmucshEjBbkdd8S10ammCIs+vLitbbY0eF
xWQA0iFAtECd0unGmBsFwXFEzqDxcQzwOZbge4DkkWr54hsPxFP2QuBYFe6saOuA
h2w2WQTFlpYGpz0ofAwTuAh0DuWbaBZRzD5LcxxWRQ8mWmTYbbom6oPAzTVttWJH
6RkFpoJNydv92xnaIU0PZxlkBC53UYvtJ6RpFW+Q160cs/woZc4T5TYwdsD69pul
78/MvDptAgMBAAECggEABtVBpBLNEHBAAz3YIAD1WqAAbLJLXjg7GfLMU1JyYF6n
fl39zET0M7fhQSDAVIEVs8eh7W2eFYE3bLoZbV+2KdtFVSZHD0nbtADE7rxk5o8R
9nYHq+ExwTerDqCt85eEFsCJvx3C/Nnf/LqqcS2AfFKHYgmoAaec14Opcirgz4yg
p6/ABbIShFT5Yn3EcdczBNrWlxsZ8tnKHpQFnwZwKh1eKf7dIRUbdGLUM50+fa7g
rL0cl9PdK7rBOR5LBjCZUv3DkW3GyuW1/2DFv0DTjIcrW6+YF/1ow9smlbYhIsei
QklBP7+vh63CUyMLEf6QHI458xGl9t9pDclmT7FPsQKBgQDoZCRIkfJsQomoHaMM
Fx+SQ2T6p0ke368PtRXo4D7o6nF0bXJ4uEPq4sFIQP+0xPOBY3MROP4PBDx1eV0H
sdffCfCnfISnoZKkUbvOQDFzbCiLtpDKTY71pS0A7gyU1N/2paozyxs3xl0GMksb
2l80ocfPWikqDP+F03nHlDbLCwKBgQDHG7SfftdBbz8hujHxVitnl89oAso5Eq8Y
WNHf7prP/8VMe4HlwZMAOH9+UIChsprGfAJo/JZtKoeNH9r2WokC1SfNovTLuSeG
zBB+GdX6Pdi1PiP6nfC+nCmkUPGvXO3hS0KNCARghpBux3fbkY5byhJ6yjGLI034
Gx0+lM87ZwKBgHkwqB9URSkh9em/MuU+Nc+v57wzexVnr0Kwu/FK6GPMx0fhP74m
0fxvLj7A7tjVkOtb8oj7wLoSCnl0xggaPapp459kd0V4JCIfIaKopWE8+VQK7C0k
DzaZYgPHILaI4RceQ8lo1RPcFW0C01p+IgIvkCTZLvhn+OVQaISlDYILAoGAcFld
zjHQXIfdY7agv8ETtNygl9wbJ6E3U9Gqe2UzzfJQ7hsy7OYRgKpgpnHeY19Ynm8T
HRKJ/wdkfWlgMGpdrU+BqjMtVlcfypwTIlSJvS5wvbRWsO+2DJgplyJlfcI+KEZD
Qzkm3yCPFzNOmoLDhV+8lbTJx+0f7cO++LUXSjkCgYBwl8zEEmxtaFXh7MlB+bdp
oWyyjYBYi38ppg0LaJLt744KSX/SCwJm0lrBdAMS4KVnyLyLCrhkFpKZmfaLoiy2
/+1X6hVUYCC+gys512sg3up+h0nbNp8eW8XCmqzDL3XS4r9CSRatelMdDpAmusnU
qEGp5GoqyrUfxJZ8BywxeQ==
-----END PRIVATE KEY-----";

// Shared variables for communication
$address = null;
$output = [];

// SSL Server coroutine
$server = spawn(function() use (&$address, &$output, $cert_data, $key_data) {
    echo "SSL Server: creating SSL context\n";
    
    // Create temporary files for Windows compatibility
    $cert_file = tempnam(sys_get_temp_dir(), 'ssl_cert') . '.pem';
    $key_file = tempnam(sys_get_temp_dir(), 'ssl_key') . '.pem';
    file_put_contents($cert_file, $cert_data);
    file_put_contents($key_file, $key_data);
    
    $context = stream_context_create([
        'ssl' => [
            'local_cert' => $cert_file,
            'local_pk' => $key_file,
            'verify_peer' => false,
            'allow_self_signed' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_SERVER,
        ]
    ]);
    
    // Cleanup function
    $cleanup = function() use ($cert_file, $key_file) {
        if (file_exists($cert_file)) unlink($cert_file);
        if (file_exists($key_file)) unlink($key_file);
    };
    
    $socket = stream_socket_server("ssl://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);
    if (!$socket) {
        echo "SSL Server: failed to create socket - $errstr\n";
        return;
    }
    
    $server_address = stream_socket_get_name($socket, false);
    // Client needs ssl:// prefix to connect via SSL
    $address = 'ssl://' . $server_address;
    echo "SSL Server: listening on $server_address\n";
    
    echo "SSL Server: waiting for SSL connection\n";
    // This should use network_async_accept_incoming() instead of php_poll2_async()
    $client = stream_socket_accept($socket, 10); // 10 second timeout
    
    if (!$client) {
        echo "SSL Server: failed to accept client\n";
        return;
    }
    
    $output[] = "SSL Server: client connected";
    
    $data = fread($client, 1024);
    $output[] = "SSL Server: received '$data'";
    
    fwrite($client, "Hello from SSL server");
    $output[] = "SSL Server: response sent";
    
    fclose($client);
    fclose($socket);
    $cleanup();
});

// SSL Client coroutine
$client = spawn(function() use (&$address, &$output) {
    // Wait for server to set address
    while ($address === null) {
        delay(10);
    }
    
    echo "SSL Client: connecting to $address\n";
    
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
        ]
    ]);
    
    $sock = stream_socket_client($address, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    if (!$sock) {
        echo "SSL Client: failed to connect - $errstr ($errno)\n";
        return;
    }
    
    $output[] = "SSL Client: connected successfully";
    
    fwrite($sock, "Hello from SSL client");
    $output[] = "SSL Client: sent request";
    
    $response = fread($sock, 1024);
    $output[] = "SSL Client: received '$response'";
    
    fclose($sock);
});

// Worker coroutine to test concurrent execution
$worker = spawn(function() {
    echo "Worker: doing work while SSL operations happen\n";
    delay(100); // Give some time for SSL handshake
    echo "Worker: finished\n";
});

awaitAll([$server, $client, $worker]);

// Sort output for deterministic results
sort($output);
foreach ($output as $message) {
    echo $message . "\n";
}

echo "End SSL client-server test\n";

?>
--EXPECTF--
Start SSL client-server test
SSL Server: creating SSL context
SSL Server: listening on ssl://127.0.0.1:%d
Worker: doing work while SSL operations happen
SSL Server: waiting for SSL connection
SSL Client: connecting to ssl://127.0.0.1:%d
Worker: finished
SSL Client: connected successfully
SSL Client: received 'Hello from SSL server'
SSL Client: sent request
SSL Server: client connected
SSL Server: received 'Hello from SSL client'
SSL Server: response sent
End SSL client-server test