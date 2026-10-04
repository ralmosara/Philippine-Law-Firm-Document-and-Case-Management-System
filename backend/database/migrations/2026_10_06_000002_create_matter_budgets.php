<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A matter's budget: an estimate in pesos (fees, optionally with expenses)
 * or in hours, optionally split by stage, tracked against the time and
 * expenses logged, with alerts at 80% and 100%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->unique()->constrained('matters')->cascadeOnDelete();
            $table->string('basis', 8);                      // amount (centavos) | hours (minutes)
            $table->unsignedBigInteger('total');             // centavos or minutes
            $table->boolean('include_expenses')->default(true);
            $table->json('stages')->nullable();              // [{stage, total}]
            $table->boolean('shared_with_client')->default(false);
            $table->string('notes', 1000)->nullable();
            $table->timestamp('alerted_80_at')->nullable();
            $table->timestamp('alerted_100_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        RowLevelSecurity::enable('matter_budgets');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('matter_budgets');
        Schema::dropIfExists('matter_budgets');
    }
};
