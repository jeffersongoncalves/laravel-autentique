<?php

namespace JeffersonGoncalves\Autentique\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JeffersonGoncalves\Autentique\Events\WebhookReceived;

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

        if (! is_array($payload)) {
            return response('Invalid payload.', 400);
        }

        // Autentique expects a fast 2xx: do heavy work in a queued listener.
        WebhookReceived::dispatch($payload);

        return response('', 200);
    }
}
