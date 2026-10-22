<?php

namespace JeffersonGoncalves\Autentique\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JeffersonGoncalves\Autentique\Jobs\ProcessWebhookCall;
use JeffersonGoncalves\Autentique\Models\WebhookCall;

class WebhookController
{
    public function __invoke(Request $request): Response
    {
        $secret = (string) config('autentique.webhook.secret');
        $signature = (string) $request->header('X-Autentique-Signature');

        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return response('Invalid signature.', 403);
        }

        $payload = json_decode($request->getContent(), true);
        $eventId = is_array($payload) ? ($payload['event']['id'] ?? null) : null;

        if (! is_string($eventId) || $eventId === '') {
            return response('Invalid payload.', 400);
        }

        // Autentique may deliver an event more than once: the event id makes it idempotent.
        $call = WebhookCall::query()->firstOrCreate(['event_id' => $eventId], [
            'type' => (string) ($payload['event']['type'] ?? ''),
            'payload' => $payload,
        ]);

        // Autentique expects a fast 2xx: the work happens on the queue.
        if ($call->wasRecentlyCreated) {
            ProcessWebhookCall::dispatch($call);
        }

        return response('', 200);
    }
}
