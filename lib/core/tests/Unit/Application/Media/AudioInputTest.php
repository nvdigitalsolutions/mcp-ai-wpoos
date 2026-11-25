<?php

/**
 * Resolving an audio source is four rules, and each one is a way to leak.
 *
 * The tool that reads this resolved `attachment_id` without ever reading the
 * file, so every branch here is pinned rather than assumed.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Media;

use Nvoos\Core\Application\Media\AudioInput;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use PHPUnit\Framework\TestCase;

final class AudioInputTest extends TestCase
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
    }

    public function test_a_stored_audio_file_resolves_to_its_bytes_type_and_name(): void
    {
        $input = new AudioInput($this->errors(), $this->registry(array(
            7 => $this->stored(7, 'memo.mp3', 'audio/mpeg', 'AUDIO-BYTES'),
        )));

        $this->assertSame(
            array('bytes' => 'AUDIO-BYTES', 'mime' => 'audio/mpeg', 'filename' => 'memo.mp3'),
            $input->fromRegistry(7),
        );
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $input = new AudioInput($this->errors(), $this->registry(array()));

        $result = $input->fromRegistry(99);

        $this->assertSame('not_found', $result['code']);
        $this->assertSame('No file with that id exists.', $result['message']);
    }

    public function test_a_file_the_caller_may_not_read_answers_exactly_like_a_missing_one(): void
    {
        $input = new AudioInput(
            $this->errors(),
            $this->registry(array(7 => $this->stored(7, 'memo.mp3', 'audio/mpeg', 'AUDIO-BYTES')), array(7)),
        );

        $result = $input->fromRegistry(7, array('user_id' => 42));

        // Whether the row exists is not something a caller should learn.
        $this->assertSame('not_found', $result['code']);
        $this->assertSame('No file with that id exists.', $result['message']);
    }

    public function test_a_system_context_skips_the_ownership_check(): void
    {
        $input = new AudioInput(
            $this->errors(),
            $this->registry(array(7 => $this->stored(7, 'memo.mp3', 'audio/mpeg', 'AUDIO-BYTES')), array(7)),
        );

        $this->assertSame('AUDIO-BYTES', $input->fromRegistry(7, array('user_id' => 0))['bytes']);
    }

    public function test_an_image_is_not_audio(): void
    {
        $input = new AudioInput($this->errors(), $this->registry(array(
            7 => $this->stored(7, 'shot.png', 'image/png', 'PNG'),
        )));

        $result = $input->fromRegistry(7);

        $this->assertSame('validation_failed', $result['code']);
        $this->assertSame('That file is not audio.', $result['message']);
    }

    public function test_a_video_points_at_the_tool_that_can_take_it(): void
    {
        $input = new AudioInput($this->errors(), $this->registry(array(
            7 => $this->stored(7, 'clip.mp4', 'video/mp4', 'MP4'),
        )));

        $result = $input->fromRegistry(7);

        $this->assertSame('validation_failed', $result['code']);
        $this->assertStringContainsString('transcribe_video', $result['message']);
    }

    public function test_a_file_over_the_provider_ceiling_is_refused_before_it_is_read(): void
    {
        $input = new AudioInput($this->errors(), $this->registry(array(
            7 => $this->stored(7, 'long.mp3', 'audio/mpeg', 'AUDIO', AudioInput::MAX_BYTES + 1),
        )));

        $result = $input->fromRegistry(7);

        $this->assertSame('validation_failed', $result['code']);
        $this->assertStringContainsString((string) AudioInput::MAX_BYTES, $result['message']);
    }

    public function test_a_host_without_a_registry_says_so_and_names_the_alternative(): void
    {
        $input = new AudioInput($this->errors(), null);

        $result = $input->fromRegistry(7);

        $this->assertSame('file_store_unavailable', $result['code']);
        $this->assertStringContainsString('audio_url', $result['message']);
    }

    public function test_a_row_whose_bytes_are_gone_is_reported_rather_than_sent_empty(): void
    {
        $stored = new StoredFile(
            id: 7,
            filename: 'gone.mp3',
            mimeType: 'audio/mpeg',
            sizeBytes: 10,
            localPath: sys_get_temp_dir() . '/nvoos-audio-that-does-not-exist.mp3',
            ownerId: 1,
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        $result = (new AudioInput($this->errors(), $this->registry(array(7 => $stored))))->fromRegistry(7);

        $this->assertSame('file_unreadable', $result['code']);
    }

    public function test_the_mime_map_covers_what_this_runtime_writes_and_falls_back_safely(): void
    {
        $this->assertSame('audio/mpeg', AudioInput::mimeFor('memo.mp3'));
        $this->assertSame('audio/mp4', AudioInput::mimeFor('memo.m4a'));
        $this->assertSame('audio/aac', AudioInput::mimeFor('memo.aac'));
        $this->assertSame('audio/flac', AudioInput::mimeFor('memo.FLAC'));

        // A signed CDN name carries no extension: the default is what the tool
        // has always declared, and it is the only answer that is not a guess.
        $this->assertSame('audio/mpeg', AudioInput::mimeFor('2f9a1c-token'));
        $this->assertSame('audio/mpeg', AudioInput::mimeFor(''));
    }

    /**
     * @param array<int, StoredFile> $files
     * @param list<int>              $forbidden
     */
    private function registry(array $files, array $forbidden = array()): FileStoreInterface
    {
        return new class ($files, $forbidden) implements FileStoreInterface {
            /**
             * @param array<int, StoredFile> $files
             * @param list<int>              $forbidden
             */
            public function __construct(private readonly array $files, private readonly array $forbidden)
            {
            }

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                throw new \RuntimeException('not used');
            }

            public function getPath(int $fileId): ?string
            {
                return $this->files[$fileId]->localPath ?? null;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                return $this->files[$fileId] ?? null;
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return ! in_array($fileId, $this->forbidden, true);
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

    private function stored(int $id, string $name, string $mime, string $bytes, ?int $size = null): StoredFile
    {
        $path = sys_get_temp_dir() . '/nvoos-audio-' . bin2hex(random_bytes(5));

        file_put_contents($path, $bytes);

        $this->tempFiles[] = $path;

        return new StoredFile(
            id: $id,
            filename: $name,
            mimeType: $mime,
            sizeBytes: $size ?? strlen($bytes),
            localPath: $path,
            ownerId: 1,
            createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    private function errors(): ErrorFactoryInterface
    {
        return new class () implements ErrorFactoryInterface {
            public function create(string $code, string $message, array $data = array()): mixed
            {
                return array('kind' => 'error', 'code' => $code, 'message' => $message, 'data' => $data);
            }

            public function isError(mixed $value): bool
            {
                return is_array($value) && 'error' === ( $value['kind'] ?? '' );
            }

            public function normalize(mixed $error): array
            {
                return array(
                    'code'    => (string) ( $error['code'] ?? 'unknown' ),
                    'message' => (string) ( $error['message'] ?? '' ),
                    'data'    => (array) ( $error['data'] ?? array() ),
                );
            }

            public function notFound(string $message = 'Resource not found.', array $data = array()): mixed
            {
                return $this->create('not_found', $message, $data);
            }

            public function forbidden(string $message = 'Access denied.', array $data = array()): mixed
            {
                return $this->create('forbidden', $message, $data);
            }

            public function validationFailed(string $message, array $errors = array()): mixed
            {
                return $this->create('validation_failed', $message, array('errors' => $errors, 'status' => 422));
            }

            public function rateLimited(string $message, int $retryAfterSeconds = 60): mixed
            {
                return $this->create('rate_limited', $message, array('retry_after' => $retryAfterSeconds));
            }
        };
    }
}
