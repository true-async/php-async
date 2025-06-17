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