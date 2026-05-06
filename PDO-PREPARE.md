# PDO Pool: Transparent Prepared Statement Cache

**Status:** design / open task
**Owner:** ext/async
**Target:** `PDO::ATTR_POOL_*` (pdo_pgsql first; pdo_mysql later)

---

## 1. Problem

The PDO connection pool shipped with `ext/async` (`PDO::ATTR_POOL_ENABLED`)
gives every coroutine its own physical connection on demand. That fixes
concurrency, but it breaks the one-prepare-many-execute pattern that
applications rely on for throughput.

In an HttpArena `async-db` benchmark (PostgreSQL `SELECT ... WHERE price
BETWEEN ? AND ? LIMIT ?`, ~50 rows) we measure:

| Framework | Mode | req/s |
|---|---|---|
| Swoole (`enable_coroutine=false`, 1 PDO + 1 cached `PDOStatement` per worker process) | sync prefork | **~64,000** |
| true-async-server (coroutines, `PDO::ATTR_POOL_ENABLED`, prepare per request) | async pool | **~12,000–20,000** |
| true-async-server, *no DB* path | — | ~200,000 |

The drop from 200k → 12-20k is far more than one extra round-trip per
request would explain. Profiling and the protocol math both point at the
same root cause.

### Root cause

`PostgreSQL.php` does, on every request:

```php
$stmt = self::$pdo->prepare(self::SQL);  // (1)
$stmt->execute([$min, $max, $limit]);    // (2)
$rows = $stmt->fetchAll();               // (3)
```

With `ATTR_EMULATE_PREPARES => false` (the pdo_pgsql default — see §4):

* (1) → `pgsql_handle_preparer` allocates a new `pdo_pgsql_stmt`, generates
  a fresh statement name `pdo_stmt_%08x`, and *defers* the actual
  `PQprepare` to the first execute (lazy prepare, see
  `ext/pdo_pgsql/pgsql_driver.c:582`).
* (2) → first execute fires the deferred `PQprepare` (Parse + Describe,
  one full RTT to Postgres) **followed by** `PQexecPrepared` (Bind +
  Execute, second RTT). Two round trips, plus a parse + plan on the
  server.
* (3) → fetch.

A `PDOStatement` is bound to the `pdo_dbh_t` it was prepared on, and our
pool may hand a different physical connection to the next coroutine, so
the user has no way to cache the statement across requests. They are
forced into the worst case: every request pays Parse + Plan + Bind +
Execute, and Postgres' ReParse load becomes the bottleneck under high
fan-in.

Swoole's "win" in the benchmark is not from a smarter pool. Swoole's
`enable_coroutine=false` config simply runs N prefork worker *processes*,
each holding one persistent connection and one `PDOStatement` cached in
`workerStart`. Per request Swoole does only `execute()` + fetch — one
RTT, no Parse. Their actual `PDOPool`
(`swoole/library/src/core/Database/PDOPool.php`) does **not** cache
statements either; it's a thin wrapper over `ConnectionPool`.

So the comparison isn't "their pool vs. our pool" — it's "their cached
prepare per worker vs. our re-prepare per request". The fix is to make
caching the default for *our* pool.

---

## 2. State of the art (how everyone else solves this)

Every mature ecosystem has converged on the same shape: **a
per-physical-connection LRU cache keyed by SQL text, transparent to the
user**. The user just calls `query(sql, args)` (or `prepare(sql)` plus
`execute()`); the driver decides whether to issue a wire `Parse` or
reuse a server-side prepared statement.

| Stack | Mechanism | Default |
|---|---|---|
| **Go pgx** (`jackc/pgx`) | `StatementCacheCapacity` LRU per conn, modes `cache_statement` (named PREPARE) / `cache_describe` (unnamed Parse, cached Describe — pgbouncer-safe) | 512, on |
| **Go `database/sql`** | Lazy re-prepare per `(Stmt, conn)` tuple, no SQL-text cache | — (worst case) |
| **Rust sqlx** | Per-conn LRU keyed by SQL text | 100, on (`statement_cache_capacity`) |
| **Node `postgres.js`** (porsager) | Tagged-template hash, per-conn cache | on |
| **Node `pg`** | Only if user passes `name` — not transparent | off |
| **.NET Npgsql** | `Max Auto Prepare` + `Auto Prepare Min Usages`: after N executes promotes to server-side PREPARE, persistent across pool checkouts of the same physical conn, LRU-evicted | off by default; standard tuning enables it |
| **Java pgjdbc** | `prepareThreshold=5`: first 4 executes are unnamed Parse+Bind+Execute; 5th promotes to named server-side PREPARE; per-conn LRU `preparedStatementCacheQueries=256` / `preparedStatementCacheSizeMiB=5` | on |

