<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->enum('channel', ['TELEGRAM']);
            $table->enum('category', ['BUDGET_THRESHOLD', 'SYNC_FAILURE', 'LARGE_OUTFLOW', 'DAILY_SUMMARY']);
            $table->boolean('is_enabled');
            $table->timestampsTz();

            $table->unique(['owner_id', 'channel', 'category']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->enum('channel', ['TELEGRAM']);
            $table->enum('category', ['BUDGET_THRESHOLD', 'SYNC_FAILURE', 'LARGE_OUTFLOW', 'DAILY_SUMMARY']);
            $table->string('deduplication_key', 191);
            $table->text('message');
            $table->json('payload')->nullable();
            $table->enum('status', ['PENDING', 'SENT', 'SUPPRESSED', 'FAILED']);
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->unique(['owner_id', 'deduplication_key']);
            $table->index(['owner_id', 'status', 'created_at', 'id']);
        });

        $connection = DB::connection($this->getConnection());

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('ALTER TABLE notifications ADD CONSTRAINT notifications_values_check CHECK (char_length(btrim(deduplication_key)) > 0 AND char_length(btrim(message)) > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_preferences');
    }
};

