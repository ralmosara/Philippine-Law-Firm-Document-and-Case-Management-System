<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained('document_versions')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('pending'); // pending | signed | declined | cancelled
            $table->string('message', 1000)->nullable();
            // SHA-256 of the exact content presented for signature.
            $table->char('content_sha256', 64);
            $table->timestamp('expires_at')->nullable();

            // Evidence of the signing act (RA 8792; Rules on Electronic Evidence).
            $table->timestamp('responded_at')->nullable();
            $table->string('signer_name')->nullable();
            $table->string('signature_method', 16)->nullable(); // drawn | typed
            $table->mediumText('signature_image')->nullable();  // PNG data URL when drawn
            $table->string('signer_ip', 45)->nullable();
            $table->string('signer_user_agent', 512)->nullable();
            $table->string('decline_reason', 1000)->nullable();
            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_requests');
    }
};
