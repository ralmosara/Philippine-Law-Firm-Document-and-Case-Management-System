<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Non-working days that shift reglementary periods. Global rather than per
     * firm: proclamations and Supreme Court court-closure circulars apply
     * nationwide.
     */
    public function up(): void
    {
        Schema::create('holiday_calendar', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->string('type', 32)->default('regular'); // regular | special_non_working | court_closure
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holiday_calendar');
    }
};
