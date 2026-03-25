# ThreadPool для TrueAsync — Исследование

## Мотивация

Текущий `Thread::start()` создаёт новый OS-поток на каждый вызов:
TSRM init → request startup → выполнение → shutdown → ts_free_thread.
Дорого для мелких задач. ThreadPool держит пул предварительно инициализированных worker-потоков + общую очередь задач.

Существующий `zend_async_task_t` + `uv_queue_work` (libuv_reactor.c:2197) — только для C-уровня.
В worker-потоке libuv **нет доступа к PHP/Zend API** (комментарий: "No PHP/Zend API access is allowed here").
ThreadPool — для **PHP-замыканий**.

---

## Что уже есть в TrueAsync

### Thread API (ext/async/thread.c)
- **Snapshot mechanism**: глубокое копирование closure + captured vars в persistent memory (pemalloc)
  - `async_thread_snapshot_create(entry, bootloader)` — создаёт snapshot
  - Arena-based bump allocator для op_arrays
  - `async_thread_transfer_zval()` — копирование zval в pemalloc (parent → child)
  - `async_thread_load_zval()` — копирование из pemalloc в emalloc (child → parent)
- **Worker lifecycle**: `async_thread_tsrm_init()` → `async_thread_request_startup(snapshot)` → execute → transfer result → `notify_parent()` → `async_thread_request_shutdown()` → `ts_free_thread()`
- **Bootloader**: опциональная closure, выполняемая до основной

### Task Queue (Zend/zend_async_API.h)
```c
struct _zend_async_task_s {
    zend_async_event_t base;
    zend_async_task_run_t run;  // C function pointer
};
```
- `ZEND_ASYNC_QUEUE_TASK(task)` → `uv_queue_work()` → libuv thread pool
- `zend_async_thread_pool_register(module, allow_override, queue_task_fn)` — одна глобальная регистрация

### Pool pattern (ext/async/pool.c)
- `zend_async_pool_t` с min/max size, factory/destructor callbacks
- `circular_buffer_t idle` + `zend_async_callbacks_vector_t waiters`
- acquire/release + circuit breaker + healthcheck timer
- Back-pressure: корутина suspend'ится через `ZEND_ASYNC_SUSPEND()`

### Channel (ext/async/channel.h)
- Thread-safe channels с `circular_buffer_t buffer`
- `waiting_receivers` / `waiting_senders`
- Завязаны на корутины — **не подходят для worker-потоков** без event loop

### Event + notification (libuv_reactor.c)
- `uv_async_t` для кросс-потокового уведомления event loop
- `notify_parent()` на thread event вызывает `uv_async_send()`
- Parent loop callback обрабатывает результат

---

## Лучшие практики из других языков

### Java ThreadPoolExecutor
- **core/max threads**: core всегда живые, extra потоки убиваются по idle timeout
- **Pluggable queue**: `LinkedBlockingQueue` (unbounded), `ArrayBlockingQueue(N)` (bounded), `SynchronousQueue` (direct handoff)
- **Rejection policy**: AbortPolicy (throw), CallerRunsPolicy, DiscardPolicy, DiscardOldestPolicy
- `submit(Callable<T>)` → `Future<T>` с `get()`, `cancel()`, `isDone()`
- `shutdown()` / `shutdownNow()` / `awaitTermination(timeout)`

### Python concurrent.futures.ThreadPoolExecutor
- `submit(fn, *args, **kwargs)` → `Future`
- **initializer** callback — один раз на worker thread (для per-thread setup)
- Unbounded `SimpleQueue`, single shared, no work-stealing
- `shutdown(wait=True, cancel_futures=False)`
- Context manager: `with ThreadPoolExecutor() as e:` → auto shutdown

### Rust tokio
- Separation: **async workers** (fixed) + **blocking pool** (elastic, 0→512)
- `spawn_blocking(closure)` → `JoinHandle<T>` (awaitable Future)
- Per-core run queues с work-stealing
- `JoinSet` для управления группой задач

### Go ants
- `pool.Submit(func(){})` — fire-and-forget (no Future)
- Bounded pool + blocking on full
- Worker recycling: idle workers убираются по таймеру
- `pool.Tune(size)` — динамическое изменение размера
- `pool.Release()` — graceful shutdown

### C# ThreadPool + TPL
- `Task.Run(() => ...)` → `Task<T>` (awaitable)
- Hill-climbing auto-sizing (feedback-based, ~500ms intervals)
- `CancellationToken` — cooperative cancellation через всю цепочку
- Global FIFO queue + per-thread LIFO queues с work-stealing

---

## Ключевые решения (принятые)

