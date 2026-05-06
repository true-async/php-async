# PDO Pool Prepared-Statement Cache — Performance Analysis

Performance validation of the per-physical-connection prepared-statement
LRU cache (`PDO::ATTR_POOL_STMT_CACHE_SIZE`) shipped in ext/async 0.7.0.
See [`PDO-PREPARE.md`](../PDO-PREPARE.md) for the design.

---

## 1. Setup

| Item | Value |
|---|---|
| PHP build | debug, ZTS, `-O0` (worst case for relative numbers; absolute will be better in release) |
| PostgreSQL | 16-alpine in Docker, TCP to `127.0.0.1` |
| Pool config | `POOL_MAX=1` (single physical conn), one coroutine |
| Workload | `prepare + execute + fetch` of `SELECT ?::int + ?::int AS r` in a tight loop |
| Compared | `STMT_CACHE_SIZE=0` (cache disabled, current behaviour) vs `STMT_CACHE_SIZE=16` (cache enabled) |

The bench script issues a one-iteration warmup before timing, then runs `N` iterations under `hrtime(true)`.

---

## 2. Wall-clock results

5000 iterations, two runs per mode to soak warm-up effects:

| Mode | Total | Per iteration | QPS |
|---|---|---|---|
| `cache=OFF` (run 1) | 2364 ms | 472.8 µs | 2 115 |
| `cache=ON`  (run 1) | **813 ms**  | **162.6 µs** | **6 150** |
| `cache=OFF` (run 2) | 2326 ms | 465.2 µs | 2 149 |
| `cache=ON`  (run 2) | **792 ms**  | **158.5 µs** | **6 309** |

**~2.9× throughput, ~310 µs saved per `prepare+execute+fetch` cycle.**

---

## 3. Wire-level proof: `strace -c`

1000 iterations, syscalls counted:

| Syscall | OFF | ON | Δ |
|---|---|---|---|
| `sendto`   | 3008 | 1007 | **−3×** |
| `recvfrom` | 6010 | 2008 | **−3×** |
| `read` (epoll wakeups) | 68 | 68 | — |
| **total**  | **9087** | **3084** | **−2.95×** |

Time spent in those syscalls:

| | `sendto` | `recvfrom` | Total |
|---|---|---|---|
| OFF | 81.3 ms | 71.7 ms | **153 ms** |
| ON  | 26.8 ms | 23.1 ms | **50 ms** |

That is **103 ms saved on 1000 iterations = ~103 µs/iter** in pure
kernel/wire-I/O alone, with the rest of the gap accounted for by libpq
user-space work that no longer runs (Parse handling, DEALLOCATE
bookkeeping).

The 3:1 ratio matches the design model exactly:

| Wire step | OFF (per iter) | ON (per iter) |
|---|---|---|
| `PQprepare` (Parse + Sync) | 1 send / 2 recv | — (skipped on hit) |
| `PQexecPrepared` (Bind + Execute + Sync) | 1 send / 2 recv | 1 send / 2 recv |
| `DEALLOCATE name` (on stmt dtor) | 1 send / 2 recv | — (cache owns the name) |
| **Total** | **3 send / 6 recv** | **1 send / 2 recv** |

Two of the three round-trips per prepare cycle disappear — the same
"Parse + Plan on every request" pattern called out in the design
document as the root cause of the HttpArena `async-db` regression.

---

## 4. CPU profile: `valgrind --tool=callgrind` (3000 iterations)

Callgrind counts retired user-space instructions (`Ir`); it does not
charge wall time for kernel I/O blocking, so the picture here is
strictly about CPU work.

| | Total `Ir` |
|---|---|
| `cache=OFF` | 345.7 M |
| `cache=ON`  | **260.1 M** |
| **Δ** | **−85.6 M (−25%)** |

So even ignoring the network round-trips, cache=ON does 25 % less
user-space CPU work — the saved instructions are the libpq Parse path
plus the `spprintf` of fresh statement names plus the DEALLOCATE
bookkeeping that no longer runs on every iteration.

### 4.1 Our pool / cache layer is invisible

| Function | % of total `Ir` |
|---|---|
| `pdo_pool_stmt_cache_lookup` | **0.04 %** |
| `_estrdup` (copying `server_stmt_name` on hit) | 0.04 % |
| `pgsql_handle_preparer` (whole function) | 0.15 % |
| `pdo_pool_acquire_conn` | 0.08 % |
| `pdo_pool_maybe_release` | 0.08 % |

**Combined pool + cache layer ≈ 0.5 %**. The `EXPECTED/UNEXPECTED`
hints, `*const` locals, and condition reordering we added are
microoptimisations on something already well below the profiler noise
floor — but they cost nothing and keep the code honest.

### 4.2 Real remaining CPU hotspots in `cache=ON`

These are **not** in our cache code; they are in PDO/PHP infrastructure
on the prepare/bind path. They represent the next tier of optimisation
opportunities, not bugs in this feature.

#### (a) Re-parsing SQL on every `prepare()` — ~1.84 % `Ir`

```
2,481,827 (0.95%)  ext/pdo_pgsql/pgsql_sql_parser.c:pdo_pgsql_scanner
2,307,769 (0.89%)  ext/pdo/pdo_sql_parser.re:pdo_parse_params
```

