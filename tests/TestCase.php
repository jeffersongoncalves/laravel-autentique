<?php

namespace JeffersonGoncalves\Autentique\Tests;

use JeffersonGoncalves\Autentique\AutentiqueServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AutentiqueServiceProvider::class,
        ];
    }
}
