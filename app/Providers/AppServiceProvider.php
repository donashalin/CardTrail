<?php

namespace App\Providers;

use App\Auth\IdentityProvider;
use App\Imports\ImportSheet;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(IdentityProvider::class, function (Application $app) {
            $driver = config('cardtrail.identity.driver');
            $class = config("cardtrail.identity.drivers.{$driver}");

            if (! $class) {
                throw new InvalidArgumentException("Unknown identity driver [{$driver}].");
            }

            if ($driver === 'fake' && ! $app->environment('local', 'testing')) {
                throw new RuntimeException('The fake identity driver is only available in local and testing.');
            }

            return $app->make($class);
        });

        $this->app->scoped(ImportSheet::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