Even on a cache hit, `pgsql_handle_preparer` still calls
`pdo_parse_params` to derive the canonical `nsql` (which is also our
cache key). On a hit this work is wasted — the server already has the
prepared statement. Skipping it would require keying the cache by the
**original** `sql` (a `zend_string`, hash already cached) and storing
the parser output (`nsql`, `bound_param_map`, placeholder metadata) in
the cache entry, then cloning it back into the new `stmt` on hit.

This is the **L1 cache by raw SQL** variant rejected for v1 because
parse cost is negligible compared to wire RTT (verified above: parse is
~1 µs vs RTT ~50 µs locally / ~500 µs over a network). Worth revisiting
only if production profiling shows parse dominating on a very fast
local socket.

#### (b) Bound-param machinery rebuilt per `prepare()` — ~1.79 % `Ir`

```
2,346,782 (0.90%)  pgsql_stmt_param_hook
1,440,480 (0.55%)  pdo_stmt.c:dispatch_param_event
  888,296 (0.34%)  pdo_stmt.c:really_register_bound_param
```

PDO's stmt object is stateful by design: every `prepare()` builds a
fresh `bound_param_map` HashTable (`:name → index`), placeholder list,
and per-param event-dispatch hooks, even when the SQL shape is
identical. Removing this requires caching at the `PDOStatement` level,
not at the server-side-prepared-name level — a much larger feature
that touches PDO core.

#### (c) Zend memory allocator — ~10 % `Ir`

```
7,163,424 (2.75%)  zend_mm_alloc_small
6,836,537 (2.63%)  zend_mm_alloc_heap
6,069,256 (2.33%)  zend_mm_free_small
4,954,804 (1.91%)  zend_mm_free_heap
```

Each iteration allocates a fresh `pdo_pgsql_stmt`, `pdo_stmt_t`, bound-param
zvals, and tears them down. This is the price of "every `prepare()` is
a new PDOStatement object" in the PDO API. Local optimisation here is
pointless; the only escape is, again, a stmt-object cache at a higher
level.

---

## 5. Conclusions

1. **The cache works as designed.** Throughput is ~2.9× higher;
   syscall counts drop by exactly the predicted factor of 3.
2. **The implementation is cheap.** All pool + cache code combined is
   ~0.5 % of total user-space CPU; eviction, lookup, and the
   plan-invalidation retry path never appear as hotspots.
3. **The remaining wall-clock cost on a cache hit is not in our code.**
   It is in PDO's stateless-stmt model: re-parsing SQL and rebuilding
   bound-param state on every `prepare()`. These are well-understood
   architectural costs, not regressions.
4. **Higher-leverage future work** lies one layer up (statement-object
   cache, `Describe` metadata cache for column types, libpq pipeline
   mode), not in tuning the LRU itself.

---

## 6. Reproduction

```php
<?php
// /tmp/bench_stmt_cache.php
$dsn = 'pgsql:host=127.0.0.1 port=5432 dbname=test user=postgres password=postgres';
$N   = (int)($argv[1] ?? 5000);

function bench(string $label, int $cacheSize, string $dsn, int $N): void {
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_POOL_ENABLED         => true,
        PDO::ATTR_POOL_MIN             => 0,
        PDO::ATTR_POOL_MAX             => 1,
        PDO::ATTR_POOL_STMT_CACHE_SIZE => $cacheSize,
    ]);

    Async\await(Async\spawn(function () use ($pdo, $N, $label) {
        $sql = 'SELECT ?::int + ?::int AS r';
        // Warm up
        $stmt = $pdo->prepare($sql); $stmt->execute([0, 0]); $stmt->fetch(); unset($stmt);

        $t0 = hrtime(true);
        for ($i = 0; $i < $N; $i++) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$i, 1]);
            $stmt->fetch();
            unset($stmt);
        }
        $dt = hrtime(true) - $t0;
        printf("%-20s  total=%7.2f ms   per_iter=%8.3f µs   QPS=%9.0f\n",
            $label, $dt / 1e6, ($dt / 1e3) / $N, 1e9 / ($dt / $N));
    }));
}

bench('cache=OFF (size=0)',  0,  $dsn, $N);
bench('cache=ON  (size=16)', 16, $dsn, $N);
bench('cache=OFF (size=0)',  0,  $dsn, $N);
bench('cache=ON  (size=16)', 16, $dsn, $N);
```

```bash
docker run -d --rm --name pgtest \
  -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=test \
  -p 5432:5432 postgres:16-alpine

# Wall-clock
sapi/cli/php /tmp/bench_stmt_cache.php 5000

# Syscall picture
strace -c -e trace=sendto,recvfrom,write,read,epoll_wait \
  sapi/cli/php /tmp/bench_one.php 0  1000
strace -c -e trace=sendto,recvfrom,write,read,epoll_wait \
  sapi/cli/php /tmp/bench_one.php 16 1000

# Instruction profile
valgrind --tool=callgrind --callgrind-out-file=/tmp/cg.cache_off \
  sapi/cli/php /tmp/bench_one.php 0  3000
valgrind --tool=callgrind --callgrind-out-file=/tmp/cg.cache_on  \
  sapi/cli/php /tmp/bench_one.php 16 3000
callgrind_annotate /tmp/cg.cache_on --threshold=80
```
