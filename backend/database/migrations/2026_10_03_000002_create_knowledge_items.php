<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The firm's knowledge bank: pleadings that worked, clauses, forms and
 * jurisprudence notes (G.R. numbers with their doctrines), searchable and
 * reusable in drafting and by the assistant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('kind', 16);                   // pleading | clause | jurisprudence | form | note
            $table->string('title', 300);
            $table->string('citation', 300)->nullable();  // "G.R. No. 123456, March 3, 2020"
            $table->text('doctrine')->nullable();         // the ruling or rule, in a sentence or two
            $table->longText('body')->nullable();
            $table->string('practice_area', 100)->nullable(); // a matter case type
            $table->json('tags')->nullable();
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('source_matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['firm_id', 'kind']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE INDEX knowledge_items_search_idx ON knowledge_items USING gin (
                    to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(citation, '') || ' ' || coalesce(doctrine, '') || ' ' || coalesce(body, ''))
                )
                SQL);
        }

        RowLevelSecurity::enable('knowledge_items');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('knowledge_items');
        Schema::dropIfExists('knowledge_items');
    }
};
