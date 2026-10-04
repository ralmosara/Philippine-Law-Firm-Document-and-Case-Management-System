<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client portal in English or Filipino: each client's language (also
 * used for the emails the firm sends them), and a firm's own Filipino
 * privacy notice next to its English one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('locale', 8)->default('en');
        });

        Schema::table('firms', function (Blueprint $table) {
            $table->text('privacy_notice_fil')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('locale'));
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn('privacy_notice_fil'));
    }
};
