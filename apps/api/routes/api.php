<?php

use App\Http\Controllers\AdAccountController;
use App\Http\Controllers\AdminClientAdsController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminConversionController;
use App\Http\Controllers\AdminImageKeyController;
use App\Http\Controllers\AdminKeywordController;
use App\Http\Controllers\AdminOwnCampaignController;
use App\Http\Controllers\AdminSecurityController;
use App\Http\Controllers\AdminTrafficController;
use App\Http\Controllers\AdsController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DesignLibraryController;
use App\Http\Controllers\InternalRunController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PreviewController;
use App\Http\Controllers\PrototypeController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteRefineController;
use App\Http\Controllers\SiteConfigController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\ValidationController;
use Illuminate\Support\Facades\Route;

// First-party analytics beacon from the portal (App\Domain\Analytics\Analytics). No cookies.
Route::post('/t', [AnalyticsController::class, 'store'])->middleware('throttle:120,1,t');

// Public prompt-to-prototype (lead magnet): no auth. Throttle on top of the per-IP daily cap.
Route::post('/prototypes', [PrototypeController::class, 'store'])->middleware('throttle:8,60,prototypes');
// The link in the e-mail a visitor gets when they build without being signed in: it signs them
// in and starts the build (owner's decision 2026-09-19).
Route::get('/prototypes/{prototype}/confirm', [PrototypeController::class, 'confirm'])->name('prototypes.confirm')->middleware('throttle:20,1,proto-confirm');
Route::post('/prototypes/questions', [PrototypeController::class, 'questions'])->middleware('throttle:12,60,proto-questions');
Route::get('/prototypes/{prototype}', [PrototypeController::class, 'show']);
Route::get('/prototypes/{prototype}/raw', [PrototypeController::class, 'raw']);
// A campaign's live landing page: the sign-up and the two links in its mails.
Route::post('/landing/{prototype}/signup', [LandingController::class, 'signup'])->middleware('throttle:6,10,landing-signup');
Route::get('/landing/signups/{signup}/confirm', [LandingController::class, 'confirm'])->name('landing.confirm')->middleware('throttle:30,1,landing-link');
Route::get('/landing/signups/{signup}/remove', [LandingController::class, 'remove'])->name('landing.remove')->middleware('throttle:30,1,landing-link');

Route::get('/site-config', [SiteConfigController::class, 'show']);
Route::post('/quotes', [QuoteController::class, 'store']);
// Wizard "sharpen my idea": OpenClaw via the worker; daily caps live in the controller.
Route::post('/quotes/refine', QuoteRefineController::class)->middleware('throttle:5,1,refine');
Route::get('/quotes/{quote}', [QuoteController::class, 'show']);
Route::post('/checkout', [CheckoutController::class, 'store']);
Route::post('/webhooks/stripe', StripeWebhookController::class);

// Every throttle names its own counter (the third argument). Without one, Laravel keys them all
// by IP alone and they share one count: the page-view beacon on /t used up the sign-in's five,
// and a visitor was locked out of signing in for the rest of the prototypes' hour.
Route::post('/auth/magic-link', [AuthController::class, 'magicLink'])
    ->middleware('throttle:5,1,signin');
Route::get('/auth/verify/{customer}', [AuthController::class, 'verify'])
    ->name('auth.verify');
Route::get('/auth/join', [AuthController::class, 'join'])
    ->name('auth.join');

