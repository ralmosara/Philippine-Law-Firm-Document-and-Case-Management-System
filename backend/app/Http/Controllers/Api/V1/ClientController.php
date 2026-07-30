<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Typically filtered by current firm
        $clients = DB::table('clients')->get();
        return response()->json($clients);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'firm_id' => 'required|integer',
        ]);
        
        $clientId = DB::table('clients')->insertGetId(array_merge($validated, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        
        $client = DB::table('clients')->find($clientId);
        return response()->json($client, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $client = DB::table('clients')->find($id);
        if (!$client) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json($client);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255',
        ]);
        
        DB::table('clients')->where('id', $id)->update(array_merge($validated, [
            'updated_at' => now(),
        ]));
        
        return response()->json(DB::table('clients')->find($id));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        DB::table('clients')->where('id', $id)->delete();
        return response()->json(null, 204);
    }
}
