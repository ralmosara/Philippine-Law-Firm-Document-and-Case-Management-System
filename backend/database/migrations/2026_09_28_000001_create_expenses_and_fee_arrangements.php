<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            // hourly | flat | retainer | contingency | pro_bono
            $table->string('fee_arrangement', 16)->default('hourly');
            $table->unsignedBigInteger('fixed_fee_cents')->nullable();      // flat fee total, or monthly retainer
            $table->unsignedBigInteger('acceptance_fee_cents')->nullable();
            $table->unsignedBigInteger('appearance_fee_cents')->nullable(); // per hearing attended
            $table->unsignedInteger('contingency_basis_points')->nullable(); // 2500 = 25% of recovery
        });

        // Costs advanced for the client: docket fees, sheriff's fees, TSN, ...
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('expense_date');
            $table->string('category', 24);
            $table->string('description', 500);
            $table->unsignedBigInteger('amount_cents');
            $table->boolean('is_billable')->default(true);
            $table->foreignId('receipt_file_id')->nullable()->constrained('matter_files')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['firm_id', 'matter_id', 'invoice_id']);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->string('kind', 16)->default('time'); // time | expense | fee
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
        });

        // Reimbursable costs advanced for the client are billed at cost and
        // kept outside the VAT base; they are totalled separately.
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('expenses_cents')->default(0);
        });

        RowLevelSecurity::enable('expenses');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('expenses');

        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('expenses_cents'));
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
            $table->dropColumn('kind');
        });
        Schema::dropIfExists('expenses');
        Schema::table('matters', fn (Blueprint $table) => $table->dropColumn([
            'fee_arrangement', 'fixed_fee_cents', 'acceptance_fee_cents', 'appearance_fee_cents', 'contingency_basis_points',
        ]));
    }
};
