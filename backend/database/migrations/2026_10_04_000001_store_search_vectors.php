<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Full-text search kept its index on an expression, so any search that also
 * matched file names (an OR the index cannot serve) rebuilt every file's
 * tsvector on the fly, twice: to match and to rank. With 20,000 files that
 * took seconds. The vector is now a stored generated column, kept up to
 * date by PostgreSQL itself, indexed, and read instead of recomputed.
 *
 * File names are split into words first ("complaint-answer-2024.pdf"
 * becomes "complaint answer 2024 pdf"): the parser would otherwise keep the
 * whole name as one token, which is why searches also scanned names with
 * ILIKE. Now the index alone finds them.
 */
return new class extends Migration
{
    private const TABLES = [
        'matter_files' => "regexp_replace(coalesce(original_name, ''), '[^[:alnum:]]+', ' ', 'g') || ' ' || coalesce(description, '') || ' ' || coalesce(content_text, '')",
        'knowledge_items' => "coalesce(title, '') || ' ' || coalesce(citation, '') || ' ' || coalesce(doctrine, '') || ' ' || coalesce(body, '')",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table => $text) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', {$text})) STORED");
            DB::statement("CREATE INDEX {$table}_search_vector_idx ON {$table} USING gin (search_vector)");
            DB::statement("DROP INDEX IF EXISTS {$table}_search_idx");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table => $text) {
            DB::statement("DROP INDEX IF EXISTS {$table}_search_vector_idx");
            DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS search_vector");
            $original = $table === 'matter_files' ? "coalesce(original_name, '') || ' ' || coalesce(description, '') || ' ' || coalesce(content_text, '')" : $text;
            DB::statement("CREATE INDEX {$table}_search_idx ON {$table} USING gin (to_tsvector('simple', {$original}))");
        }
    }
};
