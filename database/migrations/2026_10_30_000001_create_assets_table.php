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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 16)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('price_precision');
            $table->unsignedSmallInteger('quantity_precision');
            $table->boolean('is_tradable')->default(true);
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_precision_fits
            CHECK (price_precision >= 0 AND quantity_precision >= 0 AND price_precision + quantity_precision <= 8)');
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
