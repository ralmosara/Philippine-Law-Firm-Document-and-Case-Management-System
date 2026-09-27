<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deadline_rules', function (Blueprint $table) {
            $table->id();
            // Null firm_id = system rule shared by all firms (Rules of Court).
            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();
            $table->string('name');
            $table->string('trigger_event');
            $table->unsignedSmallInteger('period_days');
            $table->string('period_type', 16)->default('calendar'); // calendar | working_days
            $table->string('legal_basis')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_rules');
    }
};
