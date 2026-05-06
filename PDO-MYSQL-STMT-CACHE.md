# PDO Pool: prepared-statement cache for `pdo_mysql`

**Status:** task spec / open work
**Owner:** ext/async
**Depends on:** `PDO::ATTR_POOL_STMT_CACHE_SIZE` infrastructure already shipped
in 0.7.0 for `pdo_pgsql` (see [`PDO-PREPARE.md`](PDO-PREPARE.md)).

---

## 1. Goal

Extend the per-physical-connection prepared-statement LRU cache
(`PDO::ATTR_POOL_STMT_CACHE_SIZE`) to `pdo_mysql`. Drop-in symmetry
with `pdo_pgsql`: opt-in via the same constant, same eviction model,
same transparent re-prepare on plan invalidation.

When the cache is enabled, repeated `$pdo->prepare($sql)` on the same
physical conn must reuse the existing server-side prepared statement
(`COM_STMT_PREPARE` skipped, `COM_STMT_CLOSE` skipped on stmt dtor),
just like the pgsql path skips `PQprepare` and `DEALLOCATE`.

---

## 2. Non-goals

* MariaDB-specific protocol extensions beyond what libmysqlclient /
  mysqlnd already use.
* Cross-process cache sharing.
* Anything that changes default behaviour for users who never set
  `STMT_CACHE_SIZE`.

---

## 3. Why this matters less than for pgsql (read this first)

`pdo_mysql` defaults to **`ATTR_EMULATE_PREPARES => true`**. In emulated
mode `prepare()` does no wire I/O — placeholders are substituted
client-side and `execute()` sends a single `COM_QUERY` with the
already-rendered SQL. There is no server-side `stmt_id` to cache and
nothing to amortise. **Most pdo_mysql users will see zero benefit from
this feature**, by design.

The cache only kicks in for users who explicitly set
`ATTR_EMULATE_PREPARES => false` on pdo_mysql. They get the same
3-RTT-per-request pattern that pgsql shows by default
(`COM_STMT_PREPARE` + `COM_STMT_EXECUTE` + `COM_STMT_CLOSE`), and the
cache collapses it to one RTT — same shape and same expected ~2.9×
win as measured in
[`docs/pdo-pool-stmt-cache-perf.md`](docs/pdo-pool-stmt-cache-perf.md).

So: **build it for parity and for the emulate=false user segment, not
because we expect a default-config improvement.**

---

## 4. Design rule from the user

> If `ATTR_EMULATE_PREPARES => true` — **no pool, no cache** for that
> handle. Otherwise the cache works identically to pgsql.

Reading: when the user opts into emulation, the cache stays out of the
way completely. The cache is only created on a slot when the slot's
effective emulate flag is `false`. This avoids wasted memory and any
risk of subtle interaction with the client-side substitution path.

Implementation note: this is the same rule pgsql already follows —
`pgsql_handle_preparer` enters the cache branch only inside the
`if (!emulate && !execute_only)` block. Mirror exactly.

---

## 5. Implementation plan

### 5.1 Entry payload refactor (prerequisite)

