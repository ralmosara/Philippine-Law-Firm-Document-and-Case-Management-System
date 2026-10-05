<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When and where a lawyer's PTR was paid and IBP dues were paid, so the
 * firm knows whether they are for this year (both are renewed each January;
 * IBP lifetime members pay no annual dues).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('ptr_date')->nullable();
            $table->string('ptr_place', 100)->nullable();
            $table->date('ibp_date')->nullable();
            $table->string('ibp_chapter', 100)->nullable();
            $table->boolean('ibp_lifetime')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['ptr_date', 'ptr_place', 'ibp_date', 'ibp_chapter', 'ibp_lifetime']));
    }
};
