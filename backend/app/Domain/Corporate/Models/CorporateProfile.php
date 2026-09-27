<?php

namespace App\Domain\Corporate\Models;

use App\Casts\DateOnly;
use App\Domain\Matters\Models\Client;
use App\Models\Traits\Auditable;
use App\Models\Traits\HasTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client company's registration details, for corporate secretarial work. */
class CorporateProfile extends Model
{
    use Auditable, HasTenantScope;

    protected $fillable = [
        'firm_id', 'client_id', 'sec_registration_no', 'incorporated_on', 'fiscal_year_end', 'annual_meeting_date',
        'principal_office', 'corporate_secretary', 'responsible_lawyer_id', 'notes',
    ];

    protected $attributes = [
        'fiscal_year_end' => '12-31',
    ];

    protected function casts(): array
    {
        return ['incorporated_on' => DateOnly::class, 'client_id' => 'integer', 'responsible_lawyer_id' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function responsibleLawyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_lawyer_id');
    }
}
