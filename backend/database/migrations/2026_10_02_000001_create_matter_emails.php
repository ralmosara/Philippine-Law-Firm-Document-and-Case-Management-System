<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email to matter: every matter gets a private address; mail sent or
 * forwarded to it is filed with the matter, message and attachments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->string('inbound_email_token', 16)->nullable()->unique();
        });

        Schema::create('matter_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->string('status', 12);                  // queued | review | filed | rejected
            $table->string('source', 12)->default('forward'); // forward | upload
            $table->string('message_id')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->text('to')->nullable();
            $table->text('cc')->nullable();
            $table->string('subject', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->longText('body_text')->nullable();
            $table->json('attachments')->nullable();       // [{name, size, file_id, skipped}]
            $table->string('raw_path', 512)->nullable();   // the message until it is filed
            $table->foreignId('eml_file_id')->nullable()->constrained('matter_files')->nullOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sender_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['matter_id', 'message_id']);
            $table->index(['firm_id', 'status']);
        });

        RowLevelSecurity::enable('matter_emails');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('matter_emails');
        Schema::dropIfExists('matter_emails');
        Schema::table('matters', function (Blueprint $table) {
            $table->dropUnique(['inbound_email_token']);
            $table->dropColumn('inbound_email_token');
        });
    }
};
