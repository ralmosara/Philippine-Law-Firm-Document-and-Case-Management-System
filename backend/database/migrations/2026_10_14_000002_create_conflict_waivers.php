<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Written informed consents to the firm acting despite a possible conflict
 * of interest, signed online by each person concerned and kept with the
 * conflict check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conflict_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('conflict_check_id')->constrained('conflict_checks')->cascadeOnDelete();
            $table->string('signer_name');
            $table->string('signer_email');
            $table->string('status', 16)->default('sent');           // sent | signed | declined | cancelled
            $table->mediumText('content');
            $table->char('content_sha256', 64);
            $table->char('token_hash', 64)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('signed_name')->nullable();
            $table->string('signature_method', 16)->nullable();
            $table->mediumText('signature_image')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->string('signer_user_agent', 512)->nullable();
            $table->string('decline_reason', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        RowLevelSecurity::enable('conflict_waivers');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('conflict_waivers');
        Schema::dropIfExists('conflict_waivers');
    }
};
