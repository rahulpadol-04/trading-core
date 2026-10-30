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
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 24, 8)->default(0);
            $table->decimal('reserved_quantity', 24, 8)->default(0);
            $table->decimal('average_cost', 24, 8)->default(0);
            $table->timestampsTz();

            $table->unique(['user_id', 'asset_id']);
        });

        DB::statement('ALTER TABLE positions ADD CONSTRAINT positions_quantity_non_negative CHECK (quantity >= 0)');
        DB::statement('ALTER TABLE positions ADD CONSTRAINT positions_reserved_non_negative CHECK (reserved_quantity >= 0)');
        DB::statement('ALTER TABLE positions ADD CONSTRAINT positions_average_cost_non_negative CHECK (average_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
