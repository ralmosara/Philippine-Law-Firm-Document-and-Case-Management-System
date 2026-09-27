<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('path', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('description', 500)->nullable();
            $table->boolean('shared_with_client')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['firm_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_files');
    }
};