Today `pdo_pool_stmt_cache_entry_t::server_stmt_name` is a `char *`
(estrdup'd `"pdo_stmt_NNNNNNNN"` for pgsql). For MySQL the server-side
identifier is a `uint32_t stmt_id` returned by `COM_STMT_PREPARE`, not
a string.

Two options:

* **(a) keep `server_stmt_name` for string-named drivers, lean on the
  existing `void *driver_data + driver_data_dtor` for binary payloads
  (mysql, …).** Lowest churn. pgsql code is unchanged.
* **(b) collapse both into a single `void *driver_payload` + dtor.**
  Cleaner, but requires touching pgsql.

**Pick (a).** The existing `driver_data` slot was put there exactly
for this. MySQL stores `stmt_id` (and any extra binding data) inside
its own struct allocated as `driver_data`, with a dtor that frees it
and — at eviction — sends `COM_STMT_CLOSE`.

### 5.2 Driver struct changes (`ext/pdo_mysql/php_pdo_mysql_int.h`)

Add to the `pdo_mysql_db_handle` struct:

```c
pdo_pool_stmt_cache_t *stmt_cache; /* per-conn LRU; NULL if disabled or emulate=true */
```

Add to the `pdo_mysql_stmt` struct:

```c
bool from_cache; /* true = stmt_id is owned by the cache; do not COM_STMT_CLOSE on dtor */
```

(`from_cache` is the exact analogue of the bool we added to
`pdo_pgsql_stmt`.)

### 5.3 Factory / closer (`ext/pdo_mysql/mysql_driver.c`)

In the factory, after the connection is established, decide whether to
allocate the cache:

```c
if (dbh->pool != NULL && !H->emulate_prepares) {
    const pdo_dbh_t *template_dbh = (const pdo_dbh_t *)dbh->pool->user_data;
    if (template_dbh && template_dbh->pool_stmt_cache_size > 0) {
        H->stmt_cache = pdo_pool_stmt_cache_create(template_dbh->pool_stmt_cache_size);
    }
}
```

In the closer, free the cache before closing the connection (server
state evaporates with the session, no `COM_STMT_CLOSE` needed):

```c
if (H->stmt_cache) {
    pdo_pool_stmt_cache_destroy(H->stmt_cache);
    H->stmt_cache = NULL;
}
```

### 5.4 The hot path — `mysql_handle_preparer`

Mirror `pgsql_handle_preparer` exactly. After `pdo_parse_params`
populates `S->query`, before allocating the new statement object:

1. Honour the **"emulate ⇒ no cache"** rule: if effective
   `emulate_prepares` is true (per-handle or per-call), the cache
   branch is not entered — same pattern as the pgsql code, which is
   already wrapped in `if (!emulate && !execute_only)`.
2. If `H->stmt_cache != NULL`:
   - `pdo_pool_stmt_cache_lookup(H->stmt_cache, S->query)`
   - **Hit:** copy the cached `stmt_id` into the new
     `pdo_mysql_stmt`, mark `S->from_cache = true`, set whatever flag
     pdo_mysql uses to indicate "already prepared on the wire", do
     **not** issue `COM_STMT_PREPARE`. Done.
   - **Miss:** call `mysql_stmt_prepare()` as today, get a fresh
     `stmt_id`. Insert into the cache; on eviction, send
     `COM_STMT_CLOSE` on the evicted entry's `stmt_id` (best-effort).

### 5.5 Stmt destructor

In `pdo_mysql_stmt_dtor`, skip `mysql_stmt_close` (i.e. skip
`COM_STMT_CLOSE`) when `S->from_cache` is true — the server-side stmt
is owned by the cache. This is the direct analogue of the
`!S->from_cache` guard added to `pgsql_stmt_finish`.

### 5.6 Plan-invalidation auto-retry

MySQL signals "this prepared stmt is stale" with errno classes that
differ from PostgreSQL's SQLSTATE codes:

* **`1243` (`ER_UNKNOWN_STMT_HANDLER`)** — server lost the stmt
  (e.g. another session ran `RESET QUERY CACHE`, server restart,
  proxy shuffled the connection). Direct analogue of pg's `26000`.
* **`2057` (`CR_NEW_STMT_METADATA`)** — client-side libmysql/mysqlnd
  signal: result-set metadata changed since prepare; the prepared
  stmt must be re-prepared. Direct analogue of pg's `0A000` (cached
  plan must not change result type).
* **`1615` (`ER_NEED_REPREPARE`)** — server explicitly asks the client
  to re-prepare the statement. Often produced after DDL or
  `ALTER TABLE`. Treat as an invalidation signal too.

In `mysql_stmt_execute`, on error from `mysql_stmt_execute()` /
`mysql_stmt_store_result()`, if `S->from_cache` is true and we have
not retried yet and the errno is one of the above, then:

1. `pdo_pool_stmt_cache_take(H->stmt_cache, S->query)` to detach the
   stale entry.
2. Best-effort `mysql_stmt_close` on the taken entry's stmt_id.
3. `pdo_pool_stmt_cache_entry_free(taken)`.
4. Reset the per-stmt prepared flag, re-issue
   `mysql_stmt_prepare(S->query)`, re-insert into the cache, retry
   `mysql_stmt_execute()` once.

This is the direct analogue of the goto-based retry path now in
`pgsql_stmt_execute` (search for `plan_invalidation_retry:`).

### 5.7 Edge cases — explicit checklist

* `ATTR_EMULATE_PREPARES => true` (per-handle or per-call): cache not
  consulted, not allocated. **Ironclad rule from the user.**
* `PDO_MYSQL_ATTR_DIRECT_QUERY` (if enabled in build): bypass cache —
  it's the mysql equivalent of "execute-only".
