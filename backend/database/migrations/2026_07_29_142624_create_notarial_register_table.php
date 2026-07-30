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
        Schema::create('notarial_register', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('doc_number');
            $table->string('page_number');
            $table->string('book_number');
            $table->integer('series_year');
            $table->foreignId('notarized_by')->constrained('users')->cascadeOnDelete(); // The notary public (lawyer)
            $table->timestamps();
            
            $table->unique(['notarized_by', 'series_year', 'doc_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notarial_register');
    }
};
