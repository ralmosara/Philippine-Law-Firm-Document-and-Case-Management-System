<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('minutes');
            $table->unsignedBigInteger('rate_cents');   // hourly rate at time of entry
            $table->unsignedBigInteger('amount_cents'); // round(minutes * rate / 60), fixed at entry
            $table->text('description');
            $table->boolean('is_billable')->default(true);
            $table->unsignedBigInteger('invoice_id')->nullable(); // FK added with invoices table
            $table->timestamps();
            $table->softDeletes();

            $table->index(['firm_id', 'matter_id', 'invoice_id']);
            $table->index(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
