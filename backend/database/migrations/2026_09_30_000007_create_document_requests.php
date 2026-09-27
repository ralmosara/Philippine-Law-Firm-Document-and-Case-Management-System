<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checklists of documents the firm needs from a client ("valid ID, the
 * contract, the SPA"), uploaded item by item through the portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('title');
            $table->text('message')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status', 16);                 // open | completed | cancelled
            $table->string('last_reminder', 16)->nullable(); // due_soon | overdue
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index(['client_id', 'status']);
        });

        Schema::create('document_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('document_request_id')->constrained('document_requests')->cascadeOnDelete();
            $table->string('label');
            $table->string('description', 1000)->nullable();
            $table->boolean('required')->default(true);
            $table->string('status', 16);                 // pending | uploaded | accepted | rejected
            $table->foreignId('matter_file_id')->nullable()->constrained('matter_files')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        RowLevelSecurity::enable('document_requests');
        RowLevelSecurity::enable('document_request_items');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('document_request_items');
        RowLevelSecurity::disable('document_requests');
        Schema::dropIfExists('document_request_items');
        Schema::dropIfExists('document_requests');
    }
};
