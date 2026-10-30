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
        Schema::create('market_ticks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->decimal('price', 24, 8);
            $table->timestampTz('recorded_at', 6);
        });

        DB::statement('ALTER TABLE market_ticks ADD CONSTRAINT market_ticks_price_positive CHECK (price > 0)');
        DB::statement('CREATE INDEX market_ticks_asset_time_idx ON market_ticks (asset_id, recorded_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('market_ticks');
    }
};
