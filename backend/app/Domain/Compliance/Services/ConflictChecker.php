<?php

namespace App\Domain\Compliance\Services;

use Illuminate\Support\Facades\DB;

class ConflictChecker
{
    /**
     * Perform a conflict of interest check against the firm's clients and matter parties.
     * In a production environment with Meilisearch, this would use Laravel Scout:
     * e.g. Client::search($searchName)->get() and MatterParty::search($searchName)->get()
     *
     * @param int $firmId
     * @param string $searchName
     * @return array
     */
    public function check(int $firmId, string $searchName): array
    {
        // For demonstration/testing without Meilisearch running locally,
        // we fallback to a database LIKE query.
        $clients = DB::table('clients')
            ->where('firm_id', $firmId)
            ->where('name', 'LIKE', '%' . $searchName . '%')
            ->select('id', 'name', DB::raw("'Client' as type"))
            ->get();
            
        $matterParties = DB::table('matter_parties')
            ->join('matters', 'matter_parties.matter_id', '=', 'matters.id')
            ->where('matters.firm_id', $firmId)
            ->where('matter_parties.name', 'LIKE', '%' . $searchName . '%')
            ->select('matter_parties.id', 'matter_parties.name', 'matter_parties.role as type')
            ->get();
            
        return [
            'exact_matches' => [], // Would be populated by Scout's exact score
            'potential_matches' => $clients->concat($matterParties)->toArray()
        ];
    }
}
