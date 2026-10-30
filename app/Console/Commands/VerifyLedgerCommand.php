<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('ledger:verify')]
#[Description('Prove that balances equal their ledger entries and that every reservation belongs to an open order')]
class VerifyLedgerCommand extends Command
{
    private const array CHECKS = [
        'Wallet balance differs from its ledger entries' => <<<'SQL'
            SELECT w.id, w.available_balance, w.reserved_balance, l.available, l.reserved
            FROM wallets w
            LEFT JOIN (
                SELECT wallet_id,
                       COALESCE(SUM(amount) FILTER (WHERE bucket = 'AVAILABLE'), 0) AS available,
                       COALESCE(SUM(amount) FILTER (WHERE bucket = 'RESERVED'), 0) AS reserved
                FROM ledger_entries
                GROUP BY wallet_id
            ) l ON l.wallet_id = w.id
            WHERE w.available_balance <> COALESCE(l.available, 0)
               OR w.reserved_balance <> COALESCE(l.reserved, 0)
            SQL,

        'Reserved cash differs from open BUY orders' => <<<'SQL'
            SELECT w.id, w.reserved_balance, COALESCE(SUM(o.reserved_amount), 0) AS open_reserved
            FROM wallets w
            LEFT JOIN orders o ON o.user_id = w.user_id AND o.side = 'BUY'
                AND o.status IN ('PENDING', 'PROCESSING')
            GROUP BY w.id, w.reserved_balance
            HAVING w.reserved_balance <> COALESCE(SUM(o.reserved_amount), 0)
            SQL,

        'Position differs from its entries' => <<<'SQL'
            SELECT p.id, p.quantity, p.reserved_quantity, e.available, e.reserved
            FROM positions p
            LEFT JOIN (
                SELECT position_id,
                       COALESCE(SUM(quantity) FILTER (WHERE bucket = 'AVAILABLE'), 0) AS available,
                       COALESCE(SUM(quantity) FILTER (WHERE bucket = 'RESERVED'), 0) AS reserved
                FROM position_entries
                GROUP BY position_id
            ) e ON e.position_id = p.id
            WHERE p.quantity <> COALESCE(e.available, 0)
               OR p.reserved_quantity <> COALESCE(e.reserved, 0)
            SQL,

        'Reserved quantity differs from open SELL orders' => <<<'SQL'
            SELECT p.id, p.reserved_quantity, COALESCE(SUM(o.quantity), 0) AS open_reserved
            FROM positions p
            LEFT JOIN orders o ON o.user_id = p.user_id AND o.asset_id = p.asset_id AND o.side = 'SELL'
                AND o.status IN ('PENDING', 'PROCESSING')
            GROUP BY p.id, p.reserved_quantity
            HAVING p.reserved_quantity <> COALESCE(SUM(o.quantity), 0)
            SQL,
    ];

    public function handle(): int
    {
        $failed = false;

        foreach (self::CHECKS as $label => $sql) {
            $rows = DB::select($sql);

            if ($rows !== []) {
                $failed = true;
                $this->error($label);
                $this->table(array_keys((array) $rows[0]), array_map(fn (object $row): array => (array) $row, $rows));
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->info('Ledger consistent.');

        return self::SUCCESS;
    }
}
