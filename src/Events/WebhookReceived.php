<?php

namespace JeffersonGoncalves\Autentique\Events;

use Illuminate\Foundation\Events\Dispatchable;

class WebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload  full webhook body (`event` holds the Event object)
     */
    public function __construct(public readonly array $payload) {}

    /**
     * Event type, e.g. `document.created`, `signature.accepted`.
     */
    public function type(): ?string
    {
        $type = $this->payload['event']['type'] ?? null;

        return is_string($type) ? $type : null;
    }

    /**
     * The resource of the event (`event.data.object`).
     *
     * @return array<string, mixed>
     */
    public function object(): array
    {
        return (array) ($this->payload['event']['data']['object'] ?? []);
    }

    /**
     * Changed attributes on `*.updated` events.
     *
     * @return array<string, mixed>
     */
    public function previousAttributes(): array
    {
        return (array) ($this->payload['event']['data']['previous_attributes'] ?? []);
    }
}
