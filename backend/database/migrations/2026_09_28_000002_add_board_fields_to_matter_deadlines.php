<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks (deadlines of kind "task") move across a board while open:
 * to do -> in progress -> for review. Completing a task is the "done" column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_deadlines', function (Blueprint $table) {
            $table->string('progress', 16)->default('todo'); // todo | in_progress | review
            $table->string('priority', 8)->default('normal'); // low | normal | high | urgent
            $table->index(['firm_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('matter_deadlines', function (Blueprint $table) {
            $table->dropIndex(['firm_id', 'kind', 'status']);
            $table->dropColumn(['progress', 'priority']);
        });
    }
};
