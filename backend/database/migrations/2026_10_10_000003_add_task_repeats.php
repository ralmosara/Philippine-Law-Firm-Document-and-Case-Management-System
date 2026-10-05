<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks that repeat (a monthly report to the client, a quarterly filing):
 * finishing one creates the next, until an optional end date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_deadlines', function (Blueprint $table) {
            $table->string('repeat', 10)->nullable();          // weekly | monthly | quarterly | yearly
            $table->date('repeat_until')->nullable();
            $table->foreignId('next_task_id')->nullable()->constrained('matter_deadlines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matter_deadlines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_task_id');
            $table->dropColumn(['repeat', 'repeat_until']);
        });
    }
};
