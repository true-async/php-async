# AmphpPHP vs Async Extension Benchmark Comparison

Performance comparison between PHP Async extension and AmphpPHP v3.x

## Setup

### Install AmphpPHP dependencies:
```bash
cd /mnt/c/php/php-src/ext/async/benchmarks/amphp
composer install
```

## Running Servers

### 1. Async Extension Server (Keep-Alive optimized):
```bash
cd /mnt/c/php/php-src/ext/async/benchmarks
php http_server_keepalive.php 127.0.0.1 8080
```

### 2. AmphpPHP Server:
```bash
cd /mnt/c/php/php-src/ext/async/benchmarks/amphp  
php http_server_amphp.php 127.0.0.1 8081
```

## Benchmark Tests

### Test Async Extension:
```bash
wrk -t12 -c400 -d30s --http1.1 http://127.0.0.1:8080/benchmark
wrk -t12 -c400 -d30s --http1.1 http://127.0.0.1:8080/small
wrk -t12 -c400 -d30s --http1.1 http://127.0.0.1:8080/json
```

### Test AmphpPHP:
```bash
wrk -t12 -c400 -d30s --http1.1 http://127.0.0.1:8081/benchmark
wrk -t12 -c400 -d30s --http1.1 http://127.0.0.1:8081/small
wrk -t12 -c400 -d30s --http1.1 http://127.0.0.1:8081/json
```

## Expected Metrics to Compare

- **Requests per second (RPS)**
- **Latency (avg/p50/p90/p95/p99)**
- **Memory usage**
- **CPU utilization**
- **Connection handling efficiency**

## Features Comparison

| Feature | Async Extension | AmphpPHP |
|---------|----------------|----------|
| Keep-Alive | ✅ Custom optimized | ✅ Built-in |
| JSON Caching | ✅ Pre-cached | ✅ Pre-cached |
| Event Loop | ✅ libuv-based | ✅ ReactPHP/Revolt |
| Connection Pooling | ✅ Manual | ✅ Automatic |
| HTTP Compliance | ⚡ Minimal (perf) | ✅ Full HTTP/1.1 |

## Notes

- Both servers use identical response caching for fair comparison
- Same endpoints: `/`, `/health`, `/small`, `/json`, `/benchmark`
- Both optimized for maximum performance in benchmark scenarios
- Async extension should show lower latency due to C-level optimizations
- AmphpPHP may show better HTTP compliance and feature richness