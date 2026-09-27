<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online intake: prospective clients request a consultation through the
 * firm's public page. Each request is conflict-checked automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->string('slug', 64)->nullable()->unique();   // /intake/{slug}
            $table->boolean('intake_enabled')->default(false);
            $table->text('intake_message')->nullable();          // shown above the public form
        });

        Schema::create('intake_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('status', 16)->default('new'); // new | scheduled | accepted | declined
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('client_type', 16)->default('individual');
            $table->string('case_type', 64);
            $table->text('description');
            $table->json('opposing_parties');
            $table->json('preferred_times');
            $table->timestamp('consent_at');                   // Data Privacy Act consent
            $table->string('ip_address', 45)->nullable();
            $table->json('conflict_check_ids');
            $table->string('conflict_status', 16);             // clear | flagged
            $table->timestamp('consultation_at')->nullable();
            $table->foreignId('assigned_lawyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('internal_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->timestamps();

            $table->index(['firm_id', 'status', 'created_at']);
        });

        RowLevelSecurity::enable('intake_requests');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('intake_requests');
        Schema::dropIfExists('intake_requests');
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn(['slug', 'intake_enabled', 'intake_message']));
    }
};
