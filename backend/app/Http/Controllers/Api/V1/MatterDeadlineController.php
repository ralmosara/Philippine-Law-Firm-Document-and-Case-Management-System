<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MatterDeadlineController extends Controller
{
    /**
     * Display a listing of the resource.
     * This will be consumed by the React Big Calendar component.
     */
    public function index(Request $request)
    {
        // For now, return a dummy list of deadlines for UI building purposes.
        // In a real scenario, this would query `matter_deadlines` joined with `matters`.
        return response()->json([
            'data' => [
                [
                    'id' => 1,
                    'title' => 'File Answer (V. Dela Cruz)',
                    'start' => now()->addDays(2)->format('Y-m-d'),
                    'end' => now()->addDays(2)->format('Y-m-d'),
                    'status' => 'pending',
                    'matter_id' => 101,
                    'is_conflict' => false
                ],
                [
                    'id' => 2,
                    'title' => 'Pre-Trial Brief (A. Santos)',
                    'start' => now()->addDays(5)->format('Y-m-d'),
                    'end' => now()->addDays(5)->format('Y-m-d'),
                    'status' => 'pending',
                    'matter_id' => 102,
                    'is_conflict' => true // Example of conflict detection flag
                ]
            ]
        ]);
    }
