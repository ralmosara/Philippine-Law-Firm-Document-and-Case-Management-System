<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only trust ledger. Every row records the balance after it was
     * applied, so the ledger is self-verifying: each balance_after_cents must
     * equal the previous row's balance plus this row's signed amount.
     */
    public function up(): void
    {
        Schema::create('trust_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trust_account_id')->constrained('trust_accounts')->restrictOnDelete();
            $table->string('type', 16); // deposit | disbursement
            $table->unsignedBigInteger('amount_cents');
            $table->bigInteger('balance_after_cents');
            $table->string('reference', 64)->nullable(); // OR number, check number, gateway id
            $table->string('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['trust_account_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // Defence in depth: the service computes balances under a row lock,
            // and the database independently refuses anything that would break
            // the ledger, however the write reaches it.
            DB::statement('ALTER TABLE trust_transactions ADD CONSTRAINT trust_transactions_amount_positive CHECK (amount_cents > 0)');
            DB::statement('ALTER TABLE trust_transactions ADD CONSTRAINT trust_transactions_no_overdraw CHECK (balance_after_cents >= 0)');
            DB::statement("ALTER TABLE trust_transactions ADD CONSTRAINT trust_transactions_type_valid CHECK (type IN ('deposit', 'disbursement'))");

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION trust_transactions_guard() RETURNS trigger AS $$
                DECLARE
                    previous_balance BIGINT;
                    signed_amount BIGINT;
                BEGIN
                    IF TG_OP <> 'INSERT' THEN
                        RAISE EXCEPTION 'trust_transactions is append-only; % is not permitted', TG_OP;
                    END IF;

                    SELECT balance_after_cents INTO previous_balance
                      FROM trust_transactions
                     WHERE trust_account_id = NEW.trust_account_id
                     ORDER BY id DESC
                     LIMIT 1;

                    signed_amount := CASE WHEN NEW.type = 'deposit' THEN NEW.amount_cents ELSE -NEW.amount_cents END;

                    IF NEW.balance_after_cents <> COALESCE(previous_balance, 0) + signed_amount THEN
                        RAISE EXCEPTION 'Trust ledger continuity violation on account %', NEW.trust_account_id;
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trust_transactions_guard
                BEFORE INSERT OR UPDATE OR DELETE ON trust_transactions
                FOR EACH ROW EXECUTE FUNCTION trust_transactions_guard();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trust_transactions_guard ON trust_transactions; DROP FUNCTION IF EXISTS trust_transactions_guard();');
        }

        Schema::dropIfExists('trust_transactions');
    }
};
