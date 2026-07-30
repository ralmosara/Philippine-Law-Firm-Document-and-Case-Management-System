<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->string('ibp_number')->nullable();
            $table->date('mcle_compliance_until')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['firm_id']);
            $table->dropColumn(['firm_id', 'role', 'ibp_number', 'mcle_compliance_until']);
        });
    }
};
