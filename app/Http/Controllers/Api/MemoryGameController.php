<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemoryGameResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoryGameController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:100'],
            'score' => ['required', 'integer', 'min:0', 'max:100'],
            'moves' => ['required', 'integer', 'min:1', 'max:1000'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
        ]);

        $result = MemoryGameResult::create($validated);

        return response()->json($result, 201);
    }
}
