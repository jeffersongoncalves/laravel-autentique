<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use JeffersonGoncalves\Autentique\Events\WebhookReceived;
use JeffersonGoncalves\Autentique\Jobs\ProcessWebhookCall;
use JeffersonGoncalves\Autentique\Jobs\SyncDocument;
use JeffersonGoncalves\Autentique\Models\Document;
use JeffersonGoncalves\Autentique\Models\WebhookCall;

/**
 * @param  array<string, mixed>  $data
 */
function autentiqueWebhook(string $type, array $data, string $eventId = 'evt-1', ?string $signature = null): TestResponse
{
    $body = (string) json_encode(['object' => 'webhook', 'event' => ['id' => $eventId, 'type' => $type, 'data' => $data]]);

    return test()->call('POST', '/autentique/webhook', [], [], [], array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_AUTENTIQUE_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, 'webhook-secret'),
    ]), $body);
}

/**
 * Webhook-shaped document (`event.data` is the object itself).
 *
 * @return array<string, mixed>
 */
function webhookDocument(?string $secondSigned = null): array
{
    return [
        'id' => 'doc-1',
        'object' => 'document',
        'name' => 'Contract',
        'sandbox' => false,
        'files' => ['original' => 'https://files/original.pdf', 'signed' => 'https://files/signed.pdf'],
        'signatures' => [
            ['public_id' => 'sig-1', 'object' => 'signature', 'user' => ['name' => 'Ana', 'email' => 'ana@example.com'], 'document' => 'doc-1', 'action' => 'Sign', 'viewed' => '2025-01-30T12:49:01.000000Z', 'signed' => '2025-01-30T12:50:00.000000Z', 'rejected' => null],
            ['public_id' => 'sig-2', 'object' => 'signature', 'user' => ['name' => 'Bia', 'email' => 'bia@example.com'], 'document' => 'doc-1', 'action' => 'Sign', 'viewed' => null, 'signed' => $secondSigned, 'rejected' => null],
        ],
    ];
}

it('stores the call, syncs the document and dispatches the event', function () {
    Event::fake([WebhookReceived::class]);

    autentiqueWebhook('document.updated', webhookDocument())->assertOk();

    $document = Document::query()->where('autentique_id', 'doc-1')->firstOrFail();

    expect($document->name)->toBe('Contract')
        ->and($document->signed_url)->toBe('https://files/signed.pdf')
        ->and($document->status)->toBe(Document::STATUS_PENDING)
        ->and($document->signatures_count)->toBe(2)
        ->and($document->signed_count)->toBe(1)
        ->and($document->signatures()->where('public_id', 'sig-1')->value('email'))->toBe('ana@example.com')
        ->and(WebhookCall::query()->firstOrFail()->processed_at)->not->toBeNull();

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->type() === 'document.updated'
        && $event->object()['id'] === 'doc-1');
});

it('reads the event.data.object payload shape too', function () {
    autentiqueWebhook('document.created', ['object' => webhookDocument()])->assertOk();

    expect(Document::query()->where('autentique_id', 'doc-1')->exists())->toBeTrue();
});

it('marks the document signed when the last signature is accepted', function () {
    autentiqueWebhook('document.created', webhookDocument(), 'evt-1');

    autentiqueWebhook('signature.accepted', [
        'public_id' => 'sig-2', 'object' => 'signature', 'document' => 'doc-1', 'action' => 'Sign',
        'user' => ['name' => 'Bia', 'email' => 'bia@example.com'], 'viewed' => '2025-01-31T12:22:01.000000Z', 'signed' => '2025-01-31T12:22:30.000000Z', 'rejected' => null,
    ], 'evt-2')->assertOk();

    $document = Document::query()->where('autentique_id', 'doc-1')->firstOrFail();

    expect($document->status)->toBe(Document::STATUS_SIGNED)
        ->and($document->finished_at)->not->toBeNull()
        ->and($document->signatures()->where('public_id', 'sig-2')->first()?->signed_at?->toIso8601String())->toBe('2025-01-31T12:22:30+00:00');
});

it('marks the document rejected', function () {
    autentiqueWebhook('document.created', webhookDocument(), 'evt-1');
    autentiqueWebhook('signature.rejected', ['public_id' => 'sig-2', 'document' => 'doc-1', 'rejected' => '2025-01-31T12:22:30.000000Z'], 'evt-2');

    expect(Document::query()->firstOrFail()->status)->toBe(Document::STATUS_REJECTED);
});

it('finishes and soft deletes documents', function () {
    autentiqueWebhook('document.finished', webhookDocument(), 'evt-1');
    expect(Document::query()->firstOrFail()->status)->toBe(Document::STATUS_SIGNED);

    autentiqueWebhook('document.deleted', ['id' => 'doc-1', 'object' => 'document'], 'evt-2');
    expect(Document::query()->count())->toBe(0)
        ->and(Document::withTrashed()->count())->toBe(1);
});

it('syncs the document from the API when a signature arrives first', function () {
    Queue::fake([SyncDocument::class]);

    autentiqueWebhook('signature.viewed', ['public_id' => 'sig-1', 'document' => 'doc-9', 'viewed' => '2025-01-31T12:22:01.000000Z'])->assertOk();

    Queue::assertPushed(SyncDocument::class, fn (SyncDocument $job) => $job->autentiqueId === 'doc-9');
});

it('processes each event id only once', function () {
    Queue::fake([ProcessWebhookCall::class]);

    autentiqueWebhook('document.updated', webhookDocument(), 'evt-1')->assertOk();
    autentiqueWebhook('document.updated', webhookDocument(), 'evt-1')->assertOk();

    expect(WebhookCall::query()->count())->toBe(1);
    Queue::assertPushed(ProcessWebhookCall::class, 1);
});

it('rejects invalid or missing signatures', function (string $signature) {
    Event::fake([WebhookReceived::class]);

    autentiqueWebhook('document.updated', webhookDocument(), signature: $signature)->assertForbidden();

    expect(WebhookCall::query()->count())->toBe(0);
    Event::assertNotDispatched(WebhookReceived::class);
})->with(['wrong' => 'deadbeef', 'missing' => '']);
