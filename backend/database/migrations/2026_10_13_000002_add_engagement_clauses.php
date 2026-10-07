<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The firm's own wording for the standard engagement-letter clauses (null: the built-in text). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', fn (Blueprint $table) => $table->json('engagement_clauses')->nullable());
    }

    public function down(): void
    {
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn('engagement_clauses'));
    }
};
