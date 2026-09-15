<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Models\AnalyticsEvent;
use App\Models\Prototype;
use Illuminate\Support\Facades\Schedule;

Schedule::command('pipeline:tick')->everyMinute();

// Analytics are for trends, not records: thirteen months, then gone.
Schedule::call(fn () => AnalyticsEvent::where('created_at', '<', now()->subMonths(13))->delete())->dailyAt('03:40');

// One short call a day: the change chat assistant must still see screenshots.
Schedule::command('factory:vision-check')->dailyAt('07:10');

// Expired prototypes are throwaway; drop them and their generated HTML daily.
Schedule::call(function () {
    Prototype::where('expires_at', '<', now())->whereNull('project_id')->delete();
})->dailyAt('03:30');