| Вопрос | Решение |
|--------|---------|
| API submit | `submit(Closure $task, mixed ...$args): Future` — variadic args |
| Fire-and-forget | Нет, только `submit()` с Future |
| Worker creation | Переиспользовать механизм из thread.c (детали позже) |
| SharedPool | Отдельный этап, потребуется API |
| Очередь | Atomic trylock (два флага: head_busy, tail_busy) + mutex fallback + condvar для сна idle workers |
| Небуферизированный канал | Нет, ThreadChannel всегда буферизированный (capacity >= 1) |
| Уведомление потоков | uv_async_send (не pthread_cond_t), т.к. потоки всегда имеют event loop |
| Thread-safe канал | `async_thread_channel_t` — отдельная структура, реализует `zend_async_channel_t` |
| PHP-класс канала | `Async\ThreadChannel` |
| Структура данных | Отдельна от PHP-объекта, в persistent memory, atomic refcount для передачи между потоками |
| Модули | `thread_channel.h/.c` и `thread_pool.h/.c` — отдельные файлы в ext/async |
| Порядок реализации | Сначала ThreadChannel, потом ThreadPool (зависит от него) |

---

## Базовый принцип: разделение структуры и PHP-объекта

`thread_pool_t` — самостоятельная C-структура в **persistent memory** (pemalloc).
PHP-объект `Async\ThreadPool` — тонкая обёртка, хранящая только указатель.

```
┌─────────────────────────┐
│  thread_pool_object_t   │  ← emalloc (per-thread PHP object)
│  ┌───────────────────┐  │
│  │ thread_pool_t *pool ──────► ┌──────────────────┐
│  └───────────────────┘  │     │  thread_pool_t    │  ← pemalloc (persistent, shared)
│  zend_object std        │     │  ref_count (atomic)│
└─────────────────────────┘     │  queue             │
                                │  workers           │
┌─────────────────────────┐     │  ...               │
│  thread_pool_object_t   │     └──────────────────┘
│  (другой PHP-поток)     │            ▲
│  pool ───────────────────────────────┘
└─────────────────────────┘
```

Это даёт:
- **Передача между потоками**: при transfer PHP-объекта копируется только указатель + `ref_count++`
- **Независимость от GC**: время жизни pool определяется ref_count, не PHP GC
- **Множественный доступ**: разные PHP-потоки создают свои обёртки вокруг одного pool

Аналог: как `pdo_dbh_t` живёт отдельно от `zend_object` в PDO, или как `zend_async_pool_t` отделён от PHP wrapper.

---

## Компоненты для детальной проработки

### 0. ThreadChannel (`thread_channel.h/.c`, `Async\ThreadChannel`)
Thread-safe канал — фундамент для ThreadPool. Реализует `zend_async_channel_t`.
Структура `async_thread_channel_t`:
- `zend_async_channel_t channel` — ABI base
- `circular_buffer_t buffer` — ring buffer в pemalloc
- `pthread_mutex_t mutex` — защита буфера (наносекунды, не блокирует event loop)
- `int32_t capacity` — всегда >= 1
- Маппинг `thread_id → uv_async_t*` — для уведомления event loop нужного потока
- Список waiters (ожидающие корутины + их thread handle)

Механика:
- send(): mutex lock → пишем в буфер → если есть waiter, uv_async_send его потоку → unlock
- receive(): mutex lock → если есть данные, забираем → unlock. Если пуст — регистрируем waiter, unlock, SUSPEND
- Данные в буфере через `async_thread_transfer_zval` (pemalloc)
- uv_async_t создаётся лениво при первом обращении потока к каналу, мультишот

### 1. Task Queue (использует ThreadChannel)

### 2. Worker Thread Lifecycle
Persistent PHP thread с TSRM. Init один раз, цикл на очереди.
Ключевое: request_startup/shutdown один раз на жизнь worker-а, не на каждую задачу.
Вопросы: как именно загружать closure из snapshot в уже running request.

### 3. Task Submission + Result Delivery
Submit: snapshot closure → enqueue → signal worker.
Result: worker transfer result в pemalloc → uv_async_send → parent load → resolve Future.
Вопросы: back-pressure (нельзя блокировать event loop), per-task notification handle.

### 4. Pool Management
min/max threads, scaling, idle reaping.
Вопросы: когда создавать новые workers, когда убивать idle.

### 5. PHP Class
Constructor, submit, shutdown, shutdownNow, awaitTermination.
Properties: activeWorkerCount, queuedTaskCount, isShutdown.

### 6. SharedPool
Sharing across PHP threads. Persistent memory + atomic refcount.
Отдельный этап.

### 7. Error Handling
Bailout, exception transfer, worker crash recovery.
Отдельный этап.

---

## Ключевые файлы

| Файл | Что переиспользовать |
|------|---------------------|
| `ext/async/thread.c` | snapshot create/destroy, TSRM init, closure execution, zval transfer/load |
| `ext/async/libuv_reactor.c:2197` | `libuv_queue_task` — паттерн uv_async notification |
| `ext/async/pool.c` | PHP object handlers pattern (create_object/dtor/free) |
| `ext/async/future.c` | Future creation + resolve |
| `Zend/zend_async_API.h:1019` | `zend_async_task_t` struct |
| `Zend/zend_async_API.h:397` | `zend_async_queue_task_t` typedef |
