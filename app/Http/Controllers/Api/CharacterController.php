<?php

namespace App\Http\Controllers\Api;

use App\Models\LearningProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CharacterController
{
    private const CHARACTERS = [
        'luna' => 0,
        'niko' => 5,
        'sol' => 10,
        'mara' => 15,
    ];

    public function show(Request $request): JsonResponse
    {
        $progress = $this->progress($request);
        return response()->json([
            'stars' => $progress->stars,
            'owned_characters' => $progress->owned_characters ?: ['luna'],
            'selected_character' => $progress->selected_character ?: 'luna',
        ]);
    }

    public function purchase(Request $request, string $character): JsonResponse
    {
        if (! array_key_exists($character, self::CHARACTERS)) {
            return response()->json(['message' => 'Personaje no disponible.'], 404);
        }

        $progress = $this->progress($request);
        $owned = $progress->owned_characters ?: ['luna'];
        if (in_array($character, $owned, true)) {
            return $this->select($request, $character);
        }
        $price = self::CHARACTERS[$character];
        if ($progress->stars < $price) {
            return response()->json(['message' => "Necesitas {$price} estrellas para comprar este personaje."], 422);
        }

        $progress->stars -= $price;
        $progress->owned_characters = [...$owned, $character];
        $progress->selected_character = $character;
        $progress->save();

        return $this->payload($progress);
    }

    public function select(Request $request, string $character): JsonResponse
    {
        $progress = $this->progress($request);
        $owned = $progress->owned_characters ?: ['luna'];
        if (! in_array($character, $owned, true)) {
            return response()->json(['message' => 'Primero compra este personaje con estrellas.'], 422);
        }
        $progress->selected_character = $character;
        $progress->save();
        return $this->payload($progress);
    }

    private function progress(Request $request): LearningProgress
    {
        $clientId = $request->validate(['client_id' => ['required', 'string', 'max:100']])['client_id'];
        return LearningProgress::firstOrCreate(
            ['client_id' => $clientId],
            [
                'age_group' => 'peques',
                'stars' => 0,
                'completed_exercises' => [],
                'current_subject' => 'math',
                'current_index_in_subject' => 0,
                'owned_characters' => ['luna'],
                'selected_character' => 'luna',
            ],
        );
    }

    private function payload(LearningProgress $progress): JsonResponse
    {
        return response()->json([
            'stars' => $progress->stars,
            'owned_characters' => $progress->owned_characters ?: ['luna'],
            'selected_character' => $progress->selected_character ?: 'luna',
        ]);
    }
}
