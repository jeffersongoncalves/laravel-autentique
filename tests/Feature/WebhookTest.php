<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use JeffersonGoncalves\Autentique\Events\WebhookReceived;

function autentiqueWebhook(string $body, ?string $signature): TestResponse
{
    return test()->call('POST', '/autentique/webhook', [], [], [], array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_AUTENTIQUE_SIGNATURE' => $signature,
    ]), $body);
}

it('dispatches the event for a signed payload', function () {
    Event::fake([WebhookReceived::class]);

    $body = json_encode(['event' => ['type' => 'signature.accepted', 'data' => ['object' => ['public_id' => 'p1']]]]);

    autentiqueWebhook($body, hash_hmac('sha256', $body, 'webhook-secret'))->assertOk();

    Event::assertDispatched(WebhookReceived::class, fn (WebhookReceived $event) => $event->type() === 'signature.accepted'
        && $event->object() === ['public_id' => 'p1']);
});

it('rejects invalid or missing signatures', function (?string $signature) {
    Event::fake([WebhookReceived::class]);

    autentiqueWebhook('{"event":{}}', $signature)->assertForbidden();

    Event::assertNotDispatched(WebhookReceived::class);
})->with(['wrong' => 'deadbeef', 'missing' => null]);
