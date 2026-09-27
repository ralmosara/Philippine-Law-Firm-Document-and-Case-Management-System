<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Page size from ?per_page, clamped so one request cannot pull an entire table. */
    protected function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        return max(1, min($max, $request->integer('per_page', $default)));
    }
}
