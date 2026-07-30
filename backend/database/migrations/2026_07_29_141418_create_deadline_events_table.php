<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('deadline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_deadline_id')->constrained('matter_deadlines')->cascadeOnDelete();
            $table->string('event_type'); // 'reminder_sent', 'extended', 'missed', 'met'
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deadline_events');
    }
};
