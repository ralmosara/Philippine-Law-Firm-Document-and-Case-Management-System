<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal password resets are keyed by client, not by email: the same person
 * can be a client of several firms with the same address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_password_reset_tokens', function (Blueprint $table) {
            $table->foreignId('client_id')->primary()->constrained('clients')->cascadeOnDelete();
            $table->string('token', 64); // HMAC-SHA256 of the emailed token
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_password_reset_tokens');
    }
};
