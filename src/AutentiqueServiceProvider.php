<?php

namespace JeffersonGoncalves\Autentique;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use JeffersonGoncalves\Autentique\Http\Controllers\WebhookController;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AutentiqueServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-autentique')
            ->hasConfigFile()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('jeffersongoncalves/laravel-autentique');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Autentique::class, function ($app): Autentique {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('autentique', []);

            return new Autentique(
                $app->make(HttpFactory::class),
                new RateLimiter($app->make(CacheFactory::class)->store($config['rate_limit']['cache_store'] ?? null)),
                $config,
            );
        });
    }

    public function packageBooted(): void
    {
        $path = config('autentique.webhook.path');

        if (blank(config('autentique.webhook.secret')) || blank($path)) {
            return;
        }

        Route::post((string) $path, WebhookController::class)
            ->middleware((array) config('autentique.webhook.middleware', []))
            ->name('autentique.webhook');
    }
}
