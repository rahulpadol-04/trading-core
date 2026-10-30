# Decisions

## 1. Locking strategy

| Operation | Mechanism | Why |
|---|---|---|
| Place BUY / SELL | `SELECT … FOR UPDATE` on the wallet (BUY) or position (SELL) row. The balance is checked on the row that locking read returns. `CHECK (… >= 0)` is the backstop. | Contention is per user, on one row, and short. A reservation must never fail open. Optimistic locking would turn 20 simultaneous orders into retries and 409s. |
| Settle, cancel, reject, fail, expire | `FOR UPDATE` on the **order row first**, then wallet, then position. Each acts only if the status still holds a reservation. | Serialises cancel against fill: exactly one sees `PENDING`, so the reservation is released or consumed exactly once. |
| Idempotency | Fast-path lookup, then a re-check under the wallet/position lock, then the `UNIQUE (user_id, idempotency_key)` index on `orders` and `idempotency_keys`. | Concurrent twins wait on the lock and replay. Any race that slips through hits the unique index and replays too. |
| Job dedup, summary rebuild | Redis locks (`ShouldBeUnique`, `Cache::lock`, `Cache::add`) | Coordination only, never money. A Redis lease can expire mid-transaction; a row lock cannot. |

**Lock order everywhere: order → wallet → position**, one row per table per transaction. Placement locks only the wallet or only the position, so no wait-for cycle can form. `DB::transaction(..., attempts: 3)` still retries deadlocks and serialization failures (for example from FK checks), and the jobs retry with backoff after that.

The isolation level is PostgreSQL's default READ COMMITTED with explicit row locks. A locking read that had to wait re-reads the latest committed row.

**Measured:**
- Removing `lockForUpdate()` from the wallet query let **20 of 20** simultaneous orders through on a 500 USD wallet. Each transaction read 500 and wrote 400, a lost update that never goes negative, so the CHECK constraint alone cannot catch it.
- Removing the order locks made the cancel/fill race test fail.

**Cancel vs fill:** both paths open a transaction and take `FOR UPDATE` on the order row first. PostgreSQL grants it to one; the other blocks, then re-reads the committed row. Each path acts only on `PENDING`, and moves the order to a terminal status in the same transaction that moves the money. The loser sees `CANCELLED` or `FILLED` and does nothing (cancel returns 409). If the lock holder crashes, its transaction rolls back and the other path proceeds against an unchanged order. `tests/Concurrency/CancelFillRaceTest.php` races both on ten orders.

**`PROCESSING`** is in the enum and counts as reservation-holding, but no code path sets it. Settlement holds the order's row lock for its whole transaction and goes straight from `PENDING` to a terminal status. A visible `PROCESSING` claim only pays off when settlement spans an external call, and then it needs a sweeper for orders stuck after a crash.

## 2. Money precision and rounding

- **Storage and arithmetic:** `NUMERIC(24,8)` in the database, and `App\Support\Decimal` (immutable, over brick/math `BigDecimal`) in PHP. Decimals travel as JSON **strings**; a JSON number is rejected by validation because PHP would decode it as a float.
- **No silent truncation:** `toStorage()` uses `RoundingMode::Unnecessary`, so any value with more than 8 decimals throws.
- **Exact notionals:** `CHECK (price_precision + quantity_precision <= 8)` on assets makes every `quantity × price` exact at scale 8.
- **Order input:** order prices and quantities are validated against the asset's precision. They are rejected, never rounded.
- **The one rounding in a money path** is the MARKET BUY reservation: `quantity × last fresh price × 1.05`, rounded **up**.
- **Informational values:** average cost (scale 8) and the 24 h change (4 decimals) use HALF_EVEN. They never move money.

**MARKET BUY reservation:** at acceptance there is no execution price, so reserve the last price newer than 30 s plus a 5% buffer. MARKET orders on either side are rejected at placement (`PRICE_UNAVAILABLE`) if no fresh price exists. At settlement the fill uses the current price and releases the unused part. If the price rose more than the buffer, the order is `REJECTED` (`PRICE_MOVED_BEYOND_RESERVATION`) and released, so money that was not reserved is never spent.

Alternatives considered:
- Reserve the whole available balance, which blocks the trader's other orders.
- A client-sent maximum price, which changes the API contract.

## 3. Schema changes and invariants

