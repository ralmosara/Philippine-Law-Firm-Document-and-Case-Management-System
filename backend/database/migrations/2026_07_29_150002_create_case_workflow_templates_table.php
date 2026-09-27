<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('case_type', 64);
            $table->string('name');
            $table->json('tasks');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['firm_id', 'case_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_workflow_templates');
    }
};
