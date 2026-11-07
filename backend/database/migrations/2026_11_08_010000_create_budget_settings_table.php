<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_exponent');
            $table->unsignedBigInteger('revision');
            $table->unsignedInteger('planning_days');
            $table->bigInteger('mandatory_payments_minor_units');
            $table->bigInteger('protected_reserve_minor_units');
            $table->bigInteger('allocated_savings_minor_units');
            $table->timestampsTz();

            $table->unique(['owner_id', 'currency', 'revision']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE budget_settings ADD CONSTRAINT budget_settings_values_check CHECK (currency ~ '^[A-Z]{3}$' AND currency_exponent BETWEEN 0 AND 6 AND (currency <> 'AMD' OR currency_exponent = 0) AND revision > 0 AND planning_days > 0 AND mandatory_payments_minor_units >= 0 AND protected_reserve_minor_units >= 0 AND allocated_savings_minor_units >= 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_settings');
    }
};
