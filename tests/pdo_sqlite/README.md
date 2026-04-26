# PDO SQLite Async Tests

Integration tests for `pdo_sqlite` running under True Async with the connection
pool (`PDO::ATTR_POOL_ENABLED`). SQLite needs no server — every test creates a
temp file DB via `tempnam()` and unlinks it on shutdown.

## Helpers

`inc/async_pdo_sqlite_test.inc` exposes:

* `AsyncPDOSqliteTest::fileDsn()` — `[$dsn, $path]` for a fresh temp-file DB.
* `AsyncPDOSqliteTest::sharedMemoryDsn($name)` — DSN for `file:NAME?mode=memory&cache=shared`,
  the only in-memory mode the pool supports (slots share one DB).
* `AsyncPDOSqliteTest::poolOptions($overrides)` — default pool options.
* `AsyncPDOSqliteTest::poolFromTemp($overrides)` — `[$pdo, $path]` ready to use.
* `AsyncPDOSqliteTest::cleanup($path)` — best-effort `unlink()`.

## Tests

| #   | Test                                       | Coverage                                                             |
| --- | ------------------------------------------ | -------------------------------------------------------------------- |
| 001 | `pool_creation`                            | `ATTR_POOL_ENABLED` creates pool template; basic query works.        |
| 002 | `pool_multiple_coroutines`                 | N coroutines run queries on the same template handle.                |
| 003 | `pool_coroutine_isolation`                 | Concurrent transactions isolated per slot.                           |
| 004 | `pool_transactions`                        | beginTransaction / commit / rollback in coroutine.                   |
| 005 | `pool_auto_rollback`                       | Uncommitted txn rolled back when slot returns to pool.               |
| 006 | `pool_max_size`                            | `POOL_MAX` caps concurrent acquisition.                              |
| 007 | `pool_min_pre_warm`                        | `POOL_MIN > 0` pre-warms slots; queries succeed.                     |
| 008 | `pool_shared_memory`                       | `file:...?mode=memory&cache=shared` is allowed.                      |
| 009 | `pool_memory_rejected`                     | Classic `:memory:` DSN throws.                                       |
| 010 | `pool_udf_registry`                        | `createFunction` UDF visible to every slot.                          |
| 011 | `pool_aggregate_registry`                  | `createAggregate` works across slots.                                |
| 012 | `pool_collation_registry`                  | `createCollation` honoured by slot ORDER BY.                         |
| 013 | `pool_udf_multi_arity`                     | Same name + different argc → two UDFs.                               |
| 014 | `pool_udf_duplicate_rejected`              | Duplicate name+argc throws.                                          |
| 015 | `pool_freeze_rejection`                    | createFunction / createCollation throw after first acquire.          |
| 016 | `pool_set_authorizer_rejected`             | `setAuthorizer` rejected on pool template.                           |
| 017 | `pool_open_blob_rejected`                  | `openBlob` rejected on pool template.                                |
| 018 | `pool_load_extension_rejected`             | `loadExtension` rejected on pool template.                           |
| 019 | `pool_last_insert_id`                      | `lastInsertId` is per-coroutine.                                     |
| 020 | `pool_resource_cleanup`                    | Drop template → all slots closed, registry freed.                    |

## Architecture

```
PDO\Sqlite (pool template, driver_data == NULL)
    │
    └── pool_bindings[coro_key] → slot pdo_dbh_t (driver_data == sqlite3*)
                                          │
                                          └── pdo_sqlite_db_handle.template_applied
```

Pool slots are normal `pdo_sqlite_db_handle` instances. The template handle
holds an UDF/collation registry in `driver_pool_data`; the registry freezes on
the first `before_acquire` call, after which any `createFunction` /
`createCollation` throws.
