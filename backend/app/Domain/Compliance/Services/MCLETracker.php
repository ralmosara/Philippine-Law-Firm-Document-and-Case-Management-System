<?php

namespace App\Domain\Compliance\Services;

use Illuminate\Support\Facades\DB;

class MCLETracker
{
    /**
     * Get the total credits for a lawyer in a specific compliance period.
     *
     * @param int $userId
     * @param int $periodId
     * @return array
     */
    public function getComplianceStatus(int $userId, int $periodId): array
    {
        $period = DB::table('mcle_compliance_periods')->find($periodId);
        
        if (!$period) {
            throw new \Exception("Compliance period not found.");
        }
        
        $creditsEarned = DB::table('mcle_credits')
            ->where('user_id', $userId)
            ->where('period_id', $periodId)
            ->sum('units_earned');
            
        return [
            'period_name' => $period->name,
            'required_units' => $period->required_units,
            'earned_units' => $creditsEarned,
            'remaining_units' => max(0, $period->required_units - $creditsEarned),
            'is_compliant' => $creditsEarned >= $period->required_units
        ];
    }
}
