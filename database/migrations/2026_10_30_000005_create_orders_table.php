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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('side', 4);
            $table->string('type', 6);
            $table->string('status', 10)->default('PENDING');
            $table->decimal('quantity', 24, 8);
            $table->decimal('limit_price', 24, 8)->nullable();
            $table->decimal('reserved_amount', 24, 8)->default(0);
            $table->string('idempotency_key', 128);
            $table->string('failure_reason')->nullable();
            $table->ipAddress('request_ip')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('filled_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'idempotency_key']);
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_side_valid CHECK (side IN ('BUY', 'SELL'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_type_valid CHECK (type IN ('LIMIT', 'MARKET'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid
            CHECK (status IN ('PENDING', 'PROCESSING', 'FILLED', 'CANCELLED', 'REJECTED', 'FAILED'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_reserved_non_negative CHECK (reserved_amount >= 0)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_limit_price_matches_type CHECK (
            (type = 'LIMIT' AND limit_price IS NOT NULL AND limit_price > 0)
            OR (type = 'MARKET' AND limit_price IS NULL))");

        DB::statement('CREATE INDEX orders_user_id_idx ON orders (user_id, id DESC)');
        DB::statement('CREATE INDEX orders_user_status_id_idx ON orders (user_id, status, id DESC)');
        DB::statement('CREATE INDEX orders_user_asset_id_idx ON orders (user_id, asset_id, id DESC)');
        DB::statement("CREATE INDEX orders_matching_idx ON orders (asset_id, side, limit_price)
            WHERE status = 'PENDING' AND type = 'LIMIT'");
        DB::statement("CREATE INDEX orders_expiry_idx ON orders (expires_at) WHERE status = 'PENDING'");
        DB::statement("CREATE INDEX orders_filled_at_idx ON orders (filled_at) WHERE status = 'FILLED'");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
