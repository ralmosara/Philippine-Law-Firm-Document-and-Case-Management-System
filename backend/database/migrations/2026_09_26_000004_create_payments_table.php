<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('provider', 32);                     // paymongo
            $table->string('checkout_id', 128)->unique();       // the provider's checkout session
            $table->text('checkout_url');
            $table->string('provider_payment_id', 128)->nullable();
            $table->unsignedBigInteger('amount_cents');
            // pending | paid | unapplied (money received, but the invoice was no longer payable)
            $table->string('status', 16)->default('pending');
            $table->string('method', 32)->nullable();           // gcash, card, paymaya, ...
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['firm_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
