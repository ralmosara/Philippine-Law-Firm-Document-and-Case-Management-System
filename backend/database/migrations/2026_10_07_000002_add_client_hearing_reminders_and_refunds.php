<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hearing reminders for clients (off until the firm turns them on; a client
 * or a single hearing can be left out), and refunds through PayMongo of
 * online payments that could not be applied to their invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', fn (Blueprint $table) => $table->boolean('client_hearing_reminders')->default(false));
        Schema::table('clients', fn (Blueprint $table) => $table->boolean('hearing_reminders')->default(true));
        Schema::table('matter_deadlines', function (Blueprint $table) {
            $table->boolean('notify_client')->default(true);
            $table->string('client_reminded_stage', 8)->nullable();   // week | day
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('refund_id', 191)->nullable();
            $table->string('refund_status', 16)->nullable();          // pending | succeeded | failed
            $table->string('refund_reason', 500)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn(['refund_id', 'refund_status', 'refund_reason', 'refunded_at']);
        });
        Schema::table('matter_deadlines', fn (Blueprint $table) => $table->dropColumn(['notify_client', 'client_reminded_stage']));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('hearing_reminders'));
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn('client_hearing_reminders'));
    }
};
