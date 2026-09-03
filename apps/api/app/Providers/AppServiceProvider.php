<?php

namespace App\Providers;

use App\Domain\Design\DesignRefs;
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

        $this->app->singleton(
            DesignRefs::class,
            fn () => new DesignRefs((string) config('services.media.design_refs_path')),
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