Route::middleware('auth:sanctum')->group(function () {
    // The one free change to a prototype, for a visitor who signed in.
    Route::post('/prototypes/{prototype}/revise', [PrototypeController::class, 'revise'])->middleware('throttle:6,60,revise');
    Route::post('/prototypes/{prototype}/publish', [LandingController::class, 'publish']);
    Route::get('/prototypes/{prototype}/signups', [LandingController::class, 'signups']);
    Route::get('/prototypes/{prototype}/report', [LandingController::class, 'report']);
    Route::get('/me/projects', [MeController::class, 'projects']);
    Route::get('/me/projects/{project}', [MeController::class, 'project']);
    Route::post('/me/projects/{project}/approve-review', [MeController::class, 'approveReview']);
    Route::post('/me/projects/{project}/change-requests', [MeController::class, 'requestChanges']);
    Route::post('/me/projects/{project}/change-requests/refine', [MeController::class, 'refineChangeRequest'])->middleware('throttle:5,1,cr-refine');
    // The change chat (docs/specs/change-chat.md). The assistant's own limits are in ChangeChat;
    // the throttle only stops a stuck client from hammering the endpoint.
    Route::get('/me/projects/{project}/messages', [MeController::class, 'changeMessages']);
    Route::post('/me/projects/{project}/messages', [MeController::class, 'sendChangeMessage'])->middleware('throttle:20,1,messages');
    Route::get('/me/projects/{project}/messages/{message}/images/{n}', [MeController::class, 'changeMessageImage'])->whereNumber('n');
    Route::post('/me/projects/{project}/messages/confirm', [MeController::class, 'confirmChange'])->middleware('throttle:10,1,confirm');
    Route::post('/me/projects/{project}/care/checkout', [MeController::class, 'startCare']);
    Route::post('/me/projects/{project}/care/cancel', [MeController::class, 'cancelCare']);
    Route::post('/me/projects/{project}/publishing/start', [MeController::class, 'startPublishing']);
    Route::post('/me/projects/{project}/publishing/account', [MeController::class, 'attachStoreAccount']);
    Route::post('/me/projects/{project}/marketing/generate', [MeController::class, 'generateMarketing']);
    Route::post('/me/projects/{project}/campaigns/{campaignId}/decide', [MeController::class, 'decideCampaign']);
    Route::get('/me/projects/{project}/builds/{buildId}/download', [MeController::class, 'downloadBuild']);

    // Ad creatives (video or image), scoped to the customer's own projects.
    Route::get('/me/ads', [MediaController::class, 'index']);
    Route::post('/me/projects/{project}/ads', [MediaController::class, 'store'])->middleware('throttle:10,60,media');
    Route::get('/me/ads/{ad}/download', [MediaController::class, 'download']);

    // The customer's own ad accounts. Asking costs nothing and spends nothing; the customer
    // presses accept on the platform and can cut the link there at any time.
    Route::get('/me/ad-accounts', [AdAccountController::class, 'index']);
    Route::post('/me/ad-accounts', [AdAccountController::class, 'store'])->middleware('throttle:10,60,adlink');
    Route::post('/me/ad-accounts/{adAccount}/refresh', [AdAccountController::class, 'refresh'])->middleware('throttle:30,10,adlink');
    Route::delete('/me/ad-accounts/{adAccount}', [AdAccountController::class, 'destroy']);

    // Running campaigns on Codemenschen's ad accounts. publish creates them PAUSED; activate is
    // the one action that starts spend and is only ever called by a person.
    Route::get('/me/campaigns', [AdsController::class, 'index']);
    Route::post('/me/campaigns/{campaign}/publish', [AdsController::class, 'publish']);
    Route::post('/me/campaigns/{campaign}/activate', [AdsController::class, 'activate']);
    Route::post('/me/campaigns/{campaign}/pause', [AdsController::class, 'pause']);
});

// The console's second factor. Open to an admin whose token has not passed it yet, which is the
// point: these are how it gets passed. Written to the audit log like every other console change.
Route::middleware(['auth:sanctum', 'admin', 'audit'])->prefix('admin/2fa')->group(function () {
    Route::get('/', [AdminSecurityController::class, 'status']);
    Route::post('/setup', [AdminSecurityController::class, 'setup'])->middleware('throttle:10,10,2fa-setup');
    Route::post('/enable', [AdminSecurityController::class, 'enable'])->middleware('throttle:5,1,2fa');
    Route::post('/verify', [AdminSecurityController::class, 'verify'])->middleware('throttle:5,1,2fa');
    Route::post('/disable', [AdminSecurityController::class, 'disable'])->middleware('throttle:5,1,2fa');
});

