<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly statements of account emailed to clients, and reminders to
 * lawyers and paralegals who logged less time than their daily target.
 * Both are off until the firm turns them on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->boolean('statements_enabled')->default(false);
            $table->unsignedTinyInteger('statement_day')->default(1);          // 1-28
            $table->boolean('time_reminders_enabled')->default(false);
            $table->unsignedSmallInteger('daily_target_minutes')->default(360);
        });
        Schema::table('clients', fn (Blueprint $table) => $table->date('statement_sent_on')->nullable());
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('daily_target_minutes')->nullable();  // null: the firm's; 0: none
            $table->date('time_reminded_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn(['statements_enabled', 'statement_day', 'time_reminders_enabled', 'daily_target_minutes']));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('statement_sent_on'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['daily_target_minutes', 'time_reminded_for']));
    }
};
