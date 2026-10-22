<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use JeffersonGoncalves\Autentique\Autentique as Client;
use JeffersonGoncalves\Autentique\Exceptions\AutentiqueException;
use JeffersonGoncalves\Autentique\Exceptions\RateLimitException;
use JeffersonGoncalves\Autentique\Facades\Autentique;

beforeEach(fn () => Sleep::fake(syncWithCarbon: true));

it('sends authenticated GraphQL queries and returns the field', function () {
    Http::fake(['*' => Http::response(['data' => ['document' => ['id' => 'abc', 'name' => 'Contract']]])]);

    expect(Autentique::document('abc'))->toBe(['id' => 'abc', 'name' => 'Contract']);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.autentique.com.br/v2/graphql'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && str_contains($request['query'], 'document(id: "abc")'));
});

it('escapes inlined ids as GraphQL string literals', function () {
    Http::fake(['*' => Http::response(['data' => ['deleteDocument' => true]])]);

    expect(Autentique::deleteDocument('a"b'))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request['query'] === 'mutation { deleteDocument(id: "a\"b") }');
});

it('throws with validation details on GraphQL errors', function () {
    Http::fake(['*' => Http::response([
        'errors' => [['message' => 'validation', 'extensions' => ['validation' => ['folder.name' => ['field_required']]]]],
        'data' => ['createFolder' => null],
    ])]);

    try {
        Autentique::createFolder('');
        $this->fail('Expected AutentiqueException.');
    } catch (AutentiqueException $e) {
        expect($e->getMessage())->toBe('validation')
            ->and($e->validation())->toBe(['folder.name' => ['field_required']]);
    }
});

it('uploads documents as multipart and flags sandbox', function () {
    config()->set('autentique.sandbox', true);
    Http::fake(['*' => Http::response(['data' => ['createDocument' => ['id' => 'doc-1']]])]);

    $file = tempnam(sys_get_temp_dir(), 'aut');
    file_put_contents($file, '%PDF-1.4');

    expect(Autentique::createDocument(['name' => 'Contract'], [['email' => 'a@b.com', 'action' => 'SIGN']], $file))
        ->toBe(['id' => 'doc-1']);

    Http::assertSent(function (Request $request) {
        $parts = collect($request->data())->keyBy('name');
        $operations = json_decode($parts['operations']['contents'], true);

        return $request->isMultipart()
            && $parts['file']['contents'] === '%PDF-1.4'
            && str_contains($operations['query'], 'sandbox: true')
            && $operations['variables']['signers'][0]['email'] === 'a@b.com';
    });

    unlink($file);
});

it('waits for a free slot when the local rate limit is full', function () {
    config()->set('autentique.rate_limit.max_attempts', 2);
    Http::fake(['*' => Http::response(['data' => ['deleteFolder' => true]])]);

    $client = app(Client::class);
    $client->deleteFolder('1');
    $client->deleteFolder('2');
    $client->deleteFolder('3');

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(1);
});

it('throws instead of waiting longer than max_wait', function () {
    config()->set('autentique.rate_limit.max_attempts', 1);
    config()->set('autentique.rate_limit.max_wait', 0);
    Http::fake(['*' => Http::response(['data' => ['deleteFolder' => true]])]);

    $client = app(Client::class);
    $client->deleteFolder('1');

    expect(fn () => $client->deleteFolder('2'))->toThrow(RateLimitException::class);
    Http::assertSentCount(1);
});

it('retries a 429 from the API honouring Retry-After', function () {
    Http::fakeSequence()
        ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '5'])
        ->push(['data' => ['deleteFolder' => true]]);

    expect(Autentique::deleteFolder('1'))->toBeTrue();

    Http::assertSentCount(2);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 5);
});

it('throws a RateLimitException when the API keeps answering 429', function () {
    config()->set('autentique.rate_limit.retry_times', 1);
    Http::fake(['*' => Http::response(['message' => 'Too Many Attempts.'], 429)]);

    try {
        Autentique::deleteFolder('1');
        $this->fail('Expected RateLimitException.');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(60);
    }

    Http::assertSentCount(2);
});

it('requires a token', function () {
    config()->set('autentique.token', null);
    Http::fake();

    expect(fn () => Autentique::me())->toThrow(AutentiqueException::class, 'token is not configured');
    Http::assertNothingSent();
});
