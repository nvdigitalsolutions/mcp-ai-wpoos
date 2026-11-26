<?php

/**
 * The persister records a derivative link, and says so when it cannot.
 *
 * Three behaviours are worth pinning, because each one is a way the inventory
 * could quietly lie: a link that is never written, a link that fails silently, and
 * a host that cannot link at all being nagged on every generated file.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Media;

use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Application\Media\MediaDerivative;
use Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use PHPUnit\Framework\TestCase;

final class GeneratedMediaPersisterDerivativeTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = array();

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = array();

        parent::tearDown();
    }

    public function testAProducedFileIsLinkedToItsSource(): void
    {
        $store = $this->capableStore();

        $payload = (new GeneratedMediaPersister($store))->attachFile(
            array(),
            $this->tempFile(),
            array('user_id' => 1),
            'video-thumbnail',
            FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
            'image/jpeg',
            MediaDerivative::forSource(7, FileDerivativeStoreInterface::ROLE_POSTER),
        );

        $this->assertSame(array(array(42, 7, 'poster')), $store->calls);
        $this->assertArrayHasKey('media', $payload);
        $this->assertArrayNotHasKey('media_relationship_failed', $payload);
    }

    /**
     * The bytes are already stored and already reported, so a link failure must
     * not lose them — but it must be visible, because an unlinked poster is one
     * the inventory will ask for again.
     */
    public function testAFailedLinkKeepsTheFileAndReportsTheReason(): void
    {
        $store = $this->capableStore('the registry is read-only');

        $payload = (new GeneratedMediaPersister($store))->attachFile(
            array(),
            $this->tempFile(),
            array('user_id' => 1),
            'video-thumbnail',
            FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
            'image/jpeg',
            MediaDerivative::forSource(7, FileDerivativeStoreInterface::ROLE_POSTER),
        );

        $this->assertArrayHasKey('media', $payload);
        $this->assertSame(array('the registry is read-only'), $payload['media_relationship_failed']);
        $this->assertArrayNotHasKey('media_failed', $payload);
    }

    /**
     * A host without the capability cannot link anything. Warning on every
     * generated file would be a permanent alert nobody can clear; the tools that
     * need the relationship name the absence instead.
     */
    public function testAHostWithoutTheCapabilityIsNotNagged(): void
    {
        $payload = (new GeneratedMediaPersister($this->plainStore()))->attachFile(
            array(),
            $this->tempFile(),
            array('user_id' => 1),
            'video-thumbnail',
            FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
            'image/jpeg',
            MediaDerivative::forSource(7, FileDerivativeStoreInterface::ROLE_POSTER),
        );

        $this->assertArrayHasKey('media', $payload);
        $this->assertArrayNotHasKey('media_relationship_failed', $payload);
    }

    public function testNoRelationshipMeansNoCall(): void
    {
        $store = $this->capableStore();

        $payload = (new GeneratedMediaPersister($store))->attachFile(
            array(),
            $this->tempFile(),
            array('user_id' => 1),
            'rendition',
            FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
            'image/jpeg',
        );

        $this->assertSame(array(), $store->calls);
        $this->assertArrayHasKey('media', $payload);
        $this->assertArrayNotHasKey('media_relationship_failed', $payload);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * A store that can link, and remembers what it was asked to.
     */
    private function capableStore(?string $failure = null): FileVisibilityStoreInterface
    {
        return new class ($failure) implements FileVisibilityStoreInterface, FileDerivativeStoreInterface {
            /** @var list<array{0: int, 1: int, 2: string}> */
            public array $calls = array();

            public function __construct(private readonly ?string $failure)
            {
            }

            public function storeWithVisibility(
                string $localPath,
                string $filename,
                string $mimeType,
                int $userId,
                string $visibility,
                ?string $tenantId = null,
            ): StoredFile {
                return $this->stored($localPath, $filename, $mimeType, $userId);
            }

            public function supportsVisibility(string $visibility): bool
            {
                return true;
            }

            public function recordDerivative(int $fileId, int $sourceFileId, string $role): StoredFile
            {
                $this->calls[] = array($fileId, $sourceFileId, $role);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                return $this->stored('/tmp/derivative', 'derivative.jpg', 'image/jpeg', 1);
            }

            /**
             * Not exercised here: this file is about the write path.
             *
             * @param list<int> $sourceFileIds
             * @return list<int>
             */
            public function sourceIdsWithDerivative(array $sourceFileIds, string $role): array
            {
                return array();
            }

            private function stored(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                return new StoredFile(
                    id: 42,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: 1,
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable(),
                );
            }
        };
    }

    /**
     * A store that can only store.
     */
    private function plainStore(): FileVisibilityStoreInterface
    {
        return new class implements FileVisibilityStoreInterface {
            public function storeWithVisibility(
                string $localPath,
                string $filename,
                string $mimeType,
                int $userId,
                string $visibility,
                ?string $tenantId = null,
            ): StoredFile {
                return new StoredFile(
                    id: 42,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: 1,
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable(),
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return true;
            }
        };
    }

    private function tempFile(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'nvoos-derivative-');

        file_put_contents($path, 'bytes');

        $this->tempFiles[] = $path;

        return $path;
    }
}
