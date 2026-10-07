<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The firm's own questions on the public consultation form, per type of case, and the answers given. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', fn (Blueprint $table) => $table->json('intake_questions')->nullable());
        Schema::table('intake_requests', fn (Blueprint $table) => $table->json('answers')->nullable());
    }

    public function down(): void
    {
        Schema::table('intake_requests', fn (Blueprint $table) => $table->dropColumn('answers'));
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn('intake_questions'));
    }
};
