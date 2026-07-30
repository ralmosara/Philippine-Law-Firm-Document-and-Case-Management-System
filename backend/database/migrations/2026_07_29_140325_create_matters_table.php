<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('matters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('case_number')->nullable();
            $table->string('case_type')->nullable();
            $table->string('court_branch')->nullable();
            $table->string('judge')->nullable();
            $table->string('docket_number')->nullable();
            $table->string('status')->default('intake');
            $table->date('opened_at')->nullable();
            $table->date('closed_at')->nullable();
            $table->timestamps();
        });

        // Postgres Row-Level Security (RLS)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE matters ENABLE ROW LEVEL SECURITY;');
            DB::statement("CREATE POLICY firm_isolation ON matters USING (firm_id = current_setting('app.current_firm_id', true)::bigint);");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS firm_isolation ON matters;');
        }
        Schema::dropIfExists('matters');
    }
};
