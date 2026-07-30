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
        Schema::create('deadline_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trigger_event');
            $table->integer('period_days');
            $table->string('period_type'); // 'calendar' | 'working_days'
            $table->text('computation_notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deadline_rules');
    }
};
