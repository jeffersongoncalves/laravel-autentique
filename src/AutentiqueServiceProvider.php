<?php

namespace JeffersonGoncalves\Autentique;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AutentiqueServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-autentique')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigrations();
    }
}
