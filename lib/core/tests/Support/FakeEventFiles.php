<?php

/**
 * A file registry that keeps the bytes instead of moving them.
 *
 * Lets a test assert what the tool declared (filename, MIME, visibility) and
 * what it actually wrote, without depending on ext-fileinfo or a storage disk.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use DateTimeImmutable;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;

final class FakeEventFiles implements FileVisibilityStoreInterface
{
    public ?string $filename = null;

    public ?string $mime = null;

    public ?string $visibility = null;

    public ?string $bytes = null;

    public function storeWithVisibility(
        string $localPath,
        string $filename,
        string $mimeType,
        int $userId,
        string $visibility,
        ?string $tenantId = null,
    ): StoredFile {
        $this->filename   = $filename;
        $this->mime       = $mimeType;
        $this->visibility = $visibility;
        $this->bytes      = (string) file_get_contents($localPath);

        return new StoredFile(
            id: 55,
            filename: $filename,
            mimeType: $mimeType,
            sizeBytes: strlen($this->bytes),
            localPath: '/tmp/' . $filename,
            ownerId: $userId,
            createdAt: new DateTimeImmutable(),
            publicUrl: 'https://example.test/' . $filename,
            metadata: array(),
            visibility: $visibility,
            slug: 'slug',
        );
    }

    public function supportsVisibility(string $visibility): bool
    {
        return in_array(
            $visibility,
            array(self::VISIBILITY_PRIVATE, self::VISIBILITY_PUBLIC),
            true
        );
    }
}
