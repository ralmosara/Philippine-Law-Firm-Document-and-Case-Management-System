<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A lawyer away (leave, a trip, illness) and who covers for them meanwhile. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('away_from')->nullable();
            $table->date('away_until')->nullable();
            $table->foreignId('cover_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_user_id');
            $table->dropColumn(['away_from', 'away_until']);
        });
    }
};
