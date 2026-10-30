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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_type', 16);
            $table->string('action', 64);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('context');
            $table->ipAddress('ip_address')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('created_at', 6)->useCurrent();
        });

        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_valid CHECK (actor_type IN ('USER', 'SYSTEM'))");
        DB::statement('CREATE INDEX audit_logs_subject_idx ON audit_logs (subject_type, subject_id, id)');
        DB::statement('CREATE INDEX audit_logs_actor_idx ON audit_logs (actor_id, created_at)');

        DB::unprepared('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION forbid_append_only_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
