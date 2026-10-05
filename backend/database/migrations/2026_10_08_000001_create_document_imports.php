<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk import of documents: a ZIP with one folder per matter, uploaded in
 * pieces, previewed (folders matched to matters), then filed in the
 * background, each file scanned and made searchable like any upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('filename');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->unsignedInteger('received_chunks')->default(0);
            $table->string('status', 16)->default('uploading'); // uploading | previewed | importing | done | failed | undone
            $table->json('folders')->nullable();     // [{folder, matter_id, matched_by, files: [{entry, name, path, size, status, reason, file_id}]}]
            $table->json('summary')->nullable();
            $table->string('error', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
        });

        RowLevelSecurity::enable('document_imports');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('document_imports');
        Schema::dropIfExists('document_imports');
    }
};
