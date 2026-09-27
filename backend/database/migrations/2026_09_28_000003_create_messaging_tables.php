<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Secure messaging between a client and the firm, per matter. Messages are
 * append-only (a record of attorney-client communication); read status is
 * tracked per side on the thread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('subject');
            $table->timestamp('last_message_at')->nullable();
            // Highest message id each side has seen (ids, not timestamps: exact within a second).
            $table->unsignedBigInteger('staff_last_read_id')->default(0);
            $table->unsignedBigInteger('client_last_read_id')->default(0);
            $table->timestamps();

            $table->index(['firm_id', 'last_message_at']);
            $table->index(['client_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained('message_threads')->cascadeOnDelete();
            $table->string('sender_type', 16); // user | client (morph map)
            $table->unsignedBigInteger('sender_id');
            $table->text('body');
            $table->foreignId('matter_file_id')->nullable()->constrained('matter_files')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['thread_id', 'created_at']);
        });

        Schema::table('matter_files', function (Blueprint $table) {
            $table->foreignId('uploaded_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
        });

        RowLevelSecurity::enable('message_threads', 'messages');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('message_threads', 'messages');

        Schema::table('matter_files', fn (Blueprint $table) => $table->dropConstrainedForeignId('uploaded_by_client_id'));
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
    }
};
