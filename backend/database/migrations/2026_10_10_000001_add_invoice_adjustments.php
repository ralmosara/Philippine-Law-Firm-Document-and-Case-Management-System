<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the firm chose not to charge: lines written down and discounts
 * given before a bill is issued, and balances written off after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('original_amount_cents')->nullable();   // set once written down
            $table->string('adjustment_reason')->nullable();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('discount_cents')->default(0);           // off professional fees, before VAT
            $table->string('discount_reason')->nullable();
            $table->unsignedBigInteger('written_off_cents')->default(0);
            $table->timestamp('written_off_at')->nullable();
            $table->foreignId('written_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('write_off_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('written_off_by');
            $table->dropColumn(['discount_cents', 'discount_reason', 'written_off_cents', 'written_off_at', 'write_off_reason']);
        });
        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropColumn(['original_amount_cents', 'adjustment_reason']));
    }
};
