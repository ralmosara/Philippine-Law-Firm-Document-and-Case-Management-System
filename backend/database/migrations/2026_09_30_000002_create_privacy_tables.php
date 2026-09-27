<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data Privacy Act of 2012 (RA 10173): the firm's privacy notice and its
 * data protection officer, what each client accepted and when, requests
 * from data subjects, the retention period, and the breach log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->string('dpo_name')->nullable();
            $table->string('dpo_email')->nullable();
            $table->text('privacy_notice')->nullable();          // null: the built-in notice
            $table->unsignedInteger('privacy_notice_version')->default(1);
            $table->timestamp('privacy_notice_updated_at')->nullable();
            $table->unsignedSmallInteger('retention_years')->default(10);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('privacy_notice_version')->nullable();
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
        });

        Schema::table('intake_requests', function (Blueprint $table) {
            $table->unsignedInteger('privacy_notice_version')->nullable();
        });

        Schema::table('matters', function (Blueprint $table) {
            $table->timestamp('disposed_at')->nullable();
        });

        Schema::create('data_subject_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('requester_name');
            $table->string('requester_email')->nullable();
            $table->string('type', 16);               // access | correction | erasure | objection | portability
            $table->text('details')->nullable();
            $table->string('source', 16);             // portal | staff
            $table->string('status', 16);             // open | completed | denied
            $table->date('due_on');
            $table->text('resolution')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['firm_id', 'status', 'due_on']);
        });

        Schema::create('privacy_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->timestamp('discovered_at');
            $table->timestamp('occurred_at')->nullable();
            $table->unsignedInteger('affected_count')->nullable();
            $table->string('data_involved', 1000)->nullable();
            $table->boolean('sensitive')->default(false);      // sensitive personal information involved
            $table->boolean('notifiable')->default(true);      // real risk of serious harm: NPC and subjects must be told
            $table->timestamp('npc_notified_at')->nullable();
            $table->timestamp('subjects_notified_at')->nullable();
            $table->text('actions_taken')->nullable();
            $table->string('status', 16);                      // open | contained | closed
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['firm_id', 'status']);
        });

        RowLevelSecurity::enable('data_subject_requests');
        RowLevelSecurity::enable('privacy_incidents');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('privacy_incidents');
        RowLevelSecurity::disable('data_subject_requests');
        Schema::dropIfExists('privacy_incidents');
        Schema::dropIfExists('data_subject_requests');
        Schema::table('matters', fn (Blueprint $table) => $table->dropColumn('disposed_at'));
        Schema::table('intake_requests', fn (Blueprint $table) => $table->dropColumn('privacy_notice_version'));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['privacy_notice_version', 'privacy_accepted_at', 'anonymized_at']));
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn(['dpo_name', 'dpo_email', 'privacy_notice', 'privacy_notice_version', 'privacy_notice_updated_at', 'retention_years']));
    }
};
