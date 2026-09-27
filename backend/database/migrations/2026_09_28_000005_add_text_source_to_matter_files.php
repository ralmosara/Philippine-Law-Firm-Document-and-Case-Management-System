<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matter_files', function (Blueprint $table) {
            // text: read from the file itself; ocr: recognised from page images.
            $table->string('text_source', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('matter_files', fn (Blueprint $table) => $table->dropColumn('text_source'));
    }
};
