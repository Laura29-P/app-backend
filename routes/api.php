<?php

use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemoryGameController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\CharacterController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/billing/webhook', [SubscriptionController::class, 'webhook']);
Route::get('/billing/android-return', function (\Illuminate\Http\Request $request) {
    $data = $request->validate([
        'subscription' => ['required', 'in:success,cancelled'],
        'session_id' => ['required_if:subscription,success', 'nullable', 'regex:/^cs_[a-zA-Z0-9_]+$/', 'max:255'],
    ]);
    $url = 'aventurakids://billing/return?'.http_build_query($data);
    return response()->view('android-return', ['url' => $url])
        ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
});

Route::middleware('api.token')->group(function () {
	Route::get('/account/access', [SubscriptionController::class, 'access']);
	Route::post('/billing/checkout', [SubscriptionController::class, 'checkout']);
	Route::post('/billing/confirm', [SubscriptionController::class, 'confirm']);

	Route::middleware('subscription')->group(function () {
		Route::get('/progress', [ProgressController::class, 'show']);
		Route::put('/progress', [ProgressController::class, 'update']);
		Route::post('/mini-games/memory/results', [MemoryGameController::class, 'store']);
		Route::get('/characters', [CharacterController::class, 'show']);
		Route::post('/characters/{character}/purchase', [CharacterController::class, 'purchase']);
		Route::post('/characters/{character}/select', [CharacterController::class, 'select']);
	});
});
