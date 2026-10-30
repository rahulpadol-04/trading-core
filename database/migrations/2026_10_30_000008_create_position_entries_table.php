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
        Schema::create('position_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->string('bucket', 10);
            $table->decimal('quantity', 24, 8);
            $table->decimal('balance_after', 24, 8);
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement("ALTER TABLE position_entries ADD CONSTRAINT position_entries_bucket_valid CHECK (bucket IN ('AVAILABLE', 'RESERVED'))");
        DB::statement("ALTER TABLE position_entries ADD CONSTRAINT position_entries_type_valid
            CHECK (type IN ('DEPOSIT', 'RESERVE', 'RELEASE', 'TRADE'))");
        DB::statement('ALTER TABLE position_entries ADD CONSTRAINT position_entries_non_zero CHECK (quantity <> 0)');
        DB::statement('ALTER TABLE position_entries ADD CONSTRAINT position_entries_balance_non_negative CHECK (balance_after >= 0)');

        DB::statement('CREATE INDEX position_entries_position_idx ON position_entries (position_id, bucket, id)');
        DB::statement('CREATE INDEX position_entries_order_idx ON position_entries (order_id) WHERE order_id IS NOT NULL');

        DB::unprepared('CREATE TRIGGER position_entries_append_only BEFORE UPDATE OR DELETE ON position_entries
            FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('position_entries');
    }
};
