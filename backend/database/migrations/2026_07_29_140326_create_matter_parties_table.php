<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->string('role', 32); // see PartyRole enum
            $table->string('name');
            $table->string('counsel_name')->nullable();
            $table->string('contact')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('name'); // conflict-of-interest search
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_parties');
    }
};
