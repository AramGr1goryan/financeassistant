<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_expectations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('description');
            $table->bigInteger('amount_minor_units');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_exponent');
            $table->date('expected_date');
            $table->enum('status', ['PLANNED', 'COMPLETED']);
            $table->timestampsTz();

            $table->index(['owner_id', 'expected_date', 'id']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE income_expectations ADD CONSTRAINT income_expectations_values_check CHECK (amount_minor_units > 0 AND currency ~ '^[A-Z]{3}$' AND currency_exponent BETWEEN 0 AND 6 AND (currency <> 'AMD' OR currency_exponent = 0))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('income_expectations');
    }
};
