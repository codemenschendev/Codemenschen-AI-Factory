<?php

use App\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Route;

// The API's front page, or a bought website when the request comes in on a customer's domain.
Route::get('/', [LandingController::class, 'byHost']);

// A campaign's landing page, live once its owner switched it on (LandingController).
Route::get('/l/{prototype}', [LandingController::class, 'page'])->name('landing.page');
