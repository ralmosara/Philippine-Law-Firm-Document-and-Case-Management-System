<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What pleading assembly needs: which side the client is on (for the
 * caption), the counsel details Rule 7, Sec. 3 requires under a signature,
 * and the firm's page format for Word export.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            // plaintiff | defendant | petitioner | respondent | complainant | accused | appellant | appellee
            $table->string('client_role', 16)->default('plaintiff');
            $table->string('nature_of_action')->nullable();   // "For: Sum of Money and Damages"
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('ptr_number', 100)->nullable();              // "1234567, 01/05/2026, Makati City"
            $table->string('mcle_compliance_number', 100)->nullable();  // "VIII-0012345, valid until 04/14/2028"
        });

        Schema::table('firms', function (Blueprint $table) {
            // A.M. No. 11-9-4-SC (Efficient Use of Paper Rule) by default.
            $table->string('pleading_paper', 8)->default('folio');     // folio (8.5 x 13 in) | a4 | letter
            $table->string('pleading_font', 64)->default('Times New Roman');
            $table->unsignedTinyInteger('pleading_font_size')->default(14);
        });
    }

    public function down(): void
    {
        Schema::table('firms', fn (Blueprint $table) => $table->dropColumn(['pleading_paper', 'pleading_font', 'pleading_font_size']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['ptr_number', 'mcle_compliance_number']));
        Schema::table('matters', fn (Blueprint $table) => $table->dropColumn(['client_role', 'nature_of_action']));
    }
};
