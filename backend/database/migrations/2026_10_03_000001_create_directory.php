<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The firm's directory: courts and branches, and the people it deals with
 * (judges, clerks of court, prosecutors, opposing counsel, experts), linked
 * to the matters they appear in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('level', 24);                 // rtc | metc | mtc | mtcc | mctc | sharia | ca | sc | cta | sandiganbayan | nlrc | agency | other
            $table->string('name');                      // "Regional Trial Court"
            $table->string('branch', 100)->nullable();   // "Branch 143"
            $table->string('station', 150)->nullable();  // "Makati City"
            $table->string('address', 500)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['firm_id', 'name']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('kind', 24);                  // judge | clerk_of_court | prosecutor | opposing_counsel | expert | sheriff | other
            $table->string('name');
            $table->string('title', 50)->nullable();     // "Hon.", "Atty.", "Dr."
            $table->string('organization')->nullable();  // law firm, office, company
            $table->foreignId('court_id')->nullable()->constrained('courts')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('phone', 100)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('roll_number', 30)->nullable(); // for lawyers
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['firm_id', 'kind']);
        });

        Schema::create('matter_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('role', 24);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['matter_id', 'contact_id', 'role']);
        });

        Schema::table('matters', function (Blueprint $table) {
            $table->foreignId('court_id')->nullable()->constrained('courts')->nullOnDelete();
        });

        foreach (['courts', 'contacts', 'matter_contacts'] as $table) {
            RowLevelSecurity::enable($table);
        }
    }

    public function down(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('court_id');
        });
        foreach (['matter_contacts', 'contacts', 'courts'] as $table) {
            RowLevelSecurity::disable($table);
            Schema::dropIfExists($table);
        }
    }
};
