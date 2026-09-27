<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only log of what happened to a deadline and when: reminders sent,
     * completion, misses. Answers "what did we know, and when".
     */
    public function up(): void
    {
        Schema::create('deadline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_deadline_id')->constrained('matter_deadlines')->cascadeOnDelete();
            $table->string('event_type', 32); // created | reminder_sent | completed | missed | cancelled | rescheduled
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['matter_deadline_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deadline_events');
    }
};
