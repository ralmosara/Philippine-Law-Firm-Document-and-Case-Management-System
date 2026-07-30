<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mcle_compliance_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., '8th Compliance Period'
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('required_units')->default(36);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mcle_compliance_periods');
    }
};
