<?php

/**
 * A file store for the F&B report tests.
 *
 * Records the filename, MIME type and bytes it was handed, so a test can assert
 * what the CSV exporter actually wrote rather than only that it wrote something.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;

final class FnbRecordingFileStore implements FileVisibilityStoreInterface
{
    public string $bytes = '';

    public string $filename = '';

    public string $mimeType = '';

    public function storeWithVisibility(
        string $localPath,
        string $filename,
        string $mimeType,
        int $userId,
        string $visibility,
        ?string $tenantId = null,
    ): StoredFile {
        $this->bytes    = (string) file_get_contents($localPath);
        $this->filename = $filename;
        $this->mimeType = $mimeType;

        return new StoredFile(
            77,
            $filename,
            $mimeType,
            strlen($this->bytes),
            $localPath,
            $userId,
            new \DateTimeImmutable(),
            'https://example.test/' . $filename,
            array(),
            $visibility,
            pathinfo($filename, PATHINFO_FILENAME)
        );
    }

    public function supportsVisibility(string $visibility): bool
    {
        return true;
    }
}
