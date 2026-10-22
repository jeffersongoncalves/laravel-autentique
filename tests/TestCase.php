<?php

namespace JeffersonGoncalves\Autentique\Tests;

use JeffersonGoncalves\Autentique\AutentiqueServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AutentiqueServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('autentique.token', 'test-token');
        $app['config']->set('autentique.webhook.secret', 'webhook-secret');
    }
}
