<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Superseded: firm analytics are computed by AnalyticsService from indexed
 * base tables, which works on every supported driver and is never stale.
 * Kept as a no-op so existing migration history stays consistent; safe to
 * delete on a fresh install.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