- **`ledger_entries.bucket`** (AVAILABLE or RESERVED) with `balance_after`. The sum of each bucket equals its wallet column, and reservations are audited as well as fills.
- **`position_entries`** gives holdings the same append-only trail.
- **`idempotency_keys`** stores the request hash and the exact response. `response_body` is `json`, not `jsonb`: `jsonb` reorders object keys, and replays must be byte-identical.
- **`orders`** gains `uuid` (public id), `reserved_amount`, `failure_reason`, `request_ip`, and `filled_at`/`cancelled_at`.
- **`executions`** gains `asset_id` and `notional`. `UNIQUE (order_id)` makes a second settlement impossible even if every application check failed.
- **Append-only, enforced twice:** PostgreSQL triggers reject `UPDATE` and `DELETE` on the ledger, position-entry and audit tables, and model guards do the same.
- **`ledger:verify`** proves that balances equal their entries and that reserved cash and quantity equal the open orders' reservations. Every money test ends with it.
- Enum columns are `VARCHAR` with a `CHECK`, not PostgreSQL ENUM types, because ENUM types are awkward to migrate.
- **Production:** revoke `UPDATE`, `DELETE` and `TRUNCATE` on the append-only tables from the app's role.

## 4. Indexes (query → index → plan)

| Index | Serves |
|---|---|
| `wallets (user_id, currency)` UNIQUE | Lock the wallet on every order |
| `positions (user_id, asset_id)` UNIQUE | Lock the holding; prevents duplicate rows on concurrent first buys (`INSERT … ON CONFLICT DO NOTHING`) |
| `orders (user_id, idempotency_key)` UNIQUE | Idempotency backstop |
| `orders (user_id, id DESC)`, `(user_id, status, id DESC)`, `(user_id, asset_id, id DESC)` | Cursor listing per filter |
| `orders (asset_id, side, limit_price)` partial `PENDING AND LIMIT` | Matching on a new price |
| `orders (expires_at)` partial `PENDING` | Expiry sweep |
| `orders (filled_at)` partial `FILLED` | Summary Q1 |
| `executions (executed_at) INCLUDE (asset_id, notional)` | Summary Q2, index-only |
| `market_ticks (asset_id, recorded_at DESC)` | Summary Q3, latest and window-open price |
| `ledger_entries (wallet_id, bucket, id)`, `position_entries (position_id, bucket, id)` | Statements and reconciliation |
| `audit_logs (subject_type, subject_id, id)` | History of one order |

Foreign keys not covered by a leading column (for example `executions.asset_id`) have no extra index. Their parents are delete-restricted and never deleted on a hot path.

**Measured plans:** `EXPLAIN (ANALYZE)` on about 1M ticks and 200k filled orders and executions:

```
Q1 filled 24h   Aggregate (4.5 ms)
                  -> Index Only Scan using orders_filled_at_idx on orders (rows=28797)  Heap Fetches: 48
Q2 top 5        Limit -> Sort -> HashAggregate (4.4 ms)
                  -> Index Only Scan using executions_time_cover_idx on executions (rows=28797)  Heap Fetches: 0
Q3 prices       Nested Loop Left Join x2 (0.22 ms over 1M ticks)
                  -> Seq Scan on assets
                  -> Limit -> Index Scan using market_ticks_asset_time_idx            Index Cond: (asset_id = a.id)
                  -> Limit -> Index Scan Backward using market_ticks_asset_time_idx   Index Cond: (asset_id = a.id AND recorded_at >= ...)
Matching        Index Scan using orders_matching_idx (0.07 ms)  Index Cond: (asset_id = $1 AND side = 'BUY' AND limit_price >= 60002)
Expiry          Index Scan using orders_expiry_idx (0.07 ms)    Index Cond: (expires_at <= now())
Listing         Index Scan using orders_user_status_id_idx      Index Cond: (user_id = 1 AND status = 'PENDING')
```

For `user_id = 1 AND status = 'FILLED'` the planner walks `orders_pkey` backwards instead, because each of the three test users owns a third of all orders. A selective filter uses the composite index, as above.

## 5. Matching trigger strategy

