<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Realistic volumes for EXPLAIN only: about 1M ticks and 200k filled orders with
 * executions spread over 7 days. Run on demand after DatabaseSeeder, never in tests.
 * The synthetic fills have no ledger entries; they exist to give the planner real
 * table sizes.
 */
class LoadTestSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO market_ticks (asset_id, price, recorded_at)
            SELECT a.id, round((100 + random() * 10)::numeric, 2), now() - (g * interval '1 second')
            FROM assets a, generate_series(1, 200000) g
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO orders (uuid, user_id, asset_id, side, type, status, quantity, limit_price,
                                reserved_amount, idempotency_key, filled_at, created_at, updated_at)
            SELECT gen_random_uuid(), u.ids[1 + g % array_length(u.ids, 1)], a.ids[1 + g % array_length(a.ids, 1)],
                   'BUY', 'LIMIT', 'FILLED', 1, 100, 100, 'load-' || g,
                   now() - (g * interval '3 seconds'), now() - (g * interval '3 seconds'), now()
            FROM generate_series(1, 200000) g,
                 (SELECT array_agg(id ORDER BY id) AS ids FROM users) u,
                 (SELECT array_agg(id ORDER BY id) AS ids FROM assets) a
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO executions (order_id, asset_id, price, quantity, notional, fee, executed_at)
            SELECT id, asset_id, 100, 1, 100, 0, filled_at
            FROM orders
            WHERE idempotency_key LIKE 'load-%'
            SQL);

        DB::statement('VACUUM ANALYZE market_ticks, orders, executions');
    }
}
