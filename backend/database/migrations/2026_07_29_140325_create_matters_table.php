<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('responsible_lawyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 32);                // firm-internal, e.g. M-2026-0001
            $table->string('title');                         // e.g. "Santos v. Reyes"
            $table->string('case_type', 64);                 // Civil, Criminal, Labor, ...
            $table->string('case_number', 64)->nullable();   // court docket number once filed
            $table->string('court')->nullable();
            $table->string('court_branch')->nullable();
            $table->string('judge')->nullable();
            $table->string('status', 16)->default('intake');
            $table->text('description')->nullable();
            $table->date('opened_at');
            $table->date('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['firm_id', 'reference']);
            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matters');
    }
};
