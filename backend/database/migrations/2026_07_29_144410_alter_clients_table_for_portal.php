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
        Schema::table('clients', function (Blueprint $table) {
            $table->string('email')->nullable()->unique();
            $table->string('password')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Client Isolation Policy on Matters
            DB::statement("CREATE POLICY client_isolation_matters ON matters FOR SELECT USING (client_id = current_setting('app.current_client_id', true)::bigint);");
            
            // Client Isolation Policy on Trust Accounts
            DB::statement("CREATE POLICY client_isolation_trust ON trust_accounts FOR SELECT USING (matter_id IN (SELECT id FROM matters WHERE client_id = current_setting('app.current_client_id', true)::bigint));");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS client_isolation_trust ON trust_accounts;');
            DB::statement('DROP POLICY IF EXISTS client_isolation_matters ON matters;');
        }
        
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['email', 'password']);
        });
    }
};
