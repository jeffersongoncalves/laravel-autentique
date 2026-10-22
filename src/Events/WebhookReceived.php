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
     * The resource of the event: `event.data.object`, or `event.data` itself
     * (Autentique sends both shapes; in the second `object` is the string "document"/"signature").
     *
     * @return array<string, mixed>
     */
    public function object(): array
    {
        $data = (array) ($this->payload['event']['data'] ?? []);

        return is_array($data['object'] ?? null) ? $data['object'] : $data;
    }

    /**
     * Changed attributes on `*.updated` events.
     *
     * @return array<string, mixed>
     */
    public function previousAttributes(): array
    {
        return (array) ($this->payload['event']['data']['previous_attributes'] ?? $this->payload['event']['previous_attributes'] ?? []);
    }
}
