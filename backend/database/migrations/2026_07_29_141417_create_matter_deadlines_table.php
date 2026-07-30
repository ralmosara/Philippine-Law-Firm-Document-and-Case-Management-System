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
        Schema::create('matter_deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('deadline_rule_id')->nullable()->constrained('deadline_rules')->nullOnDelete();
            $table->date('trigger_date');
            $table->date('computed_due_date');
            $table->string('status')->default('pending'); // pending, met, missed, extended
            $table->string('escalation_stage')->default('none'); // none, 72hr, 24hr, day-of
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matter_deadlines');
    }
};
