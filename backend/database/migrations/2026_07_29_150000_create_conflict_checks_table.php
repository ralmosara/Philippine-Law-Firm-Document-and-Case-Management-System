<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every conflict-of-interest search is recorded, with its results and the
     * lawyer's resolution, as evidence of compliance with Canon III of the
     * Code of Professional Responsibility and Accountability.
     */
    public function up(): void
    {
        Schema::create('conflict_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('search_term');
            $table->json('matches');
            $table->unsignedInteger('match_count');
            $table->string('status', 16); // clear | flagged | waived | declined
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['firm_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conflict_checks');
    }
};
