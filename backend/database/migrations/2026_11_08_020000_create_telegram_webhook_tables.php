<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_owner_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('telegram_user_id');
            $table->bigInteger('telegram_chat_id');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('verified_at');
            $table->timestampsTz();

            $table->unique('owner_id');
            $table->unique('telegram_user_id');
            $table->unique('telegram_chat_id');
        });

        Schema::create('telegram_webhook_updates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('update_id');
            $table->bigInteger('telegram_user_id');
            $table->bigInteger('telegram_chat_id');
            $table->string('command', 64)->nullable();
            $table->text('response_text');
            $table->timestampTz('processed_at');
            $table->timestampsTz();

            $table->unique('update_id');
            $table->index(['owner_id', 'processed_at', 'id']);
        });

        $connection = DB::connection($this->getConnection());

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('ALTER TABLE telegram_owner_links ADD CONSTRAINT telegram_owner_links_values_check CHECK (telegram_user_id > 0 AND telegram_chat_id > 0 AND telegram_user_id = telegram_chat_id)');
            $connection->statement('ALTER TABLE telegram_webhook_updates ADD CONSTRAINT telegram_webhook_updates_values_check CHECK (update_id > 0 AND telegram_user_id > 0 AND telegram_chat_id > 0 AND char_length(btrim(response_text)) > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_webhook_updates');
        Schema::dropIfExists('telegram_owner_links');
    }
};

