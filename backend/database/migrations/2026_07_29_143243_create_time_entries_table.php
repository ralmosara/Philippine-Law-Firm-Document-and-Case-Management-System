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
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('lawyer_id')->constrained('users')->cascadeOnDelete();
            $table->integer('duration_minutes');
            $table->text('description');
            $table->bigInteger('billable_rate_cents');
            $table->string('status')->default('unbilled'); // unbilled, billed, written_off
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
