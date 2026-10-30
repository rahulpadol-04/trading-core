<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->string('bucket', 10);
            $table->decimal('amount', 24, 8);
            $table->decimal('balance_after', 24, 8);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_bucket_valid CHECK (bucket IN ('AVAILABLE', 'RESERVED'))");
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_type_valid
            CHECK (type IN ('DEPOSIT', 'RESERVE', 'RELEASE', 'TRADE'))");
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_amount_non_zero CHECK (amount <> 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_balance_after_non_negative CHECK (balance_after >= 0)');

        DB::statement('CREATE INDEX ledger_wallet_idx ON ledger_entries (wallet_id, bucket, id)');
        DB::statement('CREATE INDEX ledger_order_idx ON ledger_entries (order_id) WHERE order_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_append_only_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER ledger_entries_append_only
                BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS forbid_append_only_mutation() CASCADE');
    }
};
