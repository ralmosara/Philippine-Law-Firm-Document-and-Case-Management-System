<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business development: prospective clients from first contact to a signed
 * engagement (or not), with where they came from, for a conversion report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('name');
            $table->string('organization')->nullable();
            $table->string('client_type', 12)->default('individual');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('source', 20);                     // referral | website | event | existing_client | walk_in | social_media | other
            $table->string('referred_by')->nullable();
            $table->string('case_type', 100)->nullable();
            $table->text('description')->nullable();
            $table->json('opposing_parties')->nullable();
            $table->bigInteger('estimated_value_cents')->nullable();
            $table->string('stage', 20)->default('lead');     // lead | consultation | proposal | engagement_sent | won | lost
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('next_step')->nullable();
            $table->date('next_step_on')->nullable();
            $table->date('reminded_on')->nullable();
            $table->date('proposal_sent_on')->nullable();
            $table->date('engagement_sent_on')->nullable();
            $table->date('engagement_signed_on')->nullable();
            $table->string('lost_reason', 500)->nullable();
            $table->json('conflict_check_ids')->nullable();
            $table->foreignId('intake_request_id')->nullable()->constrained('intake_requests')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['firm_id', 'stage']);
        });

        Schema::create('prospect_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained('prospects')->cascadeOnDelete();
            $table->string('type', 12);                       // note | call | meeting | email | stage
            $table->string('from_stage', 20)->nullable();
            $table->string('to_stage', 20)->nullable();
            $table->text('body')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        RowLevelSecurity::enable('prospects');
        RowLevelSecurity::enable('prospect_events');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('prospect_events');
        RowLevelSecurity::disable('prospects');
        Schema::dropIfExists('prospect_events');
        Schema::dropIfExists('prospects');
    }
};
