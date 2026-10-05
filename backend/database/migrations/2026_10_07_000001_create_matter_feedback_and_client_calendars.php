<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's side of a finished matter, and of upcoming hearings:
 * feedback asked for when a matter closes (a rating, a comment, and the
 * firm's follow-up of a poor one), and each portal client's private
 * calendar subscription to their own hearings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->unique()->constrained('matters')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->timestamp('requested_at');
            $table->unsignedTinyInteger('rating')->nullable();      // 1 to 5
            $table->text('comment')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('followed_up_at')->nullable();
            $table->foreignId('followed_up_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('follow_up_note', 2000)->nullable();
            $table->timestamps();
            $table->index(['firm_id', 'responded_at']);
        });
        RowLevelSecurity::enable('matter_feedback');

        Schema::table('clients', function (Blueprint $table) {
            $table->char('calendar_token_hash', 64)->nullable()->index();
            $table->timestamp('calendar_created_at')->nullable();
            $table->timestamp('calendar_accessed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['calendar_token_hash', 'calendar_created_at', 'calendar_accessed_at']));
        RowLevelSecurity::disable('matter_feedback');
        Schema::dropIfExists('matter_feedback');
    }
};
