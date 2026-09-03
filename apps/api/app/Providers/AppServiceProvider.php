<?php

namespace App\Providers;

use App\Domain\Library\ImageLibrary;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The library needs to know where it lives, and that is the one thing config knows and a
        // constructor-injected service cannot work out for itself.
        $this->app->singleton(
            ImageLibrary::class,
            fn () => new ImageLibrary((string) config('services.media.library_path')),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
