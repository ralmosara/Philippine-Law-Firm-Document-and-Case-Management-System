<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prescription of a matter's causes of action: when each arose, the
 * period that applies, interruptions that restarted it, the last day to
 * file (moved past weekends and holidays under Rule 22), and which
 * reminders have gone out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->string('period_key', 64)->nullable();      // PrescriptionPeriods key; null = custom
            $table->string('label');
            $table->unsignedSmallInteger('years')->default(0);
            $table->unsignedSmallInteger('months')->default(0);
            $table->string('basis', 500)->nullable();
            $table->boolean('interruptible')->default(true);
            $table->date('accrued_on');
            $table->date('runs_from');                           // accrued_on, or the last interruption
            $table->date('last_day');                            // nominal end of the period
            $table->date('file_by');                             // last_day moved past non-working days
            $table->json('interruptions')->nullable();           // [{date, kind, note}]
            $table->string('status', 16)->default('running');    // running | filed
            $table->date('filed_on')->nullable();
            $table->string('notes', 2000)->nullable();
            $table->smallInteger('reminded_days')->nullable();   // smallest reminder stage sent
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['firm_id', 'status', 'last_day']);
        });

        RowLevelSecurity::enable('matter_prescriptions');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('matter_prescriptions');
        Schema::dropIfExists('matter_prescriptions');
    }
};
