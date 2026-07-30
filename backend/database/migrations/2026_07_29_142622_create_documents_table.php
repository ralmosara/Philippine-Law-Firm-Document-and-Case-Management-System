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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->string('title');
            $table->string('status')->default('draft'); // draft, final, notarized
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
