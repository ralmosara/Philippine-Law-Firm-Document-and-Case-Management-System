<?php

use App\Support\Tenancy\RowLevelSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Electronic invoicing (BIR EIS): each issued invoice, and each
 * cancellation, as an e-invoice document sent through the firm's chosen
 * provider, with the provider's answer and the deadline to send it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->boolean('einvoicing_enabled')->default(false);
            $table->string('tin_branch_code', 5)->default('00000');
        });

        Schema::create('e_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('kind', 16);                    // invoice | cancellation
            $table->string('status', 16)->default('pending'); // pending | recorded | submitted | accepted | rejected | failed
            $table->string('driver', 32);
            $table->json('payload');
            $table->char('payload_sha256', 64);
            $table->string('provider_reference', 191)->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->date('due_on');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'kind']);
            $table->index(['firm_id', 'status']);
        });

        RowLevelSecurity::enable('e_invoices');
    }

    public function down(): void
    {
        RowLevelSecurity::disable('e_invoices');
        Schema::dropIfExists('e_invoices');
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn(['einvoicing_enabled', 'tin_branch_code']));
    }
};
