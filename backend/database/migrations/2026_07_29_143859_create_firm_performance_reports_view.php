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
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('
                CREATE MATERIALIZED VIEW firm_performance_reports AS
                SELECT 
                    f.id as firm_id,
                    COUNT(m.id) as total_matters,
                    SUM(CASE WHEN m.status = \'open\' THEN 1 ELSE 0 END) as active_matters,
                    COALESCE(SUM(i.total_amount_cents), 0) as total_billed_cents
                FROM firms f
                LEFT JOIN matters m ON m.firm_id = f.id
                LEFT JOIN invoices i ON i.matter_id = m.id AND i.status = \'paid\'
                GROUP BY f.id;
                
                CREATE UNIQUE INDEX firm_performance_reports_firm_id_idx ON firm_performance_reports (firm_id);
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP MATERIALIZED VIEW IF EXISTS firm_performance_reports;');
        }
    }
};
