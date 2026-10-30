<?php

declare(strict_types=1);

namespace App\Actions\Market;

use App\Enums\OrderStatus;
use App\Models\Execution;
use App\Models\Order;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BuildMarketSummaryAction
{
    private const string PRICES_SQL = <<<'SQL'
        SELECT a.id, a.symbol, latest.price AS last_price, opening.price AS open_price
        FROM assets a
        LEFT JOIN LATERAL (
            SELECT t.price FROM market_ticks t
            WHERE t.asset_id = a.id
            ORDER BY t.recorded_at DESC
            LIMIT 1
        ) latest ON true
        LEFT JOIN LATERAL (
            SELECT t.price FROM market_ticks t
            WHERE t.asset_id = a.id AND t.recorded_at >= :since
            ORDER BY t.recorded_at ASC
            LIMIT 1
        ) opening ON true
        ORDER BY a.symbol
        SQL;

    /**
     * @return array{
     *     window: array{from: string, to: string},
     *     filled_orders: int,
     *     top_assets_by_volume: list<array{asset: string, notional_volume: string}>,
     *     assets: list<array{asset: string, last_price: string|null, change_24h_pct: string|null}>,
     *     generated_at: string
     * }
     */
    public function execute(): array
    {
        $now = CarbonImmutable::now();
        $since = $now->subDay();
        $sinceSql = $since->format('Y-m-d H:i:s.uP');

        $filled = Order::query()
            ->where('status', OrderStatus::Filled)
            ->where('filled_at', '>=', $sinceSql)
            ->count();

        $top = Execution::query()
            ->select('asset_id')
            ->selectRaw('SUM(notional) AS volume')
            ->where('executed_at', '>=', $sinceSql)
            ->groupBy('asset_id')
            ->orderByDesc('volume')
            ->limit(5)
            ->toBase()
            ->get();

        $prices = collect(DB::select(self::PRICES_SQL, ['since' => $sinceSql]))->keyBy('id');

        return [
            'window' => ['from' => $since->toIso8601String(), 'to' => $now->toIso8601String()],
            'filled_orders' => $filled,
            'top_assets_by_volume' => $top->map(fn (object $row): array => [
                'asset' => (string) $prices[$row->asset_id]->symbol,
                'notional_volume' => Decimal::of((string) $row->volume)->normalized(),
            ])->values()->all(),
            'assets' => $prices->map(fn (object $row): array => [
                'asset' => (string) $row->symbol,
                'last_price' => $row->last_price === null ? null : Decimal::of((string) $row->last_price)->normalized(),
                'change_24h_pct' => self::changePercent($row->open_price, $row->last_price),
            ])->values()->all(),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * (last - open) / open x 100, HALF_EVEN at 4 decimals; null without a tick in the window.
     */
    private static function changePercent(?string $open, ?string $last): ?string
    {
        if ($open === null || $last === null || Decimal::of($open)->isZero()) {
            return null;
        }

        return Decimal::of($last)->minus($open)->times('100')->dividedBy($open, 4)->normalized();
    }
}
