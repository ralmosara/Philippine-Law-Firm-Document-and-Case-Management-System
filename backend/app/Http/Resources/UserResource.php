<?php

namespace App\Http\Resources;

use App\Domain\Compliance\Services\CounselCredentials;
use App\Domain\Staff\OutOfOffice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'is_lawyer' => $this->role->isLawyer(),
            'ibp_number' => $this->ibp_number,
            'roll_number' => $this->roll_number,
            'ptr_number' => $this->ptr_number,
            'mcle_compliance_number' => $this->mcle_compliance_number,
            'ptr_date' => $this->ptr_date?->toDateString(),
            'ptr_place' => $this->ptr_place,
            'ibp_date' => $this->ibp_date?->toDateString(),
            'ibp_chapter' => $this->ibp_chapter,
            'ibp_lifetime' => (bool) $this->ibp_lifetime,
            'away_from' => $this->away_from?->toDateString(),
            'away_until' => $this->away_until?->toDateString(),
            'cover_user_id' => $this->cover_user_id,
            'is_away' => OutOfOffice::isAway($this->resource),
            'credential_problems' => $this->role->isLawyer() ? app(CounselCredentials::class)->problems($this->resource) : [],
            'mobile_number' => $this->mobile_number,
            'hourly_rate_cents' => $this->hourly_rate_cents,
            'daily_target_minutes' => $this->daily_target_minutes,
            'is_active' => $this->is_active,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
