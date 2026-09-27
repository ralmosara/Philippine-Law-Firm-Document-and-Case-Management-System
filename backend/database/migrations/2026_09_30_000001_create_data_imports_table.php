<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imports from spreadsheets: previewed first, then committed, and undoable
 * for a while afterwards. The checked rows and the ids created are kept, so
 * the firm can see exactly what an import did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('type', 32);          // clients | matters | deadlines | trust_balances
            $table->string('filename', 255);
            $table->string('status', 16);        // previewed | committed | undone
            $table->json('rows');                // per row: line, values, status, messages
            $table->json('summary');             // counts
            $table->json('created_ids')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->index(['firm_id', 'created_at']);
        });

        RowLevelSecurity::enable('data_imports');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('data_imports');
        Schema::dropIfExists('data_imports');
    }
};
