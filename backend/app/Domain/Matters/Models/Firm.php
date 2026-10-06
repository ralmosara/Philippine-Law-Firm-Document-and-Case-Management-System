<?php

namespace App\Domain\Matters\Models;

use App\Models\User;
use Database\Factories\FirmFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Firm extends Model
{
    /** @use HasFactory<FirmFactory> */
    use HasFactory;

    protected $fillable = ['name', 'tin', 'address', 'email', 'phone', 'vat_registered', 'require_two_factor', 'slug', 'intake_enabled', 'intake_message', 'ai_enabled', 'default_withholding_bps', 'dpo_name', 'dpo_email', 'privacy_notice', 'privacy_notice_fil', 'retention_years', 'payment_reminders_enabled', 'pleading_paper', 'pleading_font', 'pleading_font_size', 'taxpayer_type', 'withholding_atc', 'has_employees', 'einvoicing_enabled', 'tin_branch_code', 'client_hearing_reminders', 'statements_enabled', 'statement_day', 'time_reminders_enabled', 'daily_target_minutes'];

    /** The database defaults, so a firm just created in code behaves the same as one loaded. */
    protected $attributes = [
        'taxpayer_type' => 'juridical',
        'withholding_atc' => 'WC010',
        'has_employees' => true,
        'einvoicing_enabled' => false,
        'tin_branch_code' => '00000',
        'client_hearing_reminders' => false,
        'statements_enabled' => false,
        'statement_day' => 1,
        'time_reminders_enabled' => false,
        'daily_target_minutes' => 360,
    ];

    protected function casts(): array
    {
        return ['filing_fee_schedule' => 'array', 'filing_fee_schedule_confirmed_at' => 'datetime', 'statements_enabled' => 'boolean', 'statement_day' => 'integer', 'time_reminders_enabled' => 'boolean', 'daily_target_minutes' => 'integer', 'client_hearing_reminders' => 'boolean', 'einvoicing_enabled' => 'boolean', 'has_employees' => 'boolean', 'pleading_font_size' => 'integer', 'payment_reminders_enabled' => 'boolean', 'privacy_notice_version' => 'integer', 'retention_years' => 'integer', 'privacy_notice_updated_at' => 'datetime', 'default_withholding_bps' => 'integer', 'vat_registered' => 'boolean', 'require_two_factor' => 'boolean', 'intake_enabled' => 'boolean', 'ai_enabled' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    protected static function newFactory(): FirmFactory
    {
        return FirmFactory::new();
    }
}
