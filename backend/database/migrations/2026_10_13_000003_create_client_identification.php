<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowing the client (AMLA, RA 9160 as amended, for lawyers acting in
 * covered transactions): identification documents, the beneficial owners
 * of juridical clients, a risk rating and review; and a review record for
 * trust deposits at or above the amount the firm treats as covered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_identifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('id_type', 60);
            $table->string('id_number', 80);
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('path')->nullable();                       // the scan, on the private disk
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('reminded_stage', 10)->nullable();          // soon | expired
            $table->timestamps();
            $table->index(['firm_id', 'expires_on']);
        });
        RowLevelSecurity::enable('client_identifications');

        Schema::create('client_beneficial_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('ownership_bps')->nullable();     // 2500 = 25%
            $table->string('position', 120)->nullable();
            $table->string('nationality', 60)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
        RowLevelSecurity::enable('client_beneficial_owners');

        Schema::table('clients', function (Blueprint $table) {
            $table->string('kyc_risk', 10)->nullable();               // low | normal | high
            $table->boolean('is_pep')->default(false);                 // politically exposed person
            $table->text('kyc_notes')->nullable();
            $table->timestamp('kyc_reviewed_at')->nullable();
            $table->foreignId('kyc_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('firms', function (Blueprint $table) {
            $table->unsignedBigInteger('aml_threshold_cents')->default(50_000_000);
            $table->timestamp('aml_threshold_confirmed_at')->nullable();
        });

        Schema::create('aml_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedBigInteger('amount_cents');
            $table->json('trust_transaction_ids');
            $table->string('status', 16)->default('pending');        // pending | not_reportable | reported
            $table->text('notes')->nullable();
            $table->string('report_reference', 100)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['firm_id', 'client_id', 'day']);
        });
        RowLevelSecurity::enable('aml_reviews');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('aml_reviews');
        Schema::dropIfExists('aml_reviews');
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn(['aml_threshold_cents', 'aml_threshold_confirmed_at']));
        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kyc_reviewed_by');
            $table->dropColumn(['kyc_risk', 'is_pep', 'kyc_notes', 'kyc_reviewed_at']);
        });
        RowLevelSecurity::disable('client_beneficial_owners');
        Schema::dropIfExists('client_beneficial_owners');
        RowLevelSecurity::disable('client_identifications');
        Schema::dropIfExists('client_identifications');
    }
};
