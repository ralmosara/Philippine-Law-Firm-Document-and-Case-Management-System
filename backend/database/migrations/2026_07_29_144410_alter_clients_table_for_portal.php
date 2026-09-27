<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Client portal credentials. Portal access is opt-in per client; clients
     * log in with their email, which is unique within a firm.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('portal_enabled')->default(false);
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamp('last_portal_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['portal_enabled', 'password', 'remember_token', 'last_portal_login_at']);
        });
    }
};
