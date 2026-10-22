<?php

namespace JeffersonGoncalves\Autentique\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\Autentique\Autentique;
use JeffersonGoncalves\Autentique\Exceptions\AutentiqueException;
use JeffersonGoncalves\Autentique\Exceptions\RateLimitException;
use JeffersonGoncalves\Autentique\Jobs\Concerns\UsesConfiguredQueue;
use JeffersonGoncalves\Autentique\Models\Document;

/**
 * Upload a document to Autentique and store it (with its signatures) in the database.
 */
class CreateDocument implements ShouldQueue
{
    use Queueable;
    use UsesConfiguredQueue;

    /** Rate-limit releases count as attempts. */
    public int $tries = 10;

    /** Never retry after an error: the document may already exist at Autentique (and credits be spent). */
    public int $maxExceptions = 1;

    /**
     * @param  array<string, mixed>  $document  DocumentInput
     * @param  array<int, array<string, mixed>>  $signers  SignerInput list
     * @param  string  $path  local path, or a path on `$disk`
     */
    public function __construct(
        public array $document,
        public array $signers,
        public string $path,
        public ?string $disk = null,
        public ?Model $documentable = null,
        public ?string $folderId = null,
    ) {
        $this->useConfiguredQueue();
    }

    public function handle(Autentique $autentique): void
    {
        $file = $this->disk === null ? $this->path : $this->download();

        try {
            $data = $autentique->createDocument($this->document, $this->signers, $file, $this->folderId);
        } catch (RateLimitException $e) {
            $this->release($e->retryAfter);

            return;
        } finally {
            if ($file instanceof UploadedFile) {
                @unlink($file->getPathname());
            }
        }

        Document::sync($data, $this->documentable);
    }

    /**
     * Copy the file from the disk (S3 included) to a local temp file, keeping its name for Autentique.
     */
    protected function download(): UploadedFile
    {
        $contents = Storage::disk($this->disk)->get($this->path);

        if ($contents === null) {
            throw new AutentiqueException("File [{$this->path}] not found on disk [{$this->disk}].");
        }

        $temporary = (string) tempnam(sys_get_temp_dir(), 'autentique');
        file_put_contents($temporary, $contents);

        return new UploadedFile($temporary, basename($this->path), null, null, true);
    }
}
