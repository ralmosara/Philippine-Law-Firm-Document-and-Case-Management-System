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
        Schema::create('trust_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trust_account_id')->constrained('trust_accounts')->cascadeOnDelete();
            $table->bigInteger('amount_cents'); // Positive for deposit, negative for withdrawal
            $table->string('transaction_type'); // deposit, withdrawal, fee_payment
            $table->string('reference_number')->nullable();
            $table->bigInteger('balance_after_cents')->nullable(); // Computed by trigger
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('
                CREATE OR REPLACE FUNCTION update_trust_balance()
                RETURNS TRIGGER AS $$
                DECLARE
                    new_balance BIGINT;
                BEGIN
                    -- Lock the trust_account row to prevent concurrent modification issues
                    SELECT current_balance_cents INTO new_balance
                    FROM trust_accounts
                    WHERE id = NEW.trust_account_id
                    FOR UPDATE;
                    
                    new_balance := new_balance + NEW.amount_cents;
                    
                    IF new_balance < 0 THEN
                        RAISE EXCEPTION \'Insufficient trust funds. Attempted to withdraw %, but balance is %\', ABS(NEW.amount_cents), (new_balance - NEW.amount_cents);
                    END IF;
                    
                    NEW.balance_after_cents := new_balance;
                    
                    UPDATE trust_accounts
                    SET current_balance_cents = new_balance,
                        updated_at = NOW()
                    WHERE id = NEW.trust_account_id;
                    
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER trg_update_trust_balance
                BEFORE INSERT ON trust_transactions
                FOR EACH ROW
                EXECUTE FUNCTION update_trust_balance();
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_update_trust_balance ON trust_transactions;');
            DB::unprepared('DROP FUNCTION IF EXISTS update_trust_balance();');
        }
        Schema::dropIfExists('trust_transactions');
    }
};
