<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partial payments and creditable withholding tax.
 *
 * Corporate clients withhold tax on professional fees and pay the rest, then
 * hand over BIR Form 2307. Each payment records both parts; together they
 * settle the invoice. Payments are never deleted: a mistake is voided.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->date('received_on');
            $table->string('method', 16); // cash | check | bank_transfer | e_wallet | card | online | trust | other
            $table->unsignedBigInteger('amount_cents');        // money actually received
            $table->unsignedBigInteger('withholding_cents')->default(0); // tax withheld by the client
            $table->string('reference', 100)->nullable();      // OR/check/transaction number
            $table->text('notes')->nullable();
            $table->timestamp('form_2307_received_at')->nullable();
            $table->foreignId('form_2307_file_id')->nullable()->constrained('matter_files')->nullOnDelete();
            $table->foreignId('trust_transaction_id')->nullable()->constrained('trust_transactions')->nullOnDelete();
            $table->foreignId('online_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['firm_id', 'received_on']);
            $table->index(['invoice_id', 'voided_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Kept in step with the payments: cash plus withholding credited.
            $table->unsignedBigInteger('settled_cents')->default(0);
            $table->unsignedBigInteger('withholding_cents')->default(0);
        });

        Schema::table('firms', function (Blueprint $table) {
            // Suggested creditable withholding rate on professional fees (1000 = 10%).
            $table->unsignedInteger('default_withholding_bps')->default(1000);
        });

        // Invoices already paid in full get a matching payment record.
        foreach (DB::table('invoices')->where('status', 'paid')->get() as $invoice) {
            DB::table('invoice_payments')->insert([
                'firm_id' => $invoice->firm_id,
                'invoice_id' => $invoice->id,
                'received_on' => substr((string) ($invoice->paid_at ?? $invoice->updated_at), 0, 10),
                'method' => 'other',
                'amount_cents' => $invoice->total_cents,
                'reference' => $invoice->payment_reference,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('invoices')->where('id', $invoice->id)->update(['settled_cents' => $invoice->total_cents]);
        }

        RowLevelSecurity::enable('invoice_payments');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('invoice_payments');
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn('default_withholding_bps'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['settled_cents', 'withholding_cents']));
        Schema::dropIfExists('invoice_payments');
    }
};
