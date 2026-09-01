<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('pipeline:tick')->everyMinute();

// Expired prototypes are throwaway; drop them and their generated HTML daily.
Schedule::call(function () {
    \App\Models\Prototype::where('expires_at', '<', now())->whereNull('project_id')->delete();
})->dailyAt('03:30');
