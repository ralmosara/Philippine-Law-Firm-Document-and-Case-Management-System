<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = DB::table('users')->select('id', 'name', 'email', 'created_at')->get();
        return response()->json($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users|max:255',
            'password' => 'required|string|min:8',
        ]);
        
        $userId = DB::table('users')->insertGetId([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        $user = DB::table('users')->select('id', 'name', 'email')->find($userId);
        return response()->json($user, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = DB::table('users')->select('id', 'name', 'email')->find($id);
        if (!$user) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json($user);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255',
        ]);
        
        DB::table('users')->where('id', $id)->update(array_merge($validated, [
            'updated_at' => now(),
        ]));
        
        return response()->json(DB::table('users')->select('id', 'name', 'email')->find($id));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        DB::table('users')->where('id', $id)->delete();
        return response()->json(null, 204);
    }
}
