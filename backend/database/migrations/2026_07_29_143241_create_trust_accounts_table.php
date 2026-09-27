<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_accounts', function (Blueprint $table) {
            $table->id();
            // Financial records must never disappear through a cascade.
            $table->foreignId('firm_id')->constrained('firms')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->restrictOnDelete();
            $table->string('account_number', 32);
            // Cached running balance, maintained by TrustLedgerService under a
            // row lock and verified nightly by `trust:reconcile`.
            $table->bigInteger('balance_cents')->default(0);
            $table->string('status', 16)->default('open'); // open | closed
            $table->timestamps();

            $table->unique(['firm_id', 'account_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_accounts');
    }
};
