<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence in a case: each exhibit as marked at pre-trial or trial, for
 * either side, with what it proves, who identifies it, and how the court
 * ruled on it once offered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exhibits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->string('side', 8);                  // ours | adverse
            $table->string('marking', 24);              // "A", "A-1", "1", "1-a"
            $table->string('description', 1000);
            $table->text('purpose')->nullable();        // what it is offered to prove
            $table->string('witness')->nullable();      // who identifies or authenticates it
            $table->foreignId('matter_file_id')->nullable()->constrained('matter_files')->nullOnDelete();
            $table->date('marked_on')->nullable();
            $table->string('status', 16)->default('marked'); // marked | offered | admitted | denied | withdrawn
            $table->text('objection')->nullable();
            $table->text('ruling')->nullable();
            $table->date('ruled_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['matter_id', 'side', 'marking']);
            $table->index(['firm_id', 'matter_id']);
        });

        RowLevelSecurity::enable('exhibits');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('exhibits');
        Schema::dropIfExists('exhibits');
    }
};
