<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LearningProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $progress = LearningProgress::where('client_id', $request->query('client_id'))->first();

        if (! $progress) {
            return response()->json(['message' => 'Progress not found'], 404);
        }

        return response()->json($progress);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'string', 'max:100'],
            'age_group' => ['required', 'string', 'max:50'],
            'stars' => ['required', 'integer', 'min:0'],
            'completed_exercises' => ['required', 'array'],
            'current_subject' => ['required', 'string', 'max:50'],
            'current_index_in_subject' => ['required', 'integer', 'min:0'],
            'owned_characters' => ['sometimes', 'array'],
            'selected_character' => ['sometimes', 'string', 'max:50'],
        ]);

        $plan = $request->user()->subscription_plan ?: 'basic';
        $limits = config("subscriptions.plans.{$plan}", config('subscriptions.plans.basic'));
        $completedBySubject = [];
        foreach ($validated['completed_exercises'] as $exerciseId => $completed) {
            if (! $completed) {
                continue;
            }

            $subject = match (substr((string) $exerciseId, 0, 1)) {
                'm' => 'math',
                'e' => 'english',
                'g' => 'geo',
                default => null,
            };
            if (! $subject || ! in_array($subject, $limits['subjects'], true)) {
                return response()->json([
                    'message' => 'Este ejercicio no está incluido en tu plan.',
                    'code' => 'plan_exercise_locked',
                ], 403);
            }
            $completedBySubject[$subject] = ($completedBySubject[$subject] ?? 0) + 1;
        }

        foreach ($completedBySubject as $subject => $count) {
            if ($count > $limits['max_exercises_per_subject']) {
                return response()->json([
                    'message' => 'Has alcanzado el límite de ejercicios de tu plan.',
                    'code' => 'plan_exercise_limit',
                ], 403);
            }
        }

        if (! in_array($validated['current_subject'], $limits['subjects'], true)
            || $validated['current_index_in_subject'] >= $limits['max_exercises_per_subject']) {
            return response()->json([
                'message' => 'Esta materia o ejercicio no está incluido en tu plan.',
                'code' => 'plan_exercise_locked',
            ], 403);
        }

        $progress = LearningProgress::updateOrCreate(
            ['client_id' => $validated['client_id']],
            $validated,
        );

        return response()->json($progress);
    }
}