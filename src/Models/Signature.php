<?php

namespace JeffersonGoncalves\Autentique\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $document_id
 * @property string $public_id
 * @property string|null $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $action
 * @property string|null $link
 * @property Carbon|null $viewed_at
 * @property Carbon|null $signed_at
 * @property Carbon|null $rejected_at
 * @property array<string, mixed>|null $payload
 */
class Signature extends Model
{
    protected $table = 'autentique_signatures';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'signed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * Store a signature from the API shape (`action { name }`, `signed { created_at }`)
     * or the webhook shape (`user { ... }`, `action: "Sign"`, `signed: "2025-..."`).
     *
     * @param  array<string, mixed>  $data
     */
    public static function sync(Document $document, array $data): self
    {
        $user = (array) ($data['user'] ?? []);
        $action = $data['action'] ?? null;

        // Only overwrite what this payload actually carries (webhooks have no link, the API may omit user).
        $attributes = array_filter([
            'name' => $data['name'] ?? $user['name'] ?? null,
            'email' => $data['email'] ?? $user['email'] ?? null,
            'phone' => $data['phone'] ?? $user['phone'] ?? null,
            'action' => is_array($action) ? ($action['name'] ?? null) : $action,
            'link' => $data['link']['short_link'] ?? null,
        ], fn ($value) => $value !== null);

        foreach (['viewed', 'signed', 'rejected'] as $event) {
            if (array_key_exists($event, $data)) {
                $attributes["{$event}_at"] = self::timestamp($data[$event]);
            }
        }

        return static::updateOrCreate(
            ['public_id' => (string) $data['public_id']],
            ['document_id' => $document->id, 'payload' => $data, ...$attributes],
        );
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    protected static function timestamp(mixed $value): ?Carbon
    {
        $value = is_array($value) ? ($value['created_at'] ?? null) : $value;

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
