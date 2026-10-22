<?php

namespace JeffersonGoncalves\Autentique\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use JeffersonGoncalves\Autentique\Events\WebhookReceived;
use JeffersonGoncalves\Autentique\Jobs\Concerns\UsesConfiguredQueue;
use JeffersonGoncalves\Autentique\Models\Document;
use JeffersonGoncalves\Autentique\Models\Signature;
use JeffersonGoncalves\Autentique\Models\WebhookCall;
use Throwable;

/**
 * Apply a stored webhook to the local documents/signatures, then dispatch WebhookReceived.
 */
class ProcessWebhookCall implements ShouldQueue
{
    use Queueable;
    use UsesConfiguredQueue;

    public int $tries = 3;

    public function __construct(public WebhookCall $call)
    {
        $this->useConfiguredQueue();
    }

    public function handle(): void
    {
        $event = new WebhookReceived($this->call->payload);

        try {
            $this->apply((string) $event->type(), $event->object());
        } catch (Throwable $e) {
            $this->call->update(['exception' => (string) $e]);

            throw $e;
        }

        $this->call->update(['processed_at' => now(), 'exception' => null]);

        event($event);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    protected function apply(string $type, array $object): void
    {
        if (str_starts_with($type, 'document.') && isset($object['id'])) {
            $this->applyDocument($type, $object);
        }

        if (str_starts_with($type, 'signature.') && isset($object['public_id'])) {
            $this->applySignature($type, $object);
        }
    }

    /**
     * @param  array<string, mixed>  $object
     */
    protected function applyDocument(string $type, array $object): void
    {
        if ($type === 'document.deleted') {
            Document::query()->where('autentique_id', $object['id'])->first()?->delete();

            return;
        }

        $document = Document::sync($object);

        if ($type === 'document.finished') {
            $document->forceFill([
                'status' => Document::STATUS_SIGNED,
                'finished_at' => $document->finished_at ?? now(),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $object
     */
    protected function applySignature(string $type, array $object): void
    {
        $document = Document::query()->where('autentique_id', (string) ($object['document'] ?? ''))->first();

        // Events arrive out of order: a signature can come before its document.created.
        if ($document === null) {
            if (isset($object['document'])) {
                SyncDocument::dispatch((string) $object['document']);
            }

            return;
        }

        if ($type === 'signature.deleted') {
            Signature::query()->where('public_id', $object['public_id'])->delete();
        } else {
            Signature::sync($document, $object);
        }

        $document->refreshStatus();
    }
}
