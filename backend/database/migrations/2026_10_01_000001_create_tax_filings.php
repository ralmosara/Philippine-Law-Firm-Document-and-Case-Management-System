<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The firm's own BIR returns: a calendar of what is due, what was filed and
 * when, and the settings that decide which returns and codes apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_filings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('form', 16);                 // 2550Q, 1702Q, 1601EQ, ...
            $table->string('period', 16);               // 2026, 2026-Q3 or 2026-09
            $table->date('due_on');
            $table->string('status', 16);               // pending | filed | not_applicable
            $table->date('filed_on')->nullable();
            $table->string('reference', 100)->nullable(); // eFPS/eBIRForms confirmation, payment reference
            $table->text('notes')->nullable();
            $table->string('last_reminder', 16)->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['firm_id', 'form', 'period']);
            $table->index(['firm_id', 'status', 'due_on']);
        });

        Schema::table('firms', function (Blueprint $table) {
            // individual (sole practitioner) | juridical (partnership or corporation): decides 1701/1702 and the ATC.
            $table->string('taxpayer_type', 16)->default('juridical');
            // Code clients use on Form 2307 for fees paid to the firm; shown on the SAWT.
            $table->string('withholding_atc', 8)->default('WC010');
            // Payroll returns (1601-C, 1604-C) only matter to firms with employees.
            $table->boolean('has_employees')->default(true);
        });

        RowLevelSecurity::enable('tax_filings');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('tax_filings');
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn(['taxpayer_type', 'withholding_atc', 'has_employees']));
        Schema::dropIfExists('tax_filings');
    }
};
