<div class="filament-hidden">

![Laravel Autentique](https://raw.githubusercontent.com/jeffersongoncalves/laravel-autentique/main/art/jeffersongoncalves-laravel-autentique.png)

</div>

# Laravel Autentique

[![Buy Me A Coffee](https://img.shields.io/badge/Buy%20Me%20A%20Coffee-support-FFDD00?style=flat-square&logo=buy-me-a-coffee&logoColor=black)](https://buymeacoffee.com/jeffersongoncalves)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jeffersongoncalves/laravel-autentique.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-autentique)
[![Tests](https://img.shields.io/github/actions/workflow/status/jeffersongoncalves/laravel-autentique/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/jeffersongoncalves/laravel-autentique/actions/workflows/tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/jeffersongoncalves/laravel-autentique.svg?style=flat-square)](https://packagist.org/packages/jeffersongoncalves/laravel-autentique)
[![License](https://img.shields.io/github/license/jeffersongoncalves/laravel-autentique?style=flat-square)](LICENSE.md)

Laravel client for the [Autentique](https://autentique.com.br) e-signature [GraphQL API](https://docs.autentique.com.br/api): documents, signers, folders, sandbox mode and signed webhooks — **rate-limit aware** out of the box.

Autentique allows **60 requests per minute** per account and answers `429 Too Many Attempts.` above that. This package:

- throttles requests client-side through your cache store, so every worker sharing the store stays under the limit;
- waits for a free slot (up to `max_wait` seconds) instead of failing, or throws a `RateLimitException` you can use to `release()` a queued job;
- retries any `429` (and `502/503/504`/connection errors) that still reaches the API, honouring `Retry-After`.

## Installation

```bash
composer require jeffersongoncalves/laravel-autentique
```

Publish the config (optional):

```bash
php artisan laravel-autentique:install
```

Add your API token (generate it at [painel.autentique.com.br/perfil/api](https://painel.autentique.com.br/perfil/api)):

```dotenv
AUTENTIQUE_TOKEN=your-token
AUTENTIQUE_SANDBOX=false
```

## Usage

```php
use JeffersonGoncalves\Autentique\Facades\Autentique;

// Create a document and send it for signature
$document = Autentique::createDocument(
    document: ['name' => 'Service contract', 'refusable' => true],
    signers: [
        ['email' => 'client@example.com', 'action' => 'SIGN'],
        ['name' => 'Witness', 'action' => 'SIGN_AS_A_WITNESS'], // returns a signature link
    ],
    file: storage_path('app/contract.pdf'), // path, SplFileInfo or UploadedFile
    folderId: null,
);

$document['id'];
$document['signatures'][1]['link']['short_link'];

// Documents
Autentique::document($id);
Autentique::documents(page: 1, limit: 60);
Autentique::updateDocument($id, ['name' => 'New name']);
Autentique::signDocument($id);
Autentique::deleteDocument($id);

// Signers
Autentique::createSigner($documentId, ['email' => 'other@example.com', 'action' => 'SIGN']);
Autentique::deleteSigner($documentId, $publicId);
Autentique::resendSignatures([$publicId]);
Autentique::createLinkToSignature($publicId); // "https://assina.ae/..."

// Folders
$folder = Autentique::createFolder('Signed contracts');
Autentique::folders();
Autentique::folder($folder['id']);
Autentique::moveDocumentToFolder($documentId, $folder['id']);
Autentique::documentsByFolder($folder['id']);
Autentique::deleteFolder($folder['id']);

// Account
Autentique::me();

// Anything else: run raw GraphQL, returns `data`
Autentique::query('query { document(id: "...") { id files { original signed } } }');
```

Each method returns the requested field as an array (`total` + `data` for listings). Default selections live in `Autentique::DOCUMENT_FIELDS`, `SIGNER_FIELDS` and `FOLDER_FIELDS`; use `query()` when you need other fields.

### Sandbox

With `AUTENTIQUE_SANDBOX=true`, documents are created with `sandbox: true` (no credits consumed, removed by Autentique after a few days) and `documents()` includes sandbox documents.

### Errors

```php
use JeffersonGoncalves\Autentique\Exceptions\AutentiqueException;
use JeffersonGoncalves\Autentique\Exceptions\RateLimitException;

try {
    Autentique::createFolder('');
} catch (RateLimitException $e) {
    $e->retryAfter; // seconds
} catch (AutentiqueException $e) {
    $e->getMessage(); // "validation", "document_not_found", ...
    $e->validation(); // ['folder.name' => ['field_required']]
    $e->errors;       // raw GraphQL errors
}
```

### Rate limit in queued jobs

The client already waits for a free slot. For jobs, keep `max_wait` small and release the job instead of blocking the worker:

```php
public function handle(): void
{
    try {
        Autentique::createDocument(...);
    } catch (RateLimitException $e) {
        $this->release($e->retryAfter);
    }
}
```

| Option (`config/autentique.php`) | Env | Default |
|---|---|---|
| `rate_limit.enabled` | `AUTENTIQUE_RATE_LIMIT_ENABLED` | `true` |
| `rate_limit.max_attempts` | `AUTENTIQUE_RATE_LIMIT_MAX_ATTEMPTS` | `60` |
| `rate_limit.decay_seconds` | `AUTENTIQUE_RATE_LIMIT_DECAY_SECONDS` | `60` |
| `rate_limit.max_wait` | `AUTENTIQUE_RATE_LIMIT_MAX_WAIT` | `60` |
| `rate_limit.cache_store` | `AUTENTIQUE_RATE_LIMIT_CACHE_STORE` | default store |
| `rate_limit.retry_times` | `AUTENTIQUE_RETRY_TIMES` | `3` |
| `rate_limit.retry_sleep_ms` | `AUTENTIQUE_RETRY_SLEEP_MS` | `1000` |

Use a shared store (Redis, database) when you run several servers/workers — the `array` or `file` store only limits a single process/host.

## Webhooks

Register the endpoint in the Autentique dashboard and set its secret:

```dotenv
AUTENTIQUE_WEBHOOK_SECRET=your-webhook-secret
AUTENTIQUE_WEBHOOK_PATH=autentique/webhook
```

The `POST /autentique/webhook` route (name `autentique.webhook`) is registered only when a secret is set. It validates the HMAC-SHA256 `X-Autentique-Signature` header (`403` otherwise), answers `200` right away and dispatches `WebhookReceived`. The route has no `web` middleware, so CSRF does not apply.

```php
use Illuminate\Support\Facades\Event;
use JeffersonGoncalves\Autentique\Events\WebhookReceived;

Event::listen(function (WebhookReceived $event) {
    match ($event->type()) {
        'document.finished' => /* ... */ null,
        'signature.accepted' => /* $event->object()['public_id'] */ null,
        default => null,
    };
});
```

Use a queued listener (`ShouldQueue`) for heavy work. `$event->previousAttributes()` holds the changed fields on `*.updated` events, `$event->payload` the full body.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
