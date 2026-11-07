<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_category_suggestions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->foreignUuid('suggested_category_id')->constrained('transaction_categories')->restrictOnDelete();
            $table->unsignedTinyInteger('confidence_percent');
            $table->enum('status', ['PENDING_REVIEW', 'APPROVED', 'REJECTED']);
            $table->string('rationale', 255);
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->index(['owner_id', 'transaction_id', 'status', 'id']);
        });

        $connection = DB::connection($this->getConnection());

        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('ALTER TABLE ai_category_suggestions ADD CONSTRAINT ai_category_suggestions_values_check CHECK (confidence_percent BETWEEN 1 AND 100 AND char_length(btrim(rationale)) > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_category_suggestions');
    }
};

