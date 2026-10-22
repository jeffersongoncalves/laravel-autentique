<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use JeffersonGoncalves\Autentique\Autentique;
use JeffersonGoncalves\Autentique\Jobs\CreateDocument;
use JeffersonGoncalves\Autentique\Jobs\SyncDocument;
use JeffersonGoncalves\Autentique\Models\Document;

beforeEach(fn () => Sleep::fake(syncWithCarbon: true));

/**
 * API-shaped document (`action { name }`, `signed { created_at }`).
 *
 * @return array<string, mixed>
 */
function apiDocument(): array
{
    return [
        'id' => 'doc-1',
        'name' => 'Contract',
        'sandbox' => true,
        'files' => ['original' => 'https://files/original.pdf', 'signed' => null],
        'signatures' => [
            ['public_id' => 'sig-1', 'name' => null, 'email' => 'ana@example.com', 'action' => ['name' => 'SIGN'], 'link' => null, 'viewed' => null, 'signed' => null, 'rejected' => null],
            ['public_id' => 'sig-2', 'name' => 'Witness', 'email' => null, 'action' => ['name' => 'SIGN_AS_A_WITNESS'], 'link' => ['short_link' => 'https://assina.ae/x'], 'viewed' => ['created_at' => '2025-01-31 12:00:00'], 'signed' => null, 'rejected' => null],
        ],
    ];
}

it('creates the document from a disk and stores it linked to a model', function () {
    Storage::fake('local');
    Storage::disk('local')->put('contracts/contract.pdf', '%PDF-1.4');
    Http::fake(['*' => Http::response(['data' => ['createDocument' => apiDocument()]])]);

    $user = User::query()->forceCreate(['name' => 'Owner']);

    CreateDocument::dispatch(['name' => 'Contract'], [['email' => 'ana@example.com', 'action' => 'SIGN']], 'contracts/contract.pdf', 'local', $user);

    $document = Document::query()->forModel($user)->firstOrFail();

    expect($document->autentique_id)->toBe('doc-1')
        ->and($document->sandbox)->toBeTrue()
        ->and($document->documentable)->toBeInstanceOf(User::class)
        ->and($document->signatures()->where('public_id', 'sig-2')->first())
        ->action->toBe('SIGN_AS_A_WITNESS')
        ->link->toBe('https://assina.ae/x')
        ->viewed_at->not->toBeNull();

    Http::assertSent(fn (Request $request) => collect($request->data())->firstWhere('name', 'file')['filename'] === 'contract.pdf');
});

it('releases the job when the rate limit is reached', function () {
    config()->set('autentique.rate_limit.max_attempts', 1);
    config()->set('autentique.rate_limit.max_wait', 0);
    Http::fake(['*' => Http::response(['data' => ['document' => apiDocument()]])]);

    (new SyncDocument('doc-1'))->handle(app(Autentique::class));

    $job = (new SyncDocument('doc-1'))->withFakeQueueInteractions();
    $job->handle(app(Autentique::class));

    $job->assertReleased();
    Http::assertSentCount(1);
});

it('syncs a document and keeps fields the payload does not carry', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['data' => ['document' => apiDocument()]])
        ->push(['data' => ['document' => ['id' => 'doc-1', 'signatures' => [['public_id' => 'sig-2', 'signed' => ['created_at' => '2025-02-01 10:00:00']]]]]]),
    ]);

    SyncDocument::dispatchSync('doc-1');
    SyncDocument::dispatchSync('doc-1');

    $document = Document::query()->firstOrFail();
    $witness = $document->signatures()->where('public_id', 'sig-2')->firstOrFail();

    expect($document->name)->toBe('Contract')
        ->and($witness->link)->toBe('https://assina.ae/x')
        ->and($witness->signed_at)->not->toBeNull()
        ->and($document->signed_count)->toBe(1)
        ->and($document->status)->toBe(Document::STATUS_PENDING);
});
