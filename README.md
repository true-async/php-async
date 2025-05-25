# <img src="true-async-logo.png" alt="PHP TRUE ASYNC Logo" height="48" style="vertical-align: middle; margin-right: 12px;" /> PHP TRUE ASYNC

**PHP TRUE ASYNC** is an experimental `PHP` extension that provides a true asynchronous, 
tightly integrated at the core level.

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

*Coming soon!*

---

## Installation

PHP TRUE ASYNC is supported for PHP 8.5.0 and later.
`LibUV` is the primary reactor implementation for this extension.

---

### Unix / macOS

1. **Clone the repository:**

   ```
   git clone https://github.com/true-async/php-src -b true-async-api
   cd php-src/ext/async
   ```

2. **Prepare the build environment:**

   ```
   phpize
   ```

3. **Install LibUV:**:
   
Please see the [LibUV installation guide](https://github.com/libuv/libuv)

4. **Configure and build:**

   ```
   ./configure --enable-experimental-async-api --enable-async
   make && sudo make install
   ```

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
   cd \path\to\php-src\ext\async
   buildconf
   configure --enable-experimental-async-api --enable-async
   nmake
   ```

## Quick Start

```php
<?php

Async\spawn(function() {
    // Your async code here
});
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
