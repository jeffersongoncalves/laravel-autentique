<?php

namespace JeffersonGoncalves\Autentique;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Sleep;
use JeffersonGoncalves\Autentique\Exceptions\AutentiqueException;
use JeffersonGoncalves\Autentique\Exceptions\RateLimitException;
use SplFileInfo;
use Throwable;

class Autentique
{
    public const DOCUMENT_FIELDS = 'id name refusable sortable sandbox created_at files { original signed pades } signatures { public_id name email created_at action { name } link { short_link } user { id name email } viewed { created_at } signed { created_at } rejected { created_at } }';

    public const SIGNER_FIELDS = 'public_id name email delivery_method created_at action { name } link { id short_link }';

    public const FOLDER_FIELDS = 'id name type path created_at';

    /**
     * @param  array<string, mixed>  $config  the `autentique` config array
     */
    public function __construct(
        protected Factory $http,
        protected RateLimiter $limiter,
        protected array $config,
    ) {}

    /**
     * Run any GraphQL query/mutation and return its `data`.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        return $this->send(fn (PendingRequest $request) => $request->post($this->url(), [
            'query' => $query,
            'variables' => (object) $variables,
        ]));
    }

    /** @return array<string, mixed> */
    public function me(): array
    {
        return $this->fetch('me', 'query { me { id name email phone cpf cnpj birthday subscription { has_premium_features documents credits } organization { id uuid name cnpj } } }');
    }

    /** @return array<string, mixed> */
    public function document(string $id): array
    {
        return $this->fetch('document', 'query { document(id: '.$this->literal($id).') { '.self::DOCUMENT_FIELDS.' } }');
    }

    /** @return array<string, mixed> `total` + `data` */
    public function documents(int $page = 1, int $limit = 60): array
    {
        $sandbox = $this->sandbox() ? ', showSandbox: true' : '';

        return $this->fetch('documents', "query { documents(limit: {$limit}, page: {$page}{$sandbox}) { total data { ".self::DOCUMENT_FIELDS.' } } }');
    }

    /**
     * Create a document and send it for signature (multipart upload).
     *
     * @param  array<string, mixed>  $document  DocumentInput, e.g. ['name' => 'Contract']
     * @param  array<int, array<string, mixed>>  $signers  SignerInput list, e.g. [['email' => 'a@b.com', 'action' => 'SIGN']]
     * @param  string|SplFileInfo  $file  local path or (uploaded) file
     * @return array<string, mixed>
     */
    public function createDocument(array $document, array $signers, string|SplFileInfo $file, ?string $folderId = null, ?int $organizationId = null): array
    {
        $arguments = 'document: $document, signers: $signers, file: $file';
        $arguments .= $this->sandbox() ? ', sandbox: true' : '';
        $arguments .= $folderId !== null ? ', folder_id: '.$this->literal($folderId) : '';
        $arguments .= $organizationId !== null ? ", organization_id: {$organizationId}" : '';

        $operations = json_encode([
            'query' => 'mutation CreateDocumentMutation($document: DocumentInput!, $signers: [SignerInput!]!, $file: Upload!) { createDocument('.$arguments.') { '.self::DOCUMENT_FIELDS.' } }',
            'variables' => ['document' => $document, 'signers' => $signers, 'file' => null],
        ], JSON_THROW_ON_ERROR);

        $path = $file instanceof SplFileInfo ? $file->getRealPath() : $file;
        $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename((string) $path);

        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            throw new AutentiqueException("File [{$name}] is not readable.");
        }

        // Read once so retries resend the same bytes (a stream would be consumed by the first attempt).
        $contents = (string) file_get_contents($path);

        $data = $this->send(fn (PendingRequest $request) => $request
            ->attach('file', $contents, $name)
            ->post($this->url(), ['operations' => $operations, 'map' => '{"file": ["variables.file"]}']));

