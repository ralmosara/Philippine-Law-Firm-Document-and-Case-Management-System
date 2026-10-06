<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement letters sent to prospects for signature through a private
 * link (they have no portal account yet). Signing converts the prospect
 * into a client and matter, with the signed letter filed on the matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained('prospects')->cascadeOnDelete();
            $table->string('status', 16)->default('draft');          // draft | sent | signed | declined | cancelled
            $table->string('fee_arrangement', 16);
            $table->unsignedBigInteger('fixed_fee_cents')->nullable();
            $table->unsignedBigInteger('acceptance_fee_cents')->nullable();
            $table->unsignedBigInteger('appearance_fee_cents')->nullable();
            $table->unsignedInteger('contingency_basis_points')->nullable();
            $table->text('scope');
            $table->mediumText('content');
            $table->char('content_sha256', 64)->nullable();            // fixed when sent
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Evidence of the signing act (RA 8792; Rules on Electronic Evidence).
            $table->timestamp('responded_at')->nullable();
            $table->string('signer_name')->nullable();
            $table->string('signature_method', 16)->nullable();
            $table->mediumText('signature_image')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->string('signer_user_agent', 512)->nullable();
            $table->string('decline_reason', 1000)->nullable();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['firm_id', 'prospect_id']);
        });

        RowLevelSecurity::enable('engagement_letters');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('engagement_letters');
        Schema::dropIfExists('engagement_letters');
    }
};
