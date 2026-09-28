<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corporate secretarial work for client companies: their registration
 * details and the SEC and BIR obligations that recur every year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();
            $table->string('sec_registration_no', 32)->nullable();
            $table->date('incorporated_on')->nullable();
            $table->string('fiscal_year_end', 5)->default('12-31');       // MM-DD
            $table->string('annual_meeting_date', 5)->nullable();         // MM-DD, per the by-laws
            $table->string('principal_office')->nullable();
            $table->string('corporate_secretary')->nullable();
            $table->foreignId('responsible_lawyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('corporate_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('kind', 24);            // annual_meeting | gis | afs | annual_itr | custom
            $table->string('title');
            $table->unsignedSmallInteger('year');
            $table->date('due_on');
            $table->string('status', 16);          // pending | done | not_applicable
            $table->date('done_on')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('last_reminder', 16)->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'kind', 'year']);
            $table->index(['firm_id', 'status', 'due_on']);
        });

        RowLevelSecurity::enable('corporate_profiles');
        RowLevelSecurity::enable('corporate_obligations');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('corporate_obligations');
        RowLevelSecurity::disable('corporate_profiles');
        Schema::dropIfExists('corporate_obligations');
        Schema::dropIfExists('corporate_profiles');
    }
};