        return (array) ($data['createDocument'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $document  UpdateDocumentInput
     * @return array<string, mixed>
     */
    public function updateDocument(string $id, array $document): array
    {
        return $this->fetch(
            'updateDocument',
            'mutation UpdateDocument($id: UUID!, $document: UpdateDocumentInput!) { updateDocument(id: $id, document: $document) { id name message reminder refusable sortable deadline_at created_at } }',
            ['id' => $id, 'document' => $document],
        );
    }

    public function deleteDocument(string $id): bool
    {
        return $this->mutate('deleteDocument', 'id: '.$this->literal($id));
    }

    public function signDocument(string $id, ?int $organizationId = null): bool
    {
        $organization = $organizationId !== null ? ", organization_id: {$organizationId}" : '';

        return $this->mutate('signDocument', 'id: '.$this->literal($id).$organization);
    }

    /**
     * Pass `$folderId = null` to move the document back to the root.
     */
    public function moveDocumentToFolder(string $documentId, ?string $folderId, ?string $currentFolderId = null): bool
    {
        $current = $currentFolderId !== null ? ', current_folder_id: '.$this->literal($currentFolderId) : '';

        return $this->mutate('moveDocumentToFolder', 'document_id: '.$this->literal($documentId).$current.', folder_id: '.$this->literal($folderId));
    }

    /**
     * @param  array<string, mixed>  $signer  SignerInput, e.g. ['email' => 'a@b.com', 'action' => 'SIGN']
     * @return array<string, mixed>
     */
    public function createSigner(string $documentId, array $signer): array
    {
        return $this->fetch(
            'createSigner',
            'mutation CreateSigner($document_id: UUID!, $signer: SignerInput!) { createSigner(document_id: $document_id, signer: $signer) { '.self::SIGNER_FIELDS.' } }',
            ['document_id' => $documentId, 'signer' => $signer],
        );
    }

    public function deleteSigner(string $documentId, string $publicId): bool
    {
        return $this->mutate('deleteSigner', 'public_id: '.$this->literal($publicId).', document_id: '.$this->literal($documentId));
    }

    /**
     * @param  array<int, string>  $publicIds
     */
    public function resendSignatures(array $publicIds): bool
    {
        return $this->mutate('resendSignatures', 'public_ids: '.$this->literal(array_values($publicIds)));
    }

    public function createLinkToSignature(string $publicId): string
    {
        return (string) ($this->fetch('createLinkToSignature', 'mutation { createLinkToSignature(public_id: '.$this->literal($publicId).') { short_link } }')['short_link'] ?? '');
    }

    /** @return array<string, mixed> `total` + `data` */
    public function folders(int $page = 1, int $limit = 60): array
    {
        return $this->fetch('folders', "query { folders(limit: {$limit}, page: {$page}) { total data { ".self::FOLDER_FIELDS.' } } }');
    }

    /** @return array<string, mixed> */
    public function folder(string $id): array
    {
        return $this->fetch('folder', 'query { folder(id: '.$this->literal($id).') { '.self::FOLDER_FIELDS.' } }');
    }

    /** @return array<string, mixed> */
    public function createFolder(string $name, ?string $parentId = null): array
    {
        return $this->fetch(
            'createFolder',
            'mutation CreateFolder($parentId: UUID, $folder: FolderInput!) { createFolder(parent_id: $parentId, folder: $folder) { '.self::FOLDER_FIELDS.' } }',
            ['parentId' => $parentId, 'folder' => ['name' => $name]],
        );
    }

    public function deleteFolder(string $id): bool
    {
        return $this->mutate('deleteFolder', 'id: '.$this->literal($id));
    }

    /** @return array<string, mixed> `total` + `data` */
    public function documentsByFolder(string $folderId, int $page = 1, int $limit = 60): array
    {
        return $this->fetch('documentsByFolder', 'query { documentsByFolder(folder_id: '.$this->literal($folderId).", limit: {$limit}, page: {$page}) { total data { ".self::DOCUMENT_FIELDS.' } } }');
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    protected function fetch(string $field, string $query, array $variables = []): array
    {
        return (array) ($this->query($query, $variables)[$field] ?? []);
    }

    /**
     * Boolean mutations, e.g. `mutation { deleteDocument(id: "...") }`.
     */
    protected function mutate(string $field, string $arguments): bool
    {
        return (bool) ($this->query("mutation { {$field}({$arguments}) }")[$field] ?? false);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    protected function send(callable $call): array
    {
        $request = $this->request();

        $this->throttle();

        $response = $call($request);

        if ($response->status() === 429) {
            throw RateLimitException::fromTooManyRequests($response);
        }

        if ($response->failed() || $response->json('errors') !== null) {
            throw AutentiqueException::fromResponse($response);
        }

        return (array) $response->json('data', []);
    }

    protected function request(): PendingRequest
    {
        $token = (string) ($this->config['token'] ?? '');

        if ($token === '') {
            throw new AutentiqueException('Autentique token is not configured (AUTENTIQUE_TOKEN).');
        }

        return $this->http
            ->withToken($token)
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->retry(
                (int) ($this->config['rate_limit']['retry_times'] ?? 3) + 1,
                fn (int $attempt, Throwable $e): int => $this->retryDelay($attempt, $e),
                fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), [429, 502, 503, 504], true)),
                throw: false,
            );
    }

    protected function retryDelay(int $attempt, Throwable $e): int
    {
        $retryAfter = $e instanceof RequestException ? (int) $e->response->header('Retry-After') : 0;

        if ($retryAfter > 0) {
            return $retryAfter * 1000;
        }

        return (int) ($this->config['rate_limit']['retry_sleep_ms'] ?? 1000) * $attempt;
    }

    /**
     * Client-side window shared through the cache, so every worker on the
     * same store respects the account-wide 60 requests/minute.
     */
    protected function throttle(): void
    {
        $limit = (array) ($this->config['rate_limit'] ?? []);

        if (! ($limit['enabled'] ?? true)) {
            return;
        }

        $key = 'autentique:'.sha1((string) ($this->config['token'] ?? ''));
        $maxAttempts = (int) ($limit['max_attempts'] ?? 60);
        $decay = (int) ($limit['decay_seconds'] ?? 60);
        $maxWait = (int) ($limit['max_wait'] ?? 60);
        $waited = 0;

        // ponytail: check-then-hit is not atomic across workers; a rare overshoot gets a 429, which the retry absorbs.
        while (! $this->limiter->attempt($key, $maxAttempts, fn () => true, $decay)) {
            $wait = max(1, $this->limiter->availableIn($key));

            if ($waited + $wait > $maxWait) {
                throw RateLimitException::throttled($wait);
            }

            Sleep::sleep($wait);
            $waited += $wait;
        }
    }

    protected function sandbox(): bool
    {
        return (bool) ($this->config['sandbox'] ?? false);
    }

    protected function url(): string
    {
        return (string) ($this->config['url'] ?? 'https://api.autentique.com.br/v2/graphql');
    }

    /**
     * Inline a value as a GraphQL literal (JSON string/list escaping is valid GraphQL).
     */
    protected function literal(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
