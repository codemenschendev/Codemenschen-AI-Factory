<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DesignLibraryController;
use App\Http\Controllers\InternalRunController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MediaController;
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

// Operator lane. Same magic-link login as a customer; the `admin` middleware is the whole
// difference, and it is checked on the server for every single one of these routes.
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/overview', [AdminController::class, 'overview']);
    Route::get('/projects', [AdminController::class, 'projects']);
    Route::get('/projects/{project}', [AdminController::class, 'project']);
    Route::get('/customers', [AdminController::class, 'customers']);
    Route::get('/ads', [AdminController::class, 'ads']);

    // The rescue actions. Everything here is also possible from artisan; nothing here spends money.
    Route::post('/projects/{project}/stage', [AdminController::class, 'dispatchStage']);
    Route::post('/projects/{project}/status', [AdminController::class, 'setStatus']);
    Route::post('/ads/{ad}/rerender', [AdminController::class, 'rerenderAd']);

    // The photo library. Same catalog ops/library.sh works on from the shell.
    Route::get('/library', [LibraryController::class, 'index']);
    Route::post('/library/state', [LibraryController::class, 'state']);
    Route::post('/library/{id}', [LibraryController::class, 'update']);
    Route::delete('/library/{id}', [LibraryController::class, 'destroy']);

    // The reference library of collected app screens. Read-only: the labelling script owns it.
    Route::get('/design-library', [DesignLibraryController::class, 'index']);
});

// Outside the admin group on purpose: an <img> tag sends no Authorization header, so the
// signature in the URL is what authorises this one. It expires after an hour.
Route::get('/admin/library/{id}/image', [LibraryController::class, 'image'])
    ->middleware('signed')
    ->name('admin.library.image');

Route::get('/admin/design-library/{id}/image', [DesignLibraryController::class, 'image'])
    ->middleware('signed')
    ->name('admin.design-library.image');

// Static web preview of a built app (release stage export); unguessable URL, no login.
Route::get('/preview/{project}/{path?}', PreviewController::class)->where('path', '.*');

// Worker callbacks — authenticated by the per-run callback token.
Route::post('/internal/runs/{run}/heartbeat', [InternalRunController::class, 'heartbeat']);
Route::post('/internal/runs/{run}/complete', [InternalRunController::class, 'complete']);

// The ad canvases. No login: they are published platform specs, and the picker needs them
// before a customer has decided anything.
Route::get('/ad-formats', [MediaController::class, 'formats']);

Route::get('/health', fn () => response()->json(['ok' => true]));
