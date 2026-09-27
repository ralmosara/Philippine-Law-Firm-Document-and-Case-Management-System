<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_files', function (Blueprint $table) {
            // clean | not_scanned (scanning turned off). Infected files are never stored.
            $table->string('scan_status', 16)->default('not_scanned');
            $table->timestamp('scanned_at')->nullable();
            // pending | extracted | unsupported | failed
            $table->string('text_status', 16)->default('pending');
            $table->longText('content_text')->nullable();
        });

        // Full-text index over name, description and extracted text. The
        // 'simple' configuration does no stemming, which suits documents
        // mixing English, Filipino and proper names.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE INDEX matter_files_search_idx ON matter_files USING gin (
                    to_tsvector('simple', coalesce(original_name, '') || ' ' || coalesce(description, '') || ' ' || coalesce(content_text, ''))
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS matter_files_search_idx');
        }

        Schema::table('matter_files', function (Blueprint $table) {
            $table->dropColumn(['scan_status', 'scanned_at', 'text_status', 'content_text']);
        });
    }
};
