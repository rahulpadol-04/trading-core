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
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->decimal('available_balance', 24, 8)->default(0);
            $table->decimal('reserved_balance', 24, 8)->default(0);
            $table->timestampsTz();

            $table->unique(['user_id', 'currency']);
        });

        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_available_non_negative CHECK (available_balance >= 0)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_reserved_non_negative CHECK (reserved_balance >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
