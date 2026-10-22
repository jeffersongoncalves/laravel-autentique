<?php

namespace JeffersonGoncalves\Autentique\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $autentique_id
 * @property string|null $documentable_type
 * @property string|null $documentable_id
 * @property string|null $name
 * @property string $status
 * @property bool $sandbox
 * @property string|null $original_url
 * @property string|null $signed_url
 * @property int $signatures_count
 * @property int $signed_count
 * @property int $rejected_count
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $payload
 */
class Document extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_REJECTED = 'rejected';

    protected $table = 'autentique_documents';

    protected $guarded = [];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'documentable_id' => 'string',
            'sandbox' => 'boolean',
            'signatures_count' => 'integer',
            'signed_count' => 'integer',
            'rejected_count' => 'integer',
            'finished_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * Store a document as returned by the API or a webhook (both shapes), with its signatures.
     *
     * @param  array<string, mixed>  $data
     */
    public static function sync(array $data, ?Model $documentable = null): self
    {
        /** @var self $document */
        $document = static::withTrashed()->firstOrNew(['autentique_id' => (string) $data['id']]);

        if ($documentable !== null) {
            $document->documentable_type = $documentable->getMorphClass();
            $document->documentable_id = (string) $documentable->getKey();
        }

        $document->fill(array_filter([
            'name' => $data['name'] ?? null,
            'original_url' => $data['files']['original'] ?? null,
            'signed_url' => $data['files']['signed'] ?? null,
        ], fn ($value) => $value !== null));

        if (array_key_exists('sandbox', $data)) {
            $document->sandbox = (bool) $data['sandbox'];
        }

        $document->payload = $data;
        $document->save();

        foreach ((array) ($data['signatures'] ?? []) as $signature) {
            Signature::sync($document, (array) $signature);
        }

        return $document->refreshStatus();
    }

    /**
     * Recount the stored signatures and derive the status from them.
     */
    public function refreshStatus(): self
    {
        $signatures = $this->signatures()->get(['signed_at', 'rejected_at']);

        $this->signatures_count = $signatures->count();
        $this->signed_count = $signatures->whereNotNull('signed_at')->count();
        $this->rejected_count = $signatures->whereNotNull('rejected_at')->count();

        $this->status = match (true) {
            $this->rejected_count > 0 => self::STATUS_REJECTED,
            // finished_at is set by document.finished, which Autentique sends when every step is done.
            $this->finished_at !== null,
            $this->signatures_count > 0 && $this->signed_count === $this->signatures_count => self::STATUS_SIGNED,
            default => self::STATUS_PENDING,
        };

        if ($this->status === self::STATUS_SIGNED) {
            $this->finished_at ??= now();
        }

        $this->save();

        return $this;
    }

    /** @return MorphTo<Model, $this> */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<Signature, $this> */
    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForModel(Builder $query, Model $model): void
    {
        $query->where('documentable_type', $model->getMorphClass())->where('documentable_id', (string) $model->getKey());
    }

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }
}
