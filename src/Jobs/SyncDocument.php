<?php

namespace JeffersonGoncalves\Autentique\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use JeffersonGoncalves\Autentique\Autentique;
use JeffersonGoncalves\Autentique\Exceptions\RateLimitException;
use JeffersonGoncalves\Autentique\Jobs\Concerns\UsesConfiguredQueue;
use JeffersonGoncalves\Autentique\Models\Document;

/**
 * Fetch a document from the API and upsert it (with its signatures) in the database.
 */
class SyncDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use UsesConfiguredQueue;

    /** Rate-limit releases count as attempts. */
    public int $tries = 10;

    public int $maxExceptions = 3;

    /** Webhook bursts for one document collapse into a single sync. */
    public int $uniqueFor = 60;

    public function __construct(public string $autentiqueId)
    {
        $this->useConfiguredQueue();
    }

    public function uniqueId(): string
    {
        return $this->autentiqueId;
    }

    public function handle(Autentique $autentique): void
    {
        try {
            $data = $autentique->document($this->autentiqueId);
        } catch (RateLimitException $e) {
            $this->release($e->retryAfter);

            return;
        }

        Document::sync($data);
    }
}