- **Batching:** the simulator inserts ticks in one statement per 200 ms window, publishes the latest price per asset with one Redis `HMSET` (price and timestamp in one field), and then dispatches **one `MatchAssetOrdersJob` per asset**.
- **Deduplication:** the job is `ShouldBeUniqueUntilProcessing`, so further ticks don't enqueue another while one waits. It reads the newest price when it runs, so it covers every tick that arrived meanwhile.
- **Only crossing orders are touched:** the job runs two range scans on the tiny partial index (BUY `limit_price >= price`, SELL `<= price`) and dispatches `ProcessOrderJob` only for crossed orders. Orders that cannot fill are never touched, and matching never queries `market_ticks`.
- **Safety under the lock:** `ProcessOrderJob` is unique per order, and settlement re-checks the current price under the order lock, so an order that uncrossed meanwhile stays `PENDING`.
- **Expiry is enforced twice:** a sweep runs every minute, and settlement refuses to fill a late order (it cancels it instead).

## 6. Market summary caching and freshness

- **60 s TTL:** the cache entry expires after at most 60 s. Without fills, prices can be up to 60 s old, which is fine for a dashboard.
- **Stampede protection:** on a miss, the first reader takes a Redis lock and rebuilds. The others block on the lock, double-check the cache and read the fresh value. `SummaryStampedeTest` shows 10 simultaneous cold readers cause one rebuild.
- **Fresh after fills, without thrash:** each fill runs one atomic `SET NX EX 5`. Only the first fill in each 5 s window schedules a rebuild, delayed 5 s so it covers the whole burst. The rebuild **overwrites** the entry and never deletes it, so readers never see a cold cache during fill bursts. That bounds the load at one rebuild per 5 s however high the fill rate.
- **Consistent window:** all three queries share one bound `since`, and prices come from ticks, so every number is from one snapshot.
- **Why not `Cache::flexible()`:** on a complete miss every concurrent caller computes the value, and it keeps its timestamp under an internal key that a push-style refresh would have to rely on.

## 7. `market_ticks` growth

At 50 ticks/s the table grows about 4.3M rows a day. Plan:
- Partition by range on `recorded_at`, daily, with primary key `(id, recorded_at)`, created ahead of time by a scheduled command or `pg_partman`.
- Roll raw ticks up into one-minute OHLC candles for history.
- Retain raw ticks for N days by **dropping or detaching partitions**, which is instant and leaves no bloat, unlike `DELETE`.
- The `(asset_id, recorded_at DESC)` index becomes local to each partition, so "last 24 h" prunes to one or two partitions.

## 8. Known gaps and shortcuts

- **No partial fills or fees.** The `fee` column exists and is always 0.
- **Stuck MARKET orders:** if `failed()` itself fails (for example, the database is down), the order stays `PENDING` with its reservation intact. Money is still consistent. LIMIT orders are swept at expiry, but MARKET orders have no expiry, so a stuck-order sweeper is the next step.
- **Idempotency lookup runs after validation:** replaying a request that has since become invalid (for example, the asset was made untradable) returns 422, not the stored response.
- **Keys never expire:** idempotency keys are single-use forever, because the unique index on orders keeps them.
- **Replays count toward the rate limit.**
- **Audit order across queues:** audit rows are written by a queued listener on `default`, and settlement runs on `orders`, so rows for one order may land out of order. Each row carries a full before/after snapshot.
- **`market_ticks` is not partitioned yet.**
- **With another day:**
  - partial fills
  - a stuck-order sweeper
  - an OpenAPI spec
  - a k6 load test
  - privilege revocation in migrations for production

## 9. At 100× load (5,000 ticks/s, 1M orders/day)

- **Ticks:** stream them through Redis Streams or Kafka into batched `COPY`s into daily partitions (or TimescaleDB). Keep the latest price in Redis.
- **Matching:** an in-memory order book per asset, owned by one consumer per asset partition, which emits fill commands. Settlement stays transactional in PostgreSQL. A single writer per asset removes most lock contention.
- **Throughput:** shard the `orders` queue by asset so one hot asset cannot starve the others, and let Horizon auto-scale it.
- **Database:**
  - PgBouncer in transaction pooling mode.
  - Read replicas for listing and the summary.
  - Monthly partitions for `orders`, `executions` and `ledger_entries`.
  - Archive terminal orders.
- **Hot wallets:** one wallet row serialising one trader's orders is correct. If it becomes a bottleneck, split into sub-accounts; never relax the lock.
- **Summary:** a per-minute volume table, upserted inside the settlement transaction, so the endpoint sums at most 1,440 rows per asset.
- **Operations:**
  - Run `ledger:verify` incrementally on a replica and alert on drift.
  - Alert on queue latency and lock waits (`log_lock_waits`).
