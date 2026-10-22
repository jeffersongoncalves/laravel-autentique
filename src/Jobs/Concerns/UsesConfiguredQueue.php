<?php

namespace JeffersonGoncalves\Autentique\Jobs\Concerns;

trait UsesConfiguredQueue
{
    protected function useConfiguredQueue(): void
    {
        $this->onConnection(config('autentique.queue.connection'));
        $this->onQueue(config('autentique.queue.name'));
    }
}
