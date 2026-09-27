<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('firm_id')->nullable()->after('id')->constrained('firms')->cascadeOnDelete();
            $table->string('role', 32)->default('associate');
            $table->string('ibp_number', 32)->nullable();
            $table->string('roll_number', 32)->nullable();
            $table->string('mobile_number', 20)->nullable();
            $table->unsignedBigInteger('hourly_rate_cents')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();

            $table->index(['firm_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('firm_id');
            $table->dropColumn(['role', 'ibp_number', 'roll_number', 'mobile_number', 'hourly_rate_cents', 'is_active', 'last_login_at']);
        });
    }
};
