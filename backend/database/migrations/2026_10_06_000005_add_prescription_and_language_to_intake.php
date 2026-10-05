<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intake requests: when the applicant says the problem arose (so a lawyer
 * can see, before taking the case, whether it is about to prescribe), the
 * period the lawyer judged applies, and the language the applicant used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_requests', function (Blueprint $table) {
            $table->date('incident_on')->nullable();
            $table->string('prescription_period_key', 64)->nullable();
            $table->string('locale', 8)->default('en');
        });
    }

    public function down(): void
    {
        Schema::table('intake_requests', fn (Blueprint $table) => $table->dropColumn(['incident_on', 'prescription_period_key', 'locale']));
    }
};
