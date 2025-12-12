<?php

/**
 * Cross-platform socket pair creation helper
 *
 * @return array|false Array of two socket resources or false on failure
 */
function create_socket_pair() {
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows - use INET domain
        return stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    } else {
        // Unix/Linux - use UNIX domain with STREAM_IPPROTO_IP
        return stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    }
}

/**
 * Helper function to wait for server address with retry logic
 * Makes tests more reliable by avoiding race conditions
 *
 * @param object $server_coroutine The server coroutine to get result from
 * @param int $max_attempts Maximum number of retry attempts (default: 5)
 * @param int $delay_ms Delay between attempts in milliseconds (default: 10)
 * @return string Server address
 * @throws Exception If server address cannot be obtained after max attempts
 */
function wait_for_server_address($server_coroutine, $max_attempts = 5, $delay_ms = 10) {
    for ($attempts = 0; $attempts < $max_attempts; $attempts++) {
        \Async\delay($delay_ms);
        $address = $server_coroutine->getResult();
        if ($address) {
            return $address;
        }
    }
    throw new Exception("Failed to get server address after $max_attempts attempts");
}