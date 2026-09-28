<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => $validated['password'],
        ]);

        return response()->json($this->sessionResponse($user), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'El correo o la contraseña no son correctos.'], 422);
        }

        return response()->json($this->sessionResponse($user));
    }

    private function sessionResponse(User $user): array
    {
        $plainToken = Str::random(80);

        AuthToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
        ]);

        return [
            'token' => $plainToken,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'subscription_status' => $user->subscription_status,
                'trial_ends_at' => optional($user->trial_started_at)->addMinutes(15),
            ],
        ];
    }
}