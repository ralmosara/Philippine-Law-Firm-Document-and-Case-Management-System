<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notarial register per the 2004 Rules on Notarial Practice (Rule VI):
     * every notarial act is entered with Doc. No., Page No., Book No. and
     * Series (year). The doc number is unique per notary, book and series.
     */
    public function up(): void
    {
        Schema::create('notarial_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('notary_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->unsignedInteger('doc_number');
            $table->unsignedInteger('page_number');
            $table->unsignedInteger('book_number');
            $table->unsignedSmallInteger('series_year');
            $table->string('act_type', 32); // acknowledgment | jurat | oath | copy_certification | signature_witnessing
            $table->string('document_title');
            $table->string('principal_name');
            $table->string('competent_evidence')->nullable(); // ID presented, per Rule II Sec. 12
            $table->unsignedBigInteger('fee_cents')->default(0);
            $table->timestamp('notarized_at');
            $table->timestamps();

            $table->unique(['notary_id', 'series_year', 'book_number', 'doc_number'], 'notarial_entries_register_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notarial_entries');
    }
};
