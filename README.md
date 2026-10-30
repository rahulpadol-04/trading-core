# Trading Order Core

![CI](https://github.com/rahulpadol-04/trading-core/actions/workflows/ci.yml/badge.svg)

Order-management core for a prop trading firm: Laravel 13 · PHP 8.5 · PostgreSQL 18 · Redis · Sail.
Design decisions: [DECISIONS.md](DECISIONS.md).

## Quick start (one command)

```sh
./bin/setup
```

Requires Docker. It installs Composer dependencies if `vendor/` is missing, then starts:
- the app
- PostgreSQL
- Redis
- Horizon (the queue workers)
- the scheduler

Then it runs `migrate:fresh --seed` and prints the seeded tokens. The API is at `http://localhost/api/v1`.

If ports 80, 5432 or 6379 are taken, set `APP_PORT`, `FORWARD_DB_PORT` or `FORWARD_REDIS_PORT` in `.env`.

Laravel Sail is `./vendor/bin/sail`. The commands below assume the alias `alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'`.

### Seeded API tokens (local review only)

| User | Bearer token | Wallet | Holdings |
|---|---|---|---|
| alice@example.com | `alice-local-token-0000000000000000000000000001` | 100,000 USD | 0.5 BTC, 5 ETH, 20 AAPL |
| bob@example.com | `bob-local-token-00000000000000000000000000002` | 100,000 USD | same |
| carol@example.com | `carol-local-token-000000000000000000000000003` | 100,000 USD | same |

- **Assets:** BTC-USD, ETH-USD, SOL-USD, AAPL, EUR-USD, each with 25 hours of one-minute ticks.
- **Demo fills:** six filled MARKET buys.

## Running the pieces

| What | Command |
|---|---|
| Queue workers (Horizon) | Started by `./bin/setup` (service `horizon`). Manual: `sail artisan horizon` |
| Without Horizon | `sail artisan queue:work redis --queue=orders` and `sail artisan queue:work redis --queue=default` |
| Scheduler (expiry every minute) | Service `scheduler`. Manual: `sail artisan schedule:work` |
| Price simulator | `sail artisan market:simulate` (options `--rate=50 --flush-ms=200 --duration=0`) |
| Expire overdue orders now | `sail artisan orders:expire` |
| Ledger reconciliation | `sail artisan ledger:verify` |
| Horizon dashboard | `http://localhost/horizon` |
| EXPLAIN-scale data | `sail artisan db:seed --class=LoadTestSeeder` |

JSON logs for the correlation id: `LOG_CHANNEL=stderr` and `LOG_STDERR_FORMATTER="Monolog\Formatter\JsonFormatter"`.

## Tests

```sh
sail artisan test
sail artisan test --exclude-group=concurrency
sail artisan test --group=concurrency
sail bin pint --test && sail php vendor/bin/phpstan analyse
```

Tests run against the PostgreSQL `testing` database and Redis databases 5 and 6, never SQLite.

`tests/Concurrency` spawns separate PHP processes with `Process::pool`, each with its own database connection, started on a shared clock barrier:

- **Placement:** 20 simultaneous BUYs against a wallet that affords 5. Exactly 5 are accepted, and no balance is ever negative.
- **Idempotency:** 10 simultaneous retries with the same Idempotency-Key create one order.
- **Cancel vs fill:** cancel and fill race on 10 orders. Exactly one wins each time, and each reservation is released once.
- **Cache stampede:** 10 cold readers of the market summary cause exactly one rebuild.

## API

All endpoints are under `/api/v1` and require `Authorization: Bearer <token>`. Decimals are JSON strings in both directions.

```
POST   /api/v1/orders            place (Idempotency-Key header required), 202
GET    /api/v1/orders            own orders, cursor pagination, ?status= ?asset= ?per_page=
GET    /api/v1/orders/{uuid}     own order with executions
DELETE /api/v1/orders/{uuid}     cancel a PENDING order
GET    /api/v1/wallet            balances and positions
GET    /api/v1/market/summary    24 h summary, cached for at most 60 s
```

```sh
export TOKEN=alice-local-token-0000000000000000000000000001
export API=http://localhost/api/v1
H=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json")
```

**Place a LIMIT buy:**
```sh
curl -si -X POST $API/orders "${H[@]}" -H "Idempotency-Key: demo-0001" \
  -d '{"asset":"BTC-USD","side":"BUY","type":"LIMIT","quantity":"0.01","limit_price":"64000.00"}'
```

**Retry the same key.** You get the original 202 again with `Idempotency-Replayed: true`, and no new order is created:
```sh
curl -si -X POST $API/orders "${H[@]}" -H "Idempotency-Key: demo-0001" \
  -d '{"asset":"BTC-USD","side":"BUY","type":"LIMIT","quantity":"0.01","limit_price":"64000.00"}'
```

**Reuse the key with a different payload.** This returns 422 `IDEMPOTENCY_KEY_REUSED`:
```sh
curl -s -X POST $API/orders "${H[@]}" -H "Idempotency-Key: demo-0001" \
  -d '{"asset":"BTC-USD","side":"BUY","type":"LIMIT","quantity":"0.02","limit_price":"64000.00"}'
```

**MARKET sell** (needs a price newer than 30 s, so run `market:simulate`):
```sh
curl -s -X POST $API/orders "${H[@]}" -H "Idempotency-Key: demo-0002" \
  -d '{"asset":"ETH-USD","side":"SELL","type":"MARKET","quantity":"1.5"}'
```

**List orders,** filtered, then the next page:
```sh
curl -s "$API/orders?status=PENDING&asset=BTC-USD&per_page=10" "${H[@]}"
curl -s "$API/orders?per_page=10&cursor=<meta.next_cursor>" "${H[@]}"
```

**Show and cancel:**
```sh
curl -s $API/orders/<uuid> "${H[@]}"
curl -s -X DELETE $API/orders/<uuid> "${H[@]}"
```

**Wallet and market summary:**
```sh
curl -s $API/wallet "${H[@]}"
curl -s $API/market/summary "${H[@]}"
```

Every error has the same shape:

```json
{"error":{"code":"INSUFFICIENT_FUNDS","message":"Available balance is too low for this order.","details":{"required":"1100","available":"1000"}}}
```

| Status | Codes |
|---|---|
| 401 | `UNAUTHENTICATED` |
| 404 | `NOT_FOUND` (unknown ids and other traders' orders alike) |
| 409 | `ORDER_NOT_CANCELLABLE` |
| 422 | `VALIDATION_FAILED`, `IDEMPOTENCY_KEY_REUSED`, `INSUFFICIENT_FUNDS`, `INSUFFICIENT_POSITION`, `PRICE_UNAVAILABLE` |
| 429 | `RATE_LIMITED` (with `Retry-After`) |
| 500 | `INTERNAL_ERROR` |

Every response carries `X-Correlation-ID`. Send a UUID in that header to trace a request through its jobs and audit rows.

