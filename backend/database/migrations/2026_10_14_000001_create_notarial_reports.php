<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notary's commission details (printed on the monthly report), and a
 * record of each month's certified copy of the register being submitted
 * to the clerk of court (2004 Rules on Notarial Practice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('notarial_commission_number', 60)->nullable();
            $table->string('notarial_commission_place', 120)->nullable();   // city or province of the commission
            $table->date('notarial_commission_expires_on')->nullable();
        });

        Schema::create('notarial_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('notary_id')->constrained('users')->cascadeOnDelete();
            $table->date('period');                                         // first day of the month reported
            $table->unsignedInteger('entries');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();                       // e.g. received by the clerk of court on ...
            $table->date('last_reminded_on')->nullable();
            $table->timestamps();
            $table->unique(['notary_id', 'period']);
        });
        RowLevelSecurity::enable('notarial_reports');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('notarial_reports');
        Schema::dropIfExists('notarial_reports');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['notarial_commission_number', 'notarial_commission_place', 'notarial_commission_expires_on']));
    }
};
