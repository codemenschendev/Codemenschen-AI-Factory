<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\InternalRunController;
use App\Http\Controllers\AdsController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\PreviewController;
use App\Http\Controllers\PrototypeController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteRefineController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Public prompt-to-prototype (lead magnet): no auth. Throttle on top of the per-IP daily cap.
Route::post('/prototypes', [PrototypeController::class, 'store'])->middleware('throttle:8,60');
Route::get('/prototypes/{prototype}', [PrototypeController::class, 'show']);
Route::get('/prototypes/{prototype}/raw', [PrototypeController::class, 'raw']);

Route::post('/quotes', [QuoteController::class, 'store']);
// Wizard "sharpen my idea": OpenClaw via the worker; daily caps live in the controller.
Route::post('/quotes/refine', QuoteRefineController::class)->middleware('throttle:5,1');
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
    Route::post('/me/projects/{project}/change-requests', [MeController::class, 'requestChanges']);
    Route::post('/me/projects/{project}/change-requests/refine', [MeController::class, 'refineChangeRequest'])->middleware('throttle:5,1');
    Route::post('/me/projects/{project}/care/checkout', [MeController::class, 'startCare']);
    Route::post('/me/projects/{project}/care/cancel', [MeController::class, 'cancelCare']);
    Route::post('/me/projects/{project}/publishing/start', [MeController::class, 'startPublishing']);
    Route::post('/me/projects/{project}/publishing/account', [MeController::class, 'attachStoreAccount']);
    Route::post('/me/projects/{project}/marketing/generate', [MeController::class, 'generateMarketing']);
    Route::post('/me/projects/{project}/campaigns/{campaignId}/decide', [MeController::class, 'decideCampaign']);
    Route::get('/me/projects/{project}/builds/{buildId}/download', [MeController::class, 'downloadBuild']);

    // Ad creatives (video or image), scoped to the customer's own projects.
    Route::get('/me/ads', [MediaController::class, 'index']);
    Route::post('/me/projects/{project}/ads', [MediaController::class, 'store'])->middleware('throttle:10,60');
    Route::get('/me/ads/{ad}/download', [MediaController::class, 'download']);

    // Running campaigns on Codemenschen's ad accounts. publish creates them PAUSED; activate is
    // the one action that starts spend and is only ever called by a person.
    Route::get('/me/campaigns', [AdsController::class, 'index']);
    Route::post('/me/campaigns/{campaign}/publish', [AdsController::class, 'publish']);
    Route::post('/me/campaigns/{campaign}/activate', [AdsController::class, 'activate']);
    Route::post('/me/campaigns/{campaign}/pause', [AdsController::class, 'pause']);
});

// Static web preview of a built app (release stage export); unguessable URL, no login.
Route::get('/preview/{project}/{path?}', PreviewController::class)->where('path', '.*');

// Worker callbacks — authenticated by the per-run callback token.
Route::post('/internal/runs/{run}/heartbeat', [InternalRunController::class, 'heartbeat']);
Route::post('/internal/runs/{run}/complete', [InternalRunController::class, 'complete']);

Route::get('/health', fn () => response()->json(['ok' => true]));