* Server-side cursor (`PDO::CURSOR_SCROLL`): bypass cache.
* Connection healthcheck failure / `conn_broken`: pool destroys the
  conn, which calls our closer, which frees the cache. No
  per-statement cleanup needed.
* Multi-statement queries (`mysqli`-style multi): out of scope; PDO
  doesn't expose them through `prepare()` anyway.

---

## 6. API surface

**No new public API.** This is a driver-internal change. The user-
facing constant `PDO::ATTR_POOL_STMT_CACHE_SIZE` and the meaning of
`STMT_CACHE_SIZE => 0` (disabled, default) stay identical.

---

## 7. Tests

In `ext/async/tests/pdo_mysql/`. Mirror the pgsql suite
([`tests/pdo_pgsql/050`–`055`](tests/pdo_pgsql/)):

| # | Phpt | What it verifies |
|---|---|---|
| `050` | cache hit | Same SQL prepared N times → `information_schema.processlist` (or equivalent) shows one server-side stmt. |
| `051` | LRU eviction | `cap=2`, three distinct SQLs → exactly two stmts kept, the third evicted via `COM_STMT_CLOSE`. |
| `052` | disabled (`size=0`) | Default behaviour unchanged. After stmt dtor no leftover prepared statements. |
| `053` | **emulate bypass** | `ATTR_EMULATE_PREPARES => true`, `STMT_CACHE_SIZE => 16` → cache **not allocated** at all. (For mysql this is the most important test because emulate is the default.) |
| `054` | coroutine churn | 8 coroutines × N executes on `poolMax=1` → all collapse to one cached `stmt_id`. |
| `055` | plan invalidation retry | warm cache → `ALTER TABLE … MODIFY COLUMN …` → next execute returns transparently (errno 1615 / 2057 caught and re-prepared). |

Test infra: re-use `ext/async/tests/pdo_mysql/inc/` if it exists, or
add an `async_pdo_mysql_test.inc` mirroring the pgsql helper.

---

## 8. Performance expectations

* `emulate=true` (default): **no change** — cache not engaged.
* `emulate=false`, hot SQL on a stable pool conn: **expected ~2.5–3×
  throughput** vs current `emulate=false` baseline, mirroring the pgsql
  result. Reproduce with the same bench harness from
  [`docs/pdo-pool-stmt-cache-perf.md`](docs/pdo-pool-stmt-cache-perf.md),
  swapping the DSN.

If the measured win on a real MySQL server diverges materially from
the pgsql pattern, document the cause (likely metadata round-trip
differences in the binary protocol).

---

## 9. CHANGELOG entry (draft)

Under the next release section:

```
- **PDO Pool stmt cache: pdo_mysql driver coverage** — extends the
  prepared-statement cache shipped in 0.7.0 to pdo_mysql. Same
  opt-in via PDO::ATTR_POOL_STMT_CACHE_SIZE, same LRU semantics,
  same transparent re-prepare on plan invalidation
  (errno 1243 / 1615 / 2057 mapped to evict + COM_STMT_CLOSE +
  COM_STMT_PREPARE retry).

  Note: pdo_mysql defaults to ATTR_EMULATE_PREPARES => true, where
  no server-side prepared statement is created and the cache is
  intentionally not allocated. Real benefit accrues only to
  applications that explicitly set EMULATE_PREPARES => false on
  pdo_mysql.
```

---

## 10. References

* [`PDO-PREPARE.md`](PDO-PREPARE.md) — original cache design.
* [`docs/pdo-pool-stmt-cache-perf.md`](docs/pdo-pool-stmt-cache-perf.md) —
  pgsql perf measurements; methodology applies to mysql verbatim.
* `ext/pdo_pgsql/pgsql_driver.c::pgsql_handle_preparer` — the path
  this work mirrors.
* `ext/pdo_pgsql/pgsql_statement.c::pgsql_stmt_execute` — the
  plan-invalidation retry path to mirror.
* MySQL prepared-statement protocol (`COM_STMT_PREPARE`,
  `COM_STMT_EXECUTE`, `COM_STMT_CLOSE`):
  https://dev.mysql.com/doc/dev/mysql-server/latest/page_protocol_com_stmt_prepare.html
* MySQL error codes (1243, 1615): https://dev.mysql.com/doc/mysql-errors/8.0/en/server-error-reference.html
* libmysql / mysqlnd `CR_NEW_STMT_METADATA` (2057):
  https://dev.mysql.com/doc/mysql-errors/8.0/en/client-error-reference.html
