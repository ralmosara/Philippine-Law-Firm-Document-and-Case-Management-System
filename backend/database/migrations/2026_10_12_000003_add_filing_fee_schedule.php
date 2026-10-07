<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The firm's table of court filing fees (Rule 141) used to estimate what a
 * complaint will cost to file. Estimates are refused until a managing
 * partner has reviewed the table against the current rules and confirmed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->json('filing_fee_schedule')->nullable();
            $table->timestamp('filing_fee_schedule_confirmed_at')->nullable();
            $table->foreignId('filing_fee_schedule_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('filing_fee_schedule_confirmed_by');
            $table->dropColumn(['filing_fee_schedule', 'filing_fee_schedule_confirmed_at']);
        });
    }
};