Postgres-side primitives this exploits:

* **Named prepared statement** (`PQprepare(name, sql)` / `PQexecPrepared(name)`) —
  lives until `DEALLOCATE` or session end; reuses server plan; needs name
  that's stable on a given physical connection. The right key for our
  cache.
* **Unnamed prepared statement** (empty `name` in `Parse`) — survives
  only until next `Parse` on that connection. Useful as a tier-2 cache
  for one-shots and for pgbouncer transaction mode where named statements
  break.
* **libpq pipeline mode** (`PQenterPipelineMode`, PG14+) — out of scope
  for v1; relevant for batch later.

The pattern: server-side plan reuse + zero per-request Parse RTT after
warmup. That is what we want.

---

## 3. Goals / non-goals

**Goals**

* Make repeated `$pdo->prepare($sql)` on a pooled `PDO` object
  effectively free after warmup, *without changing user code*.
* Survive any pool churn: a coroutine that lands on a physical
  connection where this SQL has already been prepared must reuse the
  existing server-side statement; one that lands on a fresh connection
  must transparently re-prepare and cache.
* Drop in for `pdo_pgsql` first; design must extend to `pdo_mysql` (and
  others that gain pool support) without a redesign.
* Bounded memory: per-connection LRU with a configurable cap.
* No semantic change for non-pooled PDO. No semantic change when
  `ATTR_EMULATE_PREPARES => true`. No semantic change for cursors
  (`PDO_CURSOR_SCROLL` already forces emulate).

**Non-goals (v1)**

* Cross-process / cross-thread cache sharing.
* libpq pipeline mode.
* Auto-promote-after-N (pgjdbc `prepareThreshold`) — start with
  always-cache; revisit if it costs us on rare-query workloads.
* MySQL/MariaDB — same shape will apply, but MySQL prepared protocol
  and metadata caching are different enough to scope separately.

**Explicitly rejected**

