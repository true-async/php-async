# <img src="true-async-logo.png" alt="PHP TRUE ASYNC Logo" height="48" style="vertical-align: middle; margin-right: 12px;" /> PHP TRUE ASYNC

**PHP TRUE ASYNC** is an experimental `PHP` extension that provides a true asynchronous, 
tightly integrated at the core level.

---

## Table of Contents

- [Features](#features)
- [Installation](#installation)
    - [Unix / macOS](#unix--macos)
    - [Windows](#windows)
- [Adapted PHP Functions](#adapted-php-functions)
- [Quick Start](#quick-start)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [License](#license)
- [Links](#links)

---

## Features

*Coming soon!*

---

## Installation

PHP TRUE ASYNC is supported for PHP 8.5.0 and later.
`LibUV` is the primary reactor implementation for this extension.

### Docker (for tests)

```bash
# Build the image
docker build -t true-async-php .

# Run interactively
docker run -it true-async-php bash

# Check TrueAsync module
docker run --rm true-async-php php -m | grep true_async
```

### Requirements

- **PHP 8.5.0+**
- **LibUV ≥ 1.44.0** (required) - Fixes critical `UV_RUN_ONCE` busy loop issue that could cause high CPU usage

### Why LibUV 1.44.0+ is Required

Prior to libuv 1.44, there was a critical issue in `uv__io_poll()`/`uv__run_pending` logic that could cause the event loop to "stick" after the first callback when running in `UV_RUN_ONCE` mode, especially when new ready events appeared within callbacks. This resulted in:

- **High CPU usage** due to busy loops
- **Performance degradation** in async applications
- **Inconsistent event loop behavior** affecting TrueAsync API reliability

The fix in libuv 1.44 ensures that `UV_RUN_ONCE` properly returns after processing all ready callbacks in the current iteration, meeting the "forward progress" specification requirements. This is essential for TrueAsync's performance and reliability.

---

### Unix / macOS

1. **Clone the PHP repository:**

    for example, basic directory name is `php-src`:

   ```
   git clone https://github.com/true-async/php-src -b true-async-api ./php-src
   ```

2. **Clone the `True Async` extension repository:**

    to the `ext` directory of your PHP source:

    ```
    git clone https://github.com/true-async/php-async ./php-src/ext/async
    ```

3. **Install PHP development tools:**

    Make sure you have the necessary development tools installed. On Debian/Ubuntu, you can run:
    
    ```
    sudo apt-get install php-dev build-essential autoconf libtool pkg-config
    ```
    
    For macOS, you can use Homebrew:
    
    ```
    brew install autoconf automake libtool pkg-config
    ```

4. **Install LibUV:**:
   
**IMPORTANT:** LibUV version 1.44.0 or later is required.

For Debian/Ubuntu:
```bash
# Check if system libuv meets requirements (≥1.44.0)
pkg-config --modversion libuv

# If version is too old, install from source:
wget https://github.com/libuv/libuv/archive/v1.44.0.tar.gz
tar -xzf v1.44.0.tar.gz
cd libuv-1.44.0
mkdir build && cd build
cmake .. -DCMAKE_BUILD_TYPE=Release
make -j$(nproc)
sudo make install
sudo ldconfig
```

For macOS:
```bash
# Homebrew usually has recent versions
brew install libuv
```

Please see the [LibUV installation guide](https://github.com/libuv/libuv) for more details.

5. **Configure and build:**

   ```
   ./buildconf
   ./configure --enable-async
   make && sudo make install
   ```

   We can use `--enable-debug` to enable debug mode, which is useful for development.
   
   **Note:** The `--enable-experimental-async-api` option is no longer needed as the Async API is now enabled by default in the core.

---

### Windows

1. **Install php-sdk:**  
   Download and set up [php-sdk](https://wiki.php.net/internals/windows/stepbystepbuild_sdk_2) for building PHP extensions on Windows.

2. **Install and build LibUV:**  
   You can use [vcpkg](https://github.com/microsoft/vcpkg) or build libuv from source.

3. **Copy LibUV files to PHP SDK directories:**

   ```
   1. Copy everything from 'libuv\include' to '%PHP_SDK_PATH%\deps\include\libuv\'
   2. Copy 'libuv.lib' to '%PHP_SDK_PATH%\deps\lib\'
   ```
   `%PHP_SDK_PATH%` is your php-sdk installation root.

4. **Configure and build the extension with PHP:**

   ```
   cd \path\to\php-src
   buildconf
   configure --enable-async
   nmake
   ```

   **Note:** The `--enable-experimental-async-api` option is no longer needed as the Async API is now enabled by default in the core.

---

## Adapted PHP Functions

**50+ PHP functions** have been adapted to work asynchronously when used within coroutines:

### DNS Functions
- `gethostbyname()` - resolve hostname to IP address
- `gethostbyaddr()` - resolve IP address to hostname  
- `gethostbynamel()` - get list of IP addresses for hostname

### Database Functions
- **PDO MySQL** - async-compatible PDO operations
  - `PDO::__construct()`, `PDO::prepare()`, `PDO::exec()` - non-blocking
  - `PDOStatement::execute()`, `PDOStatement::fetch()` - async data access
- **MySQLi** - async-compatible MySQLi operations  
  - `mysqli_connect()`, `mysqli_query()`, `mysqli_prepare()` - non-blocking
  - `mysqli_stmt_execute()`, `mysqli_fetch_*()` - async result fetching

### CURL Functions  
- `curl_exec()` - execute cURL request
- `curl_multi_exec()` - execute multiple cURL handles
- `curl_multi_select()` - wait for activity on multiple cURL handles
- `curl_multi_getcontent()` - get content from multi handle
- `curl_setopt()`, `curl_getinfo()`, `curl_error()`, `curl_close()` - async-aware

### Socket Functions
- `socket_connect()`, `socket_accept()` - connection operations
- `socket_read()`, `socket_write()` - data transfer
- `socket_send()`, `socket_recv()` - data exchange
- `socket_sendto()`, `socket_recvfrom()` - addressed data transfer
- `socket_bind()`, `socket_listen()` - server operations
- `socket_select()` - monitor socket activity

### Stream Functions
- `file_get_contents()` - get file/URL contents
- `fread()`, `fwrite()` - file I/O operations
- `fopen()`, `fclose()` - file handle management
- `stream_socket_client()`, `stream_socket_server()` - socket streams
- `stream_socket_accept()` - accept stream connections
- `stream_select()` - monitor stream activity
- `stream_context_create()` - async-aware context creation

### Process Execution Functions
- `proc_open()` - open process with pipes
- `exec()` - execute external command
- `shell_exec()` - execute shell command
- `system()` - execute system command  
- `passthru()` - execute and pass output directly

### Sleep/Timer Functions
- `sleep()` - delay execution (seconds)
- `usleep()` - delay execution (microseconds)
- `time_nanosleep()` - nanosecond precision delay
- `time_sleep_until()` - sleep until timestamp

### Output Buffer Functions
- `ob_start()` - start output buffering with coroutine isolation
- `ob_flush()`, `ob_clean()` - buffer operations with isolation
- `ob_get_contents()`, `ob_end_clean()` - get/end buffer with isolation

All functions automatically become non-blocking when used in async context, allowing other coroutines to continue execution while waiting for I/O operations to complete.

---

## Quick Start

### Basic Coroutine Example

```php
<?php

// Spawn multiple concurrent coroutines
Async\spawn(function() {
    echo "Starting coroutine 1\n";
    sleep(2); // Non-blocking in async context
    echo "Coroutine 1 completed\n";
});

Async\spawn(function() {
    echo "Starting coroutine 2\n";
    sleep(1); // Non-blocking in async context
    echo "Coroutine 2 completed\n";
});

echo "All coroutines started\n";
```

### Concurrent DNS Lookups

```php
<?php

$start = microtime(true);

// Start multiple DNS lookups concurrently
Async\spawn(function() {
    $ip = gethostbyname('github.com'); // Non-blocking
    $ips = gethostbynamel('github.com'); // Get all IPs
    echo "GitHub: $ip (" . count($ips) . " total IPs)\n";
});

Async\spawn(function() {
    $ip = gethostbyname('google.com'); // Non-blocking
    $hostname = gethostbyaddr($ip); // Reverse lookup
    echo "Google: $ip -> $hostname\n";
});

Async\spawn(function() {
    $content = file_get_contents('http://httpbin.org/ip'); // Non-blocking
    echo "External IP: " . json_decode($content)->origin . "\n";
});

$elapsed = microtime(true) - $start;
echo "All operations completed in: " . round($elapsed, 3) . "s\n";
```

### Concurrent HTTP Requests with CURL

```php
<?php

$urls = [
    'https://httpbin.org/delay/1',
    'https://httpbin.org/delay/2',
    'https://httpbin.org/delay/1'
];

$start = microtime(true);

foreach ($urls as $i => $url) {
    Async\spawn(function() use ($url, $i) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch); // Non-blocking
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "Request $i: HTTP $httpCode\n";
    });
}

$elapsed = microtime(true) - $start;
echo "All requests completed in: " . round($elapsed, 3) . "s\n";
```

### Async Database Operations

```php
<?php

// Concurrent database queries with PDO MySQL
Async\spawn(function() {
    $pdo = new PDO('mysql:host=localhost;dbname=test', $user, $pass);
    
    // All operations are non-blocking in async context
    $stmt = $pdo->prepare("SELECT * FROM users WHERE active = ?");
    $stmt->execute([1]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "User: {$row['name']}\n";
    }
});

// MySQLi concurrent operations
Async\spawn(function() {
    $mysqli = new mysqli('localhost', $user, $pass, 'test');
    
    // Non-blocking query execution
    $result = $mysqli->query("SELECT COUNT(*) as total FROM orders");
    $row = $result->fetch_assoc();
    echo "Total orders: {$row['total']}\n";
    
    $mysqli->close();
});

echo "Database queries started\n";
```

### Process Execution

```php
<?php

// Execute multiple commands concurrently
Async\spawn(function() {
    $output = shell_exec('sleep 2 && echo "Command 1 done"'); // Non-blocking
    echo $output;
});

Async\spawn(function() {
    $output = shell_exec('sleep 1 && echo "Command 2 done"'); // Non-blocking
    echo $output;
});

echo "Commands started\n";
```

### Output Buffering with Coroutine Isolation

```php
<?php

// Each coroutine has isolated output buffer
Async\spawn(function() {
    ob_start(); // Isolated buffer
    echo "Output from coroutine 1\n";
    echo "More output from coroutine 1\n";
    $buffer1 = ob_get_contents();
    ob_end_clean();
    
    echo "Coroutine 1 captured: $buffer1";
});

Async\spawn(function() {
    ob_start(); // Separate isolated buffer
    echo "Output from coroutine 2\n";
    $buffer2 = ob_get_contents();
    ob_end_clean();
    
    echo "Coroutine 2 captured: $buffer2";
});

echo "Buffers are isolated between coroutines\n";
```

---

## Documentation

- [Documentation (coming soon)](https://github.com/true-async/php-async-docs)

---

## Contributing

Pull requests and suggestions are welcome!  
Please read [CONTRIBUTING.md](CONTRIBUTING.md) before starting.

---

## License

[MIT](LICENSE)

---

## Links

- 🛠️ [php-src/true-async-api](https://github.com/true-async/php-src)
- 🔌 [php-async](https://github.com/true-async/php-async)
- 📄 [php-true-async-rfc](https://github.com/true-async/php-true-async-rfc)

---

> _PHP TRUE ASYNC — modern async PHP, today!_
