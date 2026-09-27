<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private calendar subscriptions (iCalendar feeds). The URL is the only
 * credential, so only a hash of its token is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('scope', 8)->default('mine'); // mine | firm
            // Off: events show only the kind and matter reference, never client names.
            $table->boolean('show_details')->default(false);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
        });

        RowLevelSecurity::enable('calendar_feeds');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('calendar_feeds');
        Schema::dropIfExists('calendar_feeds');
    }
};