* "Just flip `EMULATE_PREPARES => true`." Yes it removes the Parse RTT,
  but it also kills server-side plan reuse (every execute re-parses on
  the server), and it reintroduces escape/typing footguns
  (CVE-2025-14180; issues #11587, #12581). It is a workaround, not a
  fix.

---

## 4. Background facts (verified)

* **`pdo_pgsql` default `ATTR_EMULATE_PREPARES`: `false`.** The struct
  field `H->emulate_prepares` is `ecalloc`'d (zero) at connect and only
  changes if the user sets it. Confirmed in
  `ext/pdo_pgsql/pgsql_driver.c`. (pdo_mysql defaults to `true`, which is
  why MySQL doesn't show this bottleneck as sharply.)
* **Lazy prepare**: in `pgsql_handle_preparer`
  (`ext/pdo_pgsql/pgsql_driver.c:582-586`), when not emulating and not
  in execute-only mode, PDO only generates a name
  (`pdo_stmt_%08x`) and defers the real `PQprepare` to the first
  execute. So today the first `execute()` pays Parse+Describe **plus**
  Bind+Execute on the wire.
* **Pool acquire is per-call**: `pdo_pool_acquire_conn` is invoked at the
  top of every PDO method (`prepare`, `query`, `exec`, `getAttribute`,
  `inTransaction`, etc. — see all call sites in `ext/pdo/pdo_dbh.c`).
  The acquired physical conn is bound to the current coroutine via
  `pool_bindings` (HashTable keyed by coro id) and released on
  coroutine finish (`pdo_pool_binding_on_coroutine_finish` in
  `ext/pdo/pdo_pool.c`). Within a coroutine, all calls run on the same
  conn — but across coroutines the conn rotates.
* **Statement is already linked to its conn**: `pdo_stmt_t->pooled_conn`
  is set in `pdo_dbh.c:736` and refcount-bumped on the slot. So a
  `PDOStatement` returned from `prepare()` is pinned to a specific
  physical connection until destroyed — which is fine, that's the
  invariant we'll preserve.
* **Prepared name uniqueness**: today the name is a per-handle counter
  (`H->stmt_counter`). For the cache we want the name to be derived
  from the cache slot on the *physical* connection so reuse works.

---

## 5. Design

### 5.1 Where the cache lives

Per **physical** `pdo_dbh_t` (i.e., a pool slot, not the user-facing
"front" handle). Each slot owns:

```c
struct pdo_pool_stmt_cache {
    /* keyed by sql_text (zend_string*); value is pdo_pool_stmt_cache_entry* */
    HashTable entries;
    /* doubly-linked list head/tail for LRU ordering */
    pdo_pool_stmt_cache_entry *lru_head;
    pdo_pool_stmt_cache_entry *lru_tail;
    uint32_t size;
    uint32_t capacity;
};

struct pdo_pool_stmt_cache_entry {
    zend_string  *sql_text;        /* key (after pdo_parse_params rewrite) */
    char         *server_stmt_name;/* e.g. "pdo_stmt_00000007" — stable for life of conn */
    /* driver-specific bag; for pgsql: param oids if Describe was cached */
    void         *driver_data;
    pdo_pool_stmt_cache_entry *prev, *next;
};
```

Lives in the driver (`pdo_pgsql_db_handle`) so each driver controls its
own per-statement state. The pool layer provides only the LRU
bookkeeping helpers (a small generic API in `ext/pdo/pdo_pool.{c,h}`)
and the lifecycle hooks.

Lifecycle:

* Allocated lazily on first prepare on this physical conn.
* Walked + freed on conn close (`pgsql_handle_closer`) — must
  `DEALLOCATE` each entry, or just rely on session end. Closing the
  connection is enough; explicit `DEALLOCATE` only matters if we ever
  evict mid-life.
* Walked + freed on health-check failure / forced reset.
* Eviction of a single entry due to LRU cap → fire-and-forget
  `DEALLOCATE name` on that conn (best effort; if it fails we drop the
  entry anyway, the worst case is a leaked server plan).

### 5.2 The hot path — `prepare()` change

Currently `pgsql_handle_preparer` always allocates a new
`pdo_pgsql_stmt` and a new statement name. Change:

1. Run `pdo_parse_params` first (we already do) to get the canonical
   `nsql` (rewritten with `$1`, `$2`, …). This is the cache key —
   guarantees that two PHP-level SQLs that rewrite to the same wire SQL
   share a slot.
2. Look up `nsql` in this physical conn's cache.
3. **Hit**: reuse `entry->server_stmt_name`, reuse cached param OIDs if
   any, mark the entry MRU. Set `S->stmt_name = estrdup(entry->server_stmt_name)`,
   set `S->is_prepared = true` (new flag — skip the deferred
   `PQprepare` on first execute). Done. No wire traffic at all.
4. **Miss**: allocate as today, generate a new `pdo_stmt_%08x` name,
   insert into the cache *before* the deferred prepare is consumed. On
   first execute, the existing lazy-prepare path runs `PQprepare` once;
   on success we leave the entry in place. On `PQprepare` failure we
   evict the entry (so the next attempt on this conn gets a fresh try).
5. **Cache full on miss**: evict LRU tail, `DEALLOCATE` its server
   name, then insert.

A new flag in `pdo_pgsql_stmt`:

```c
bool is_prepared;  /* true → server-side stmt_name is already PQprepare'd on H->server */
```

In the executor (`pgsql_stmt_execute`), the existing branch that does
the deferred `PQprepare` becomes "prepare iff `!S->is_prepared`".

### 5.3 What about parameter OIDs / Describe?

`PQprepare(name, sql, nParams, paramTypes)` allows passing parameter
types up front. PDO currently passes `0` (let the server infer). When
binding, pdo_pgsql may issue an internal `Describe` to learn output
column types. This is the second piece pgx caches as `cache_describe`.

For v1 we can leave Describe behavior as-is and only cache the named
PREPARE. Most of the win is here. If we still see Describe overhead
under profiling, add a second slot in `cache_entry->driver_data`
storing the column metadata.

### 5.4 Configuration surface

New PDO attributes (declared in `ext/pdo/pdo_dbh_arginfo.h`, exposed in
`Pdo` enum/constants stub):

| Constant | Type | Default | Meaning |
|---|---|---|---|
| `PDO::ATTR_POOL_STMT_CACHE_SIZE` | int | 256 | Per-connection LRU capacity. `0` disables. Construction-only. |

Setting on a non-pooled PDO is a no-op (or silently ignored — match the
existing pool attrs). Reading `getAttribute(...STMT_CACHE_SIZE)` returns
the configured value, or `false` when pooling is off (mirror existing
`POOL_MIN`/`POOL_MAX` behavior).

Driver-level escape hatch: `PDO_PGSQL_ATTR_DISABLE_PREPARES` already
exists; if set, cache is bypassed entirely.

### 5.5 Concurrency / thread-safety

The cache is per physical `pdo_dbh_t`. A physical conn is checked out
to at most one coroutine at a time (pool invariant), and within a
coroutine the conn is sticky. So cache mutation has the same
single-owner invariant as the rest of the conn state. **No locking
required** as long as we never touch a cache from outside its owning
coroutine. Verify by audit: only `prepare`/`execute`/`closer` mutate it,
and all of them run under the pool's coroutine binding.

For the multi-thread (ext/async ThreadPool) case: each thread has its
own pool, and a physical conn never crosses threads. Same invariant
holds.

### 5.6 Edge cases

* **`PDO_CURSOR_SCROLL`**: forces `emulate=1` already; bypass cache
  (no server-side prepare to share).
* **`ATTR_EMULATE_PREPARES => true`** (per-call or per-handle): bypass
  cache. Statement is client-side anyway.
* **`PDO_PGSQL_ATTR_DISABLE_PREPARES`**: bypass cache.
* **Transactions**: a server-side prepared statement persists across
  transactions on the same conn. Nothing to do.
* **`SET search_path` / `SET session_authorization`**: changes
  semantics of cached plans. We don't proxy these specially today;
  documented limitation. (pgjdbc has the same warning.)
* **DDL that invalidates a plan** (`ALTER TABLE`, `DROP INDEX`, …):
  Postgres returns error 0A000 / 26000 on the next execute. Detect
  these classes in the executor, evict the cache entry, and retry the
  prepare once. Mirrors pgjdbc's `autosave=conservative` flow.
* **Health-check / connection reset**: drop the whole cache for that
  conn (entries reference a dead server-side namespace).
* **Statement object lifetime**: today `pdo_stmt_t` owns
  `S->stmt_name` (estrdup'd, freed in stmt dtor). With the cache, the
  *cache* owns the canonical name; the stmt holds a copy. On stmt
  destruction we do **not** `DEALLOCATE` — the cache outlives the
  stmt. Audit `pgsql_stmt_dtor` to make sure it doesn't `DEALLOCATE`
  unconditionally; today it does only on explicit close paths, but
  double-check.
* **pgbouncer transaction mode**: named prepared statements break.
  Workaround: set `STMT_CACHE_SIZE => 0` in user config. Could later
  add a "`cache_describe` mode" that uses unnamed prepares + cached
  Describe metadata only.

---

## 6. Implementation plan

Sized in passes; each pass leaves the tree green and benchable.

### Pass 1 — generic LRU + attribute plumbing
* Add `pdo_pool_stmt_cache_*` API in `ext/pdo/pdo_pool.{c,h}`: alloc,
  free, lookup, insert-with-evict, mark-MRU, drop-all. Driver-agnostic;
  entry payload is opaque `void*` with a driver-supplied dtor.
* Add `PDO::ATTR_POOL_STMT_CACHE_SIZE` constant + plumbing through the
  pool config (`pdo_pool_config_t`). Read it in `pdo_dbh.c` ctor like
  the other pool attrs. Default 256.
* Wire it into the per-physical-conn create path so each pool slot
  spawns its own cache instance with the configured cap.

### Pass 2 — pdo_pgsql integration
* Add `is_prepared` to `pdo_pgsql_stmt`.
* Modify `pgsql_handle_preparer`: cache lookup before allocating;
  reuse on hit.
* Modify `pgsql_stmt_execute`: skip the deferred `PQprepare` when
  `S->is_prepared`. On error class 0A000/26000 evict + retry once.
* Modify `pgsql_handle_closer` to free the cache (frees server-side
  state implicitly via session close).
* Add `DEALLOCATE` on LRU eviction.

### Pass 3 — tests
* `tests/pdo_pgsql/050-pdo_pgsql_pool_stmt_cache_hit.phpt` — same SQL
  twice from same coroutine, assert second prepare doesn't bump
  `pg_stat_statements` query parse counter.
* `tests/pdo_pgsql/051-pdo_pgsql_pool_stmt_cache_lru.phpt` — fill
  beyond capacity, assert LRU eviction issued `DEALLOCATE`.
* `tests/pdo_pgsql/052-pdo_pgsql_pool_stmt_cache_invalidation.phpt` —
  prepare, run DDL that invalidates plan, execute, assert transparent
  recovery.
* `tests/pdo_pgsql/053-pdo_pgsql_pool_stmt_cache_disabled.phpt` —
  `STMT_CACHE_SIZE => 0` reverts to current behavior.
* `tests/pdo_pgsql/054-pdo_pgsql_pool_stmt_cache_emulate.phpt` —
  `EMULATE_PREPARES => true` bypasses cache cleanly.
* Coroutine-flavored test: 64 coroutines × 100 iterations of the same
  SQL on a pool of 8; assert total server-side prepares ≤ 8.

### Pass 4 — bench validation
* HttpArena `async-db` profile: target Δ ≥ 4× for true-async-server.
  Acceptance: ≥ 60k req/s on the bench rig (matches the "1 RTT per
  request" envelope of pgx/sqlx). Median of 3 runs, apples-to-apples
  cpuset.
* Update `ext/async/CHANGELOG.md` and the `PDO_POOL_ANALYSIS.md`
  followups.

### Pass 5 (optional) — pdo_mysql
Same pattern. MySQL prepared statements use a different protocol
(integer stmt_id, COM_STMT_PREPARE/EXECUTE), but the cache shape is
identical. Schedule separately.

---

## 7. Risks

* **Plan staleness after schema changes** — handled by error-class
  retry; document.
* **Memory growth on pathologically diverse SQL** (e.g., ORM that
  inlines literals into SQL text) — bounded by LRU cap; document
  recommendation to use parameterized queries.
* **`DEALLOCATE` on eviction may fail silently** if conn is already
  bad — acceptable; the cache entry is dropped anyway and the next
  prepare on a healthy conn will re-prepare.
* **Behavior change for users who relied on `prepare()` always being a
  fresh server-side prepare** — extremely unlikely use case (would only
  matter for testing parse-error paths). Escape via
  `STMT_CACHE_SIZE => 0`.

---

## 8. Acceptance

* All existing `tests/pdo_pgsql/*.phpt` pass unchanged.
* New tests in §6 Pass 3 pass.
* HttpArena `async-db` true-async-server result improves by ≥ 4×
  (median of 3 runs on the standard cpuset).
* No regression on `ext/async` baseline / json / static profiles.
* `CHANGELOG.md` and `PDO_POOL_ANALYSIS.md` updated.

---

## 9. References

* `ext/pdo/pdo_dbh.c` — `pdo_pool_acquire_conn` call sites; prepare path
  at `:714-744`.
* `ext/pdo/pdo_pool.{c,h}` — current pool layer; place to add LRU API.
* `ext/pdo_pgsql/pgsql_driver.c` — `pgsql_handle_preparer:517`,
  lazy-prepare branch `:582-586`.
* `ext/pdo_pgsql/pgsql_statement.c` — execute path; deferred
  `PQprepare` site is here.
* Swoole reference (no statement caching): `swoole/library`
  `src/core/Database/PDOPool.php`.
* pgx automatic prepared-statement caching:
  https://github.com/jackc/pgx/wiki/Automatic-Prepared-Statement-Caching
* sqlx statement caching:
  https://docs.rs/sqlx/latest/sqlx/postgres/struct.PgConnectOptions.html#method.statement_cache_capacity
* Npgsql auto-prepare: https://www.npgsql.org/doc/prepare.html
* pgjdbc server-prepare: https://jdbc.postgresql.org/documentation/server-prepare/
* PostgreSQL extended query protocol: https://www.postgresql.org/docs/current/protocol-flow.html#PROTOCOL-FLOW-EXT-QUERY
