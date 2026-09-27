<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcle_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('mcle_compliance_periods')->cascadeOnDelete();
            $table->string('title');
            $table->string('provider')->nullable();
            $table->string('subject_area', 64)->nullable(); // e.g. legal ethics, trial practice
            $table->decimal('units', 5, 2);
            $table->date('date_earned');
            $table->string('certificate_number', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcle_credits');
    }
};
