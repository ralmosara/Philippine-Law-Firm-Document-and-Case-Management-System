<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deposit slips and transfer screenshots clients upload against a bill in
 * the portal. Finance confirms each (recording the payment) or rejects it
 * with a reason the client sees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('matter_file_id')->nullable()->constrained('matter_files')->nullOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->date('paid_on');
            $table->string('method', 16);
            $table->string('reference', 100)->nullable();
            $table->string('note', 1000)->nullable();
            $table->string('status', 16)->default('pending');        // pending | confirmed | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason', 1000)->nullable();
            $table->foreignId('invoice_payment_id')->nullable()->constrained('invoice_payments')->nullOnDelete();
            $table->timestamps();
            $table->index(['firm_id', 'status']);
        });

        RowLevelSecurity::enable('payment_proofs');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('payment_proofs');
        Schema::dropIfExists('payment_proofs');
    }
};
