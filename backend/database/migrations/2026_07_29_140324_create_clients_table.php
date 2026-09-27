<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('type', 16)->default('individual'); // individual | corporate
            $table->string('name');
            $table->string('tin', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['firm_id', 'name']);
            $table->unique(['firm_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
