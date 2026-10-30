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
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->char('request_hash', 64);
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['user_id', 'key']);
        });

        DB::statement('CREATE INDEX idempotency_keys_created_idx ON idempotency_keys (created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
