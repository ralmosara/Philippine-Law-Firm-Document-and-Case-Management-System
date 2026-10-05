<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The monthly three-way reconciliation of client trust funds: the bank
 * statement (adjusted for deposits in transit and uncleared cheques), the
 * trust ledger, and the sum of the client balances, signed off by a partner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->date('period_end');                                   // last day of the month reconciled
            $table->string('bank_account', 120);
            $table->bigInteger('statement_balance_cents');
            $table->json('deposits_in_transit');                          // [{description, amount_cents}]
            $table->json('outstanding_checks');                           // [{description, amount_cents}]
            $table->bigInteger('adjusted_bank_cents');
            $table->bigInteger('ledger_cents');
            $table->bigInteger('client_total_cents');
            $table->json('exceptions');                                   // accounts below zero, ledger mismatches
            $table->text('notes')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('signed_off_at')->nullable();
            $table->timestamps();
            $table->unique(['firm_id', 'period_end']);
        });

        RowLevelSecurity::enable('trust_reconciliations');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('trust_reconciliations');
        Schema::dropIfExists('trust_reconciliations');
    }
};
