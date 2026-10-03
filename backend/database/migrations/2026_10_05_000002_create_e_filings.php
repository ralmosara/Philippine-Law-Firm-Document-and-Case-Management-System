<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Electronic filing: the single PDF prepared for the court (the pleading
 * and its annexes, bookmarked and paginated), the checks it passed, and
 * the record of when, how and where it was filed and acknowledged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_filings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('title');
            $table->json('items');                        // [{kind, id, label, name, pages, first_page}]
            $table->foreignId('package_file_id')->nullable()->constrained('matter_files')->nullOnDelete();
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->json('checks')->nullable();           // [{level: ok|warning|error, message}]
            $table->string('status', 16)->default('prepared'); // prepared | filed | acknowledged
            $table->timestamp('filed_at')->nullable();
            $table->string('filed_via', 16)->nullable();   // email | ecourt | in_person | courier | other
            $table->string('filed_to')->nullable();         // the court's e-mail or portal
            $table->string('filing_reference', 100)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgment', 500)->nullable();
            $table->foreignId('acknowledgment_file_id')->nullable()->constrained('matter_files')->nullOnDelete();
            $table->foreignId('deadline_id')->nullable()->constrained('matter_deadlines')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['firm_id', 'matter_id']);
        });

        RowLevelSecurity::enable('e_filings');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('e_filings');
        Schema::dropIfExists('e_filings');
    }
};
