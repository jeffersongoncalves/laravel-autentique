<?php

namespace JeffersonGoncalves\Autentique\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string, mixed> query(string $query, array<string, mixed> $variables = [])
 * @method static array<string, mixed> me()
 * @method static array<string, mixed> document(string $id)
 * @method static array<string, mixed> documents(int $page = 1, int $limit = 60)
 * @method static array<string, mixed> createDocument(array<string, mixed> $document, array<int, array<string, mixed>> $signers, string|\SplFileInfo $file, ?string $folderId = null, ?int $organizationId = null)
 * @method static array<string, mixed> updateDocument(string $id, array<string, mixed> $document)
 * @method static bool deleteDocument(string $id)
 * @method static bool signDocument(string $id, ?int $organizationId = null)
 * @method static bool moveDocumentToFolder(string $documentId, ?string $folderId, ?string $currentFolderId = null)
 * @method static array<string, mixed> createSigner(string $documentId, array<string, mixed> $signer)
 * @method static bool deleteSigner(string $documentId, string $publicId)
 * @method static bool resendSignatures(array<int, string> $publicIds)
 * @method static string createLinkToSignature(string $publicId)
 * @method static array<string, mixed> folders(int $page = 1, int $limit = 60)
 * @method static array<string, mixed> folder(string $id)
 * @method static array<string, mixed> createFolder(string $name, ?string $parentId = null)
 * @method static bool deleteFolder(string $id)
 * @method static array<string, mixed> documentsByFolder(string $folderId, int $page = 1, int $limit = 60)
 *
 * @see \JeffersonGoncalves\Autentique\Autentique
 */
class Autentique extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \JeffersonGoncalves\Autentique\Autentique::class;
    }
}
