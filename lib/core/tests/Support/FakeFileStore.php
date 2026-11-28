<?php

/**
 * A recording double for the file store.
 *
 * The export tool writes a payload, hands the store a temporary path, and the
 * store is expected to keep the bytes. This double reads the file *while it still
 * exists* — which is what makes the assertion honest: the test inspects exactly
 * what would have been persisted, including the CSV formula guard, rather than
 * trusting the string the tool built.
 *
 * @package Nvoos\Core\Tests
 * @since   2.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use DateTimeImmutable;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use RuntimeException;

final class FakeFileStore implements FileStoreInterface, FileVisibilityStoreInterface
{
    /**
     * The last payload handed to the store.
     */
    public string $payload = '';

    /**
     * The last filename handed to the store.
     */
    public string $filename = '';

    /**
     * The last visibility handed to the store.
     */
    public string $visibility = '';

    /**
     * Whether the store should fail.
     */
    public bool $fail = false;

    public function __construct(
        private readonly bool $supportsPrivate = true,
    ) {
    }

    public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
    {
        return $this->storeWithVisibility(
            $localPath,
            $filename,
            $mimeType,
            $userId,
            self::VISIBILITY_PUBLIC,
        );
    }

    public function storeWithVisibility(
        string $localPath,
        string $filename,
        string $mimeType,
        int $userId,
        string $visibility,
        ?string $tenantId = null,
    ): StoredFile {
        unset($mimeType, $tenantId);

        if ($this->fail) {
            throw new RuntimeException('disk full');
        }

        $this->payload    = (string) file_get_contents($localPath);
        $this->filename   = $filename;
        $this->visibility = $visibility;

        return new StoredFile(
            id: 1,
            filename: $filename,
            mimeType: 'text/csv',
            sizeBytes: strlen($this->payload),
            localPath: '/srv/app/storage/exports/' . $filename,
            ownerId: $userId,
            createdAt: new DateTimeImmutable(),
            publicUrl: self::VISIBILITY_PUBLIC === $visibility ? 'https://example.test/' . $filename : null,
            visibility: $visibility,
        );
    }

    public function getPath(int $fileId): ?string
    {
        unset($fileId);

        return null;
    }

    public function getMetadata(int $fileId): ?StoredFile
    {
        unset($fileId);

        return null;
    }

    public function userCanAccess(int $fileId, int $userId): bool
    {
        unset($fileId, $userId);

        return false;
    }

    public function delete(int $fileId): void
    {
        unset($fileId);
    }

    public function findByMetadata(array $criteria, int $limit = 50): array
    {
        unset($criteria, $limit);

        return array();
    }

    public function supportsVisibility(string $visibility): bool
    {
        return self::VISIBILITY_PUBLIC === $visibility
            || ($this->supportsPrivate && self::VISIBILITY_PRIVATE === $visibility);
    }
}
