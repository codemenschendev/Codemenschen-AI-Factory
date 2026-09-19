<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// A campaign's landing page, live once its owner switched it on (LandingController).
Route::get('/l/{prototype}', [\App\Http\Controllers\LandingController::class, 'page'])->name('landing.page');
