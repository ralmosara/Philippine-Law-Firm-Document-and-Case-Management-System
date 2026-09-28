<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash advances for case costs (filing and sheriff's fees, TSN, ...):
 * requested by the lawyer, approved and released by a partner, then
 * liquidated with receipts, which become the matter's expenses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursement_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('category', 24);
            $table->string('description', 500);
            $table->bigInteger('amount_cents');
            $table->date('needed_by')->nullable();
            $table->string('source', 8)->default('firm');      // firm | trust
            $table->foreignId('trust_account_id')->nullable()->constrained('trust_accounts');
            $table->string('status', 12)->default('pending');   // pending | approved | rejected | released | liquidated | cancelled
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users');
            $table->timestamp('released_at')->nullable();
            $table->string('release_reference', 64)->nullable();  // voucher or check no.
            $table->date('liquidation_due_on')->nullable();
            $table->bigInteger('spent_cents')->nullable();
            $table->bigInteger('returned_cents')->nullable();
            $table->foreignId('liquidated_by')->nullable()->constrained('users');
            $table->timestamp('liquidated_at')->nullable();
            $table->string('liquidation_note', 500)->nullable();
            $table->string('last_reminder', 12)->nullable();
            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index(['matter_id']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('disbursement_request_id')->nullable()->constrained('disbursement_requests')->nullOnDelete();
        });

        RowLevelSecurity::enable('disbursement_requests');
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disbursement_request_id');
        });
        RowLevelSecurity::disable('disbursement_requests');
        Schema::dropIfExists('disbursement_requests');
    }
};
