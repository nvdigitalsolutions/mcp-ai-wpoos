<?php

/**
 * The two video asset inventories.
 *
 * Both tools answer one question with a different role: which videos have no
 * poster, and which have no caption file. What is worth pinning is everything
 * around the answer — the role each asks for, the refusals that stop a host
 * pretending, the bound that stops a window being read as a census, and the fact
 * that a registry failure is reported rather than returned as an empty list.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\GetVideosWithoutThumbnailsTool;
use Nvoos\Core\Tool\GetVideosWithoutTranscriptsTool;
use PHPUnit\Framework\TestCase;

final class VideoAssetInventoryToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => $code, 'message' => $message),
            )
        );
    }

    // ─── Identity ──────────────────────────────────────────────────────────

    public function test_each_tool_declares_its_own_slug_and_flags(): void
    {
        $thumbnails  = new GetVideosWithoutThumbnailsTool($this->errors);
        $transcripts = new GetVideosWithoutTranscriptsTool($this->errors);

        $this->assertSame('get_videos_without_thumbnails', $thumbnails->getSlug());
        $this->assertSame('get_videos_without_transcripts', $transcripts->getSlug());

        foreach (array($thumbnails, $transcripts) as $tool) {
            $this->assertSame('read', $tool->getRequiredCapability());
            $this->assertContains('read-only', $tool->getCapabilityFlags());
            $this->assertNotSame('', $tool->getDescription());
        }
    }

    /**
     * The registry caps a metadata read, so the schema must not promise more.
     */
    public function test_the_limit_cannot_promise_more_than_the_registry_returns(): void
    {
        $limit = (new GetVideosWithoutThumbnailsTool($this->errors))->getParametersSchema()['properties']['limit'];

        $this->assertSame(100, $limit['maximum']);
        $this->assertSame(GetVideosWithoutThumbnailsTool::MAX_LIMIT, $limit['maximum']);
    }

    // ─── The answer ────────────────────────────────────────────────────────

    public function test_videos_with_a_poster_are_not_listed(): void
    {
        $store = $this->store(array(1, 2, 3), array(1));

        $result = (new GetVideosWithoutThumbnailsTool($this->errors, $store))
            ->execute(array());

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(3, $result['data']['examined']);
        $this->assertFalse($result['data']['truncated']);
        $this->assertSame(array(2, 3), array_column($result['data']['videos'], 'file_id'));
    }

    /**
     * The two tools differ only in the role they ask for; that is the one thing a
     * copy-paste slip would break silently.
     */
    public function test_each_tool_asks_for_its_own_role(): void
    {
        $store = $this->store(array(1), array());

        (new GetVideosWithoutThumbnailsTool($this->errors, $store))->execute(array());
        (new GetVideosWithoutTranscriptsTool($this->errors, $store))->execute(array());

        $this->assertSame(
            array(
                array(array(1), FileDerivativeStoreInterface::ROLE_POSTER),
                array(array(1), FileDerivativeStoreInterface::ROLE_CAPTION),
            ),
            $store->derivativeCalls
        );
    }

    /**
     * A full page is a window, not a census — the caller has to be able to tell.
     */
    public function test_a_full_page_is_reported_as_truncated(): void
    {
        $store = $this->store(array(1, 2), array());

        $result = (new GetVideosWithoutThumbnailsTool($this->errors, $store))
            ->execute(array('limit' => 2));

        $this->assertSame(2, $result['data']['examined']);
        $this->assertTrue($result['data']['truncated']);
    }

    public function test_an_empty_registry_is_a_finding_not_an_error(): void
    {
        $result = (new GetVideosWithoutTranscriptsTool($this->errors, $this->store(array(), array())))
            ->execute(array());

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['count']);
        $this->assertSame(0, $result['data']['examined']);
        $this->assertFalse($result['data']['truncated']);
    }

    public function test_the_limit_is_clamped_to_what_the_registry_accepts(): void
    {
        $store = $this->store(array(), array());

        (new GetVideosWithoutThumbnailsTool($this->errors, $store))->execute(array('limit' => 500));
        (new GetVideosWithoutThumbnailsTool($this->errors, $store))->execute(array('limit' => 0));

        $this->assertSame(array(100, 1), $store->limits);
    }

    // ─── The refusals ──────────────────────────────────────────────────────

    public function test_no_registry_is_a_named_refusal(): void
    {
        $result = (new GetVideosWithoutThumbnailsTool($this->errors))->execute(array());

        $this->assertFalse($result['success']);
        $this->assertSame('no_file_registry', $result['error']['code']);
    }

    /**
     * A registry that cannot record where a file came from cannot answer, and must
     * not answer "none" — that would be an inventory of false gaps.
     */
    public function test_a_registry_without_the_capability_is_a_named_refusal(): void
    {
        $result = (new GetVideosWithoutThumbnailsTool($this->errors, $this->plainStore()))
            ->execute(array());

        $this->assertFalse($result['success']);
        $this->assertSame('no_derivative_registry', $result['error']['code']);
    }

    /**
     * A registry error is an error. Returning an empty list instead would read as
     * "every video has a poster".
     */
    public function test_a_registry_failure_is_reported_not_swallowed(): void
    {
        $store = $this->store(array(1), array(), 'the registry is unavailable');

        $result = (new GetVideosWithoutTranscriptsTool($this->errors, $store))->execute(array());

        $this->assertFalse($result['success']);
        $this->assertSame('video_inventory_failed', $result['error']['code']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * A registry that can answer, and remembers what it was asked.
     *
     * @param list<int> $videoIds   Videos the registry holds.
     * @param list<int> $derivative Videos that already have the derivative.
     */
    private function store(array $videoIds, array $derivative, ?string $failure = null): object
    {
        return new class ($this->videos($videoIds), $derivative, $failure) implements FileStoreInterface, FileDerivativeStoreInterface {
            /** @var list<array{0: list<int>, 1: string}> */
            public array $derivativeCalls = array();

            /** @var list<int> */
            public array $limits = array();

            /**
             * @param list<StoredFile> $videos
             * @param list<int>        $derivative
             */
            public function __construct(
                private readonly array $videos,
                private readonly array $derivative,
                private readonly ?string $failure,
            ) {
            }

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                throw new \LogicException('Not used by these tools.');
            }

            public function getPath(int $fileId): ?string
            {
                return null;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                return null;
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return false;
            }

            public function delete(int $fileId): void
            {
            }

            public function findByMetadata(array $criteria, int $limit = 50): array
            {
                $this->limits[] = $limit;

                return array_slice($this->videos, 0, max(1, $limit));
            }

            public function recordDerivative(int $fileId, int $sourceFileId, string $role): StoredFile
            {
                throw new \LogicException('Not used by these tools.');
            }

            /**
             * @param list<int> $sourceFileIds
             * @return list<int>
             */
            public function sourceIdsWithDerivative(array $sourceFileIds, string $role): array
            {
                $this->derivativeCalls[] = array($sourceFileIds, $role);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                return array_values(array_intersect($sourceFileIds, $this->derivative));
            }
        };
    }

    /**
     * A registry that can store but cannot record relationships.
     */
    private function plainStore(): FileStoreInterface
    {
        return new class implements FileStoreInterface {
            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                throw new \LogicException('Not used by these tools.');
            }

            public function getPath(int $fileId): ?string
            {
                return null;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                return null;
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return false;
            }

            public function delete(int $fileId): void
            {
            }

            public function findByMetadata(array $criteria, int $limit = 50): array
            {
                return array();
            }
        };
    }

    /**
     * @param list<int> $ids
     * @return list<StoredFile>
     */
    private function videos(array $ids): array
    {
        return array_map(
            static fn (int $id): StoredFile => new StoredFile(
                id: $id,
                filename: 'clip-' . $id . '.mp4',
                mimeType: 'video/mp4',
                sizeBytes: 1000 * $id,
                localPath: '/srv/clip-' . $id . '.mp4',
                ownerId: 7,
                createdAt: new \DateTimeImmutable('2026-01-02T03:04:05+00:00'),
            ),
            $ids,
        );
    }
}
