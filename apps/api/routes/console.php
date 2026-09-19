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

// The analytics digest in #appwerk-agents: every Monday morning, and within a minute of "!stats appwerk".
Schedule::command('factory:analytics --post --days=7')->weeklyOn(1, '08:00')->timezone('Europe/Vienna');
Schedule::command('factory:analytics --requests')->everyMinute();

// Analytics are for trends, not records: thirteen months, then gone.
Schedule::call(fn () => AnalyticsEvent::where('created_at', '<', now()->subMonths(13))->delete())->dailyAt('03:40');

// One short call a day: the change chat assistant must still see screenshots.
Schedule::command('factory:vision-check')->dailyAt('07:10');

// Expired prototypes are throwaway; drop them and their generated HTML daily.
Schedule::call(function () {
    // The pictures a visitor uploaded go with the prototype; a query delete fires no model event.
    Prototype::where('expires_at', '<', now())->whereNull('project_id')->pluck('id')->each(function (string $id) {
        \Illuminate\Support\Facades\File::deleteDirectory(\App\Http\Controllers\PrototypeController::uploadDir($id));
    });
    Prototype::where('expires_at', '<', now())->whereNull('project_id')->delete();
})->dailyAt('03:30');

// Ad platforms: read-only credential check, so the admin tile shows what the platform last said.
Schedule::command('factory:ads-check')->dailyAt('07:20');
