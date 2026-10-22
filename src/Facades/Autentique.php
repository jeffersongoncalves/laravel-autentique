<?php

namespace JeffersonGoncalves\Autentique\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \JeffersonGoncalves\Autentique\Autentique
 */
class Autentique extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-autentique';
    }
}
