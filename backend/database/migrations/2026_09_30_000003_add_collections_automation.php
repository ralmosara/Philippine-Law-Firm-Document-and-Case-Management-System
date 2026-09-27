<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Collections automation: monthly retainer invoices, payment reminders to
 * clients, and requests to top up a trust deposit that ran low.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->boolean('retainer_auto_bill')->default(false);
            $table->unsignedTinyInteger('retainer_billing_day')->default(1);   // 1-28
            $table->boolean('retainer_auto_issue')->default(false);            // false: left as a draft to review
            $table->date('retainer_billed_through')->nullable();               // first day of the last month billed
        });

        Schema::table('firms', function (Blueprint $table) {
            $table->boolean('payment_reminders_enabled')->default(false);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('reminders_paused_at')->nullable();
        });

        Schema::create('invoice_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('stage', 16);            // due_soon | overdue_7 | overdue_30 | manual
            $table->unsignedBigInteger('balance_cents');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');

            $table->index(['invoice_id', 'sent_at']);
        });

        Schema::table('trust_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('minimum_balance_cents')->nullable();
            $table->timestamp('replenishment_requested_at')->nullable();
        });

        RowLevelSecurity::enable('invoice_reminders');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('invoice_reminders');
        Schema::table('trust_accounts', fn (Blueprint $table) => $table->dropColumn(['minimum_balance_cents', 'replenishment_requested_at']));
        Schema::dropIfExists('invoice_reminders');
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('reminders_paused_at'));
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn('payment_reminders_enabled'));
        Schema::table('matters', fn (Blueprint $table) => $table->dropColumn(['retainer_auto_bill', 'retainer_billing_day', 'retainer_auto_issue', 'retainer_billed_through']));
    }
};
