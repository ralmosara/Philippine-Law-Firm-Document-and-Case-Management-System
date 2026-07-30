<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientPortalController extends Controller
{
    /**
     * Get active matters for the authenticated client.
     */
    public function getMatters(Request $request)
    {
        // For testing, assuming client ID is passed or mocked
        $clientId = $request->header('X-Client-Id', 1);
        
        // Ensure RLS context is set (simulated for Postgres)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.current_client_id', ?, true)", [(string)$clientId]);
        }
        
        $matters = DB::table('matters')
            ->where('client_id', $clientId) // In Postgres with RLS, this WHERE is technically redundant but good practice
            ->get();
            
        return response()->json($matters);
    }
    
    /**
     * Get trust balance for a matter.
     */
    public function getTrustBalance(Request $request, $matterId)
    {
        $clientId = $request->header('X-Client-Id', 1);
        
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT set_config('app.current_client_id', ?, true)", [(string)$clientId]);
        }
        
        // RLS will prevent fetching a trust account if the matter doesn't belong to the client
        $trustAccount = DB::table('trust_accounts')
            ->where('matter_id', $matterId)
            ->first();
            
        if (!$trustAccount) {
            return response()->json(['error' => 'Not found or unauthorized'], 404);
        }
        
        return response()->json($trustAccount);
    }
}
