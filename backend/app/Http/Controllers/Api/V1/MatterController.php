<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MatterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $matters = DB::table('matters')
            ->join('clients', 'matters.client_id', '=', 'clients.id')
            ->select('matters.*', 'clients.name as client_name')
            ->get();
        return response()->json($matters);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'firm_id' => 'required|integer',
            'client_id' => 'required|integer',
            'case_number' => 'nullable|string|max:255',
            'case_type' => 'nullable|string|max:255',
            'status' => 'required|string',
        ]);
        
        $matterId = DB::table('matters')->insertGetId(array_merge($validated, [
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        
        return response()->json(DB::table('matters')->find($matterId), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $matter = DB::table('matters')->find($id);
        if (!$matter) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json($matter);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'case_number' => 'sometimes|nullable|string|max:255',
            'status' => 'sometimes|required|string',
        ]);
        
        DB::table('matters')->where('id', $id)->update(array_merge($validated, [
            'updated_at' => now(),
        ]));
        
        return response()->json(DB::table('matters')->find($id));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        DB::table('matters')->where('id', $id)->delete();
        return response()->json(null, 204);
    }
}
