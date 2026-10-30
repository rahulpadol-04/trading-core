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
        Schema::create('executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 24, 8);
            $table->decimal('quantity', 24, 8);
            $table->decimal('notional', 24, 8);
            $table->decimal('fee', 24, 8)->default(0);
            $table->timestampTz('executed_at', 6);
        });

        DB::statement('ALTER TABLE executions ADD CONSTRAINT executions_positive
            CHECK (price > 0 AND quantity > 0 AND notional > 0 AND fee >= 0)');
        DB::statement('CREATE INDEX executions_time_cover_idx ON executions (executed_at) INCLUDE (asset_id, notional)');
    }

    public function down(): void
    {
        Schema::dropIfExists('executions');
    }
};
