<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The matter assistant (Claude). Off until the firm opts in, because it
 * sends matter documents to a third-party processor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(false);
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();

            $table->index(['matter_id', 'user_id']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 16);                      // user | assistant
            $table->longText('content')->nullable();
            $table->string('status', 16)->default('complete'); // pending | complete | failed
            $table->string('error', 500)->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->json('sources')->nullable();             // ids sent as context, for the audit trail
            $table->timestamps();
        });

        RowLevelSecurity::enable('ai_conversations', 'ai_messages');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('ai_conversations', 'ai_messages');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn('ai_enabled'));
    }
};
