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
   
Please see the [LibUV installation guide](https://github.com/libuv/libuv)

5. **Configure and build:**

   ```
   ./buildconf
   ./configure --enable-experimental-async-api --enable-async
   make && sudo make install
   ```

   We can use `--enable-debug` to enable debug mode, which is useful for development.

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
