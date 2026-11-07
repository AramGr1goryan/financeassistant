<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id');
            $table->primary('id');
            $table->foreignUuid('account_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor_units');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_exponent');
            $table->enum('type', ['INCOME', 'EXPENSE', 'TRANSFER', 'REFUND', 'FEE', 'ADJUSTMENT']);
            $table->enum('status', ['PENDING', 'POSTED', 'REVERSED', 'DECLINED']);
            $table->timestampTz('transaction_at');
            $table->timestampTz('posting_at')->nullable();
            $table->text('description')->nullable();
            $table->string('merchant')->nullable();
            $table->string('external_bank_transaction_id')->nullable();
            $table->string('idempotency_key');
            $table->foreignUuid('category_id')->nullable()->constrained('transaction_categories')->restrictOnDelete();
            $table->foreignUuid('original_transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->uuid('transfer_group_id')->nullable();
            $table->string('transfer_reference')->nullable();
            $table->enum('transfer_direction', ['IN', 'OUT'])->nullable();
            $table->boolean('is_generated_counterpart')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'idempotency_key']);
            $table->unique(['account_id', 'external_bank_transaction_id']);
            $table->unique(['account_id', 'transfer_reference']);
            $table->unique(['transfer_group_id', 'account_id']);
            $table->index(['account_id', 'transaction_at']);
            $table->index(['account_id', 'status', 'transaction_at']);
            $table->index(['original_transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
