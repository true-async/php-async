# <img src="true-async-logo.png" alt="PHP TRUE ASYNC Logo" height="48" style="vertical-align: middle; margin-right: 12px;" /> PHP TRUE ASYNC

**PHP TRUE ASYNC** is an experimental `PHP` extension providing true asynchronous execution,
tightly integrated at the core level. Write concurrent code using familiar PHP syntax —
no callbacks, no promises, no framework required.

---

## Table of Contents

- [Features](#features)
- [Installation](#installation)
    - [Unix / macOS](#unix--macos)
    - [Windows](#windows)
- [Quick Start](#quick-start)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [License](#license)
- [Links](#links)

---

## Features

- **Coroutines** — Lightweight execution units that automatically suspend on blocking I/O, allowing other coroutines to run concurrently.
- **Scope** — Structured concurrency container ensuring all child coroutines are properly awaited or cancelled, with hierarchical cancellation support.
- **Future** — Represents a value not yet available, with chaining via `map` / `catch` / `finally` and lazy evaluation through `await`.
- **Channels** — CSP-style primitives for safe data transfer between coroutines, with buffered queues and backpressure.
- **Context** — Hierarchical key-value storage propagated implicitly through scopes and coroutines, similar to Go's `context.Context`.
- **Cancellation** — Cooperative cancellation with critical section support (`protect`) and cascading cancellation through scope hierarchies.
- **Pool** — Universal resource pool for managing reusable objects (connections, sockets) with health checks and circuit breaker patterns.
- **TaskGroup** — High-level structured concurrency with multiple completion strategies (`all` / `race` / `any`) and concurrency limits.
- **PDO Pool** — Transparent built-in connection pool for PDO, with automatic transaction management and health checks.
- **FileSystemWatcher** — Persistent filesystem event observer with coalesced and raw event delivery modes.
- **50+ async-aware PHP functions** — DNS, sockets, streams, cURL, PDO, MySQLi, PostgreSQL, process execution, sleep/timers, and more — all automatically non-blocking inside coroutines.

---

## Installation

PHP TRUE ASYNC requires **PHP 8.6+** and **LibUV ≥ 1.45.0**.

> Full installation instructions and pre-built packages: **[true-async.github.io/download](https://true-async.github.io/download.html)**

### Unix / macOS

1. **Clone the PHP repository:**

   ```bash
   git clone https://github.com/true-async/php-src -b true-async-api ./php-src
   ```

2. **Clone the extension into the `ext` directory:**

   ```bash
   git clone https://github.com/true-async/php-async ./php-src/ext/async
   ```

3. **Install dependencies:**

   ```bash
   # Debian/Ubuntu
   sudo apt-get install php-dev build-essential autoconf libtool pkg-config libuv1-dev

   # macOS
   brew install autoconf automake libtool pkg-config libuv
   ```

   > **Note:** LibUV 1.45.0+ is required. Older versions have a critical busy-loop issue in `UV_RUN_ONCE` mode causing high CPU usage.

4. **Configure and build:**

   ```bash
   ./buildconf
   ./configure --enable-async
   make && sudo make install
   ```

### Windows

1. Set up [php-sdk](https://wiki.php.net/internals/windows/stepbystepbuild_sdk_2).
2. Build or install LibUV via [vcpkg](https://github.com/microsoft/vcpkg).
3. Copy LibUV headers to `%PHP_SDK_PATH%\deps\include\libuv\` and `libuv.lib` to `%PHP_SDK_PATH%\deps\lib\`.
4. Build:

   ```
   buildconf
   configure --enable-async
   nmake
   ```

### Docker

```bash
docker build -t true-async-php .
docker run -it true-async-php bash
docker run --rm true-async-php php -m | grep true_async
```

---

## Quick Start

```php
<?php

// Spawn multiple concurrent coroutines
Async\spawn(function() {
    $ip = gethostbyname('github.com'); // non-blocking
    echo "GitHub: $ip\n";
});

Async\spawn(function() {
    sleep(1); // non-blocking — other coroutines continue
    echo "Done after 1s\n";
});

// Structured concurrency with Scope
$scope = new Async\Scope();

$scope->spawn(function() use ($scope) {
    $result = file_get_contents('https://example.com'); // non-blocking
    echo strlen($result) . " bytes\n";
});

$scope->awaitCompletion();
```

---

## Documentation

- **[Documentation](https://true-async.github.io/)** — full reference and guides
- **[Supported Functions](https://true-async.github.io/docs/reference/supported-functions.html)** — complete list of async-aware PHP functions
- **[Download & Installation](https://true-async.github.io/download.html)** — packages and build instructions

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
- 🌐 [true-async.github.io](https://true-async.github.io/)

---

> _PHP TRUE ASYNC — modern async PHP, today!_