// Operator lane. Same magic-link login as a customer; the `admin` middleware is the whole
// difference, and it is checked on the server for every single one of these routes. Since
// 2026-09-24 an admin who switched on 2FA must also have passed the authenticator code on this
// token (admin.2fa), and every change made here is written to the audit log.
Route::middleware(['auth:sanctum', 'admin', 'admin.2fa', 'audit'])->prefix('admin')->group(function () {
    Route::get('/audit', [AdminSecurityController::class, 'audit']);
    Route::get('/overview', [AdminController::class, 'overview']);
    Route::get('/projects', [AdminController::class, 'projects']);
    Route::get('/projects/{project}', [AdminController::class, 'project']);
    Route::get('/customers', [AdminController::class, 'customers']);
    Route::get('/ads', [AdminController::class, 'ads']);
    // Every prototype a visitor ever asked for, the free lead magnet included: what was typed,
    // what came of it and how long it took. The owner tests through the public box like a
    // visitor and wants to see the result without hunting for the share link.
    Route::get('/prototypes', [AdminController::class, 'prototypes']);

    // The rescue actions. Everything here is also possible from artisan; nothing here spends money.
    Route::post('/projects/{project}/stage', [AdminController::class, 'dispatchStage']);
    Route::post('/projects/{project}/status', [AdminController::class, 'setStatus']);
    Route::get('/analytics', [AdminController::class, 'analytics']);
    Route::post('/payments/mode', [AdminController::class, 'paymentsMode']);
    Route::post('/layouts', [AdminController::class, 'layoutsSettings']);
    Route::post('/ads-mode', [AdminController::class, 'adsMode']);
    // The validation test and the spend guard (ValidationController, SpendGuard).
    Route::get('/prototypes/{prototype}/validation', [ValidationController::class, 'show']);
    Route::post('/prototypes/{prototype}/validation', [ValidationController::class, 'store']);
    Route::post('/marketing/{campaign}/activate', [ValidationController::class, 'activate']);
    Route::post('/marketing/{campaign}/pause', [ValidationController::class, 'pause']);
    Route::post('/ads/kill', [ValidationController::class, 'kill']);
    Route::post('/ads/limits', [ValidationController::class, 'limits']);
    Route::post('/ads/platform', [ValidationController::class, 'platform']);
    // Appwerk's own ad account numbers, editable here instead of in the server env.
    Route::get('/ads/settings', [ValidationController::class, 'settings']);
    Route::post('/ads/settings', [ValidationController::class, 'saveSettings']);
    // The OpenAI key paid renders are billed to. Write-only: nothing reads the key back.
    Route::get('/image-key', [AdminImageKeyController::class, 'show']);
    Route::post('/image-key', [AdminImageKeyController::class, 'store'])->middleware('throttle:10,10,image-key');
    Route::delete('/image-key', [AdminImageKeyController::class, 'destroy']);
    // The words a search campaign is bought for. Only `apply` reaches Google; suggesting and
    // ticking happen here and cost nothing.
    // Every client's ad accounts and campaigns in one place. Starting and pausing stay on the
    // /marketing routes above, behind the spend guard.
    Route::get('/client-ads', [AdminClientAdsController::class, 'index']);
    Route::post('/ad-accounts/{adAccount}/refresh', [AdminClientAdsController::class, 'refreshAccount'])->middleware('throttle:30,10,adlink-admin');
    // Appwerk advertising itself, on our own Google and Meta accounts (AdminOwnCampaignController).
    Route::get('/own-campaigns', [AdminOwnCampaignController::class, 'index']);
    Route::post('/own-campaigns', [AdminOwnCampaignController::class, 'store']);
    Route::post('/own-campaigns/write', [AdminOwnCampaignController::class, 'write'])->middleware('throttle:20,60,adcopy');
    Route::put('/own-campaigns/{campaign}', [AdminOwnCampaignController::class, 'update']);
    Route::delete('/own-campaigns/{campaign}', [AdminOwnCampaignController::class, 'destroy']);
    Route::post('/own-campaigns/{campaign}/publish', [AdminOwnCampaignController::class, 'publish']);
    Route::post('/own-campaigns/{campaign}/image', [AdminOwnCampaignController::class, 'image'])->middleware('throttle:30,10,own-ad-image');
    Route::get('/own-campaigns/{campaign}/image', [AdminOwnCampaignController::class, 'showImage']);
    Route::get('/conversions', [AdminConversionController::class, 'index']);
    Route::post('/conversions/retry', [AdminConversionController::class, 'retry'])->middleware('throttle:10,10,conversions');
    Route::get('/marketing/{campaign}/traffic', [AdminTrafficController::class, 'show'])->middleware('throttle:60,10,traffic');
    Route::get('/keywords', [AdminKeywordController::class, 'campaigns']);
    Route::get('/marketing/{campaign}/keywords', [AdminKeywordController::class, 'index']);
    Route::post('/marketing/{campaign}/keywords/suggest', [AdminKeywordController::class, 'suggest'])->middleware('throttle:20,60,keywords');
    Route::post('/marketing/{campaign}/keywords', [AdminKeywordController::class, 'store']);
    Route::post('/marketing/{campaign}/keywords/apply', [AdminKeywordController::class, 'apply']);
    Route::patch('/keywords/{keyword}', [AdminKeywordController::class, 'update']);
    Route::delete('/keywords/{keyword}', [AdminKeywordController::class, 'destroy']);
    Route::get('/projects/{project}/messages', [AdminController::class, 'changeMessages']);
    Route::post('/projects/{project}/messages', [AdminController::class, 'sendChangeMessage']);
    Route::get('/projects/{project}/messages/{message}/images/{n}', [AdminController::class, 'changeMessageImage'])->whereNumber('n');
    Route::post('/projects/{project}/assistant', [AdminController::class, 'assistant']);
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
