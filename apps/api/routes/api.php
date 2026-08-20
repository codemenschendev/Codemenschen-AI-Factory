<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/quotes', [QuoteController::class, 'store']);
Route::get('/quotes/{quote}', [QuoteController::class, 'show']);
Route::post('/checkout', [CheckoutController::class, 'store']);
Route::post('/webhooks/stripe', StripeWebhookController::class);

Route::post('/auth/magic-link', [AuthController::class, 'magicLink'])
    ->middleware('throttle:5,1');
Route::get('/auth/verify/{customer}', [AuthController::class, 'verify'])
    ->name('auth.verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me/projects', [MeController::class, 'projects']);
    Route::get('/me/projects/{project}', [MeController::class, 'project']);
    Route::post('/me/projects/{project}/approve-review', [MeController::class, 'approveReview']);
    Route::post('/me/projects/{project}/publishing/start', [MeController::class, 'startPublishing']);
    Route::post('/me/projects/{project}/publishing/account', [MeController::class, 'attachStoreAccount']);
    Route::post('/me/projects/{project}/marketing/generate', [MeController::class, 'generateMarketing']);
    Route::post('/me/projects/{project}/campaigns/{campaignId}/decide', [MeController::class, 'decideCampaign']);
    Route::get('/me/projects/{project}/builds/{buildId}/download', [MeController::class, 'downloadBuild']);
});

// Worker callbacks — authenticated by the per-run callback token.
Route::post('/internal/runs/{run}/heartbeat', [App\Http\Controllers\InternalRunController::class, 'heartbeat']);
Route::post('/internal/runs/{run}/complete', [App\Http\Controllers\InternalRunController::class, 'complete']);

Route::get('/health', fn () => response()->json(['ok' => true]));
