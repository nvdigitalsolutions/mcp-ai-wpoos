<?php

/**
 * The poll handler's three outcomes, and the two refusals it must not retry.
 *
 * A render that is still running and a render that ended badly look alike to a
 * queue: both are "the handler did not finish". They need opposite treatment,
 * so the exceptions are the contract — `VideoGenerationPendingException` means
 * try again, `NonRetryableJobException` means stop and record the reason.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Media;

use Nvoos\Core\Application\Media\VideoGenerationPendingException;
use Nvoos\Core\Application\Media\VideoGenerationPollHandler;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\VideoGenerationInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Domain\Error\NonRetryableJobException;
use PHPUnit\Framework\TestCase;

final class VideoGenerationPollHandlerTest extends TestCase
{
    /** An MP4 header: `ftyp` at offset 4 is what the format sniffer reads. */
    private const MP4 = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2";

    public function test_a_running_render_asks_for_another_attempt(): void
    {
        $http = $this->http();

        $handler = new VideoGenerationPollHandler(
            $this->videos(array('status' => 'processing', 'terminal' => false, 'video_url' => null)),
            $http,
            $this->store()
        );

        $this->expectException(VideoGenerationPendingException::class);
        $this->expectExceptionMessage('still processing');

        try {
            $handler->handle(array('prediction_id' => 'pred-1'));
        } finally {
            $this->assertSame(array(), $http->calls, 'A pending render downloads nothing.');
        }
    }

    public function test_a_failed_render_stops_instead_of_retrying(): void
    {
        $handler = new VideoGenerationPollHandler(
            $this->videos(
                array(
                    'status' => 'failed',
                    'terminal' => true,
                    'video_url' => null,
                    'error' => 'model refused the input',
                )
            ),
            $this->http(),
            $this->store()
        );

        $this->expectException(NonRetryableJobException::class);
        $this->expectExceptionMessage('model refused the input');

        $handler->handle(array('prediction_id' => 'pred-1'));
    }

    public function test_a_finished_render_is_downloaded_and_stored(): void
    {
        $http = $this->http(self::MP4);
        $store = $this->store();

        $handler = new VideoGenerationPollHandler(
            $this->videos(
                array(
                    'status' => 'succeeded',
                    'terminal' => true,
                    'video_url' => 'https://cdn.example.test/clip.mp4',
                    'model' => 'stable-video-diffusion',
                    'metrics' => array('predict_time' => 42.5),
                )
            ),
            $http,
            $store
        );

        $result = $handler->handle(
            array(
                'prediction_id' => 'pred-1',
                'user_id' => 7,
                'visibility' => 'public',
                'file_name' => 'product-video',
            )
        );

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('pred-1', $result['prediction_id']);
        $this->assertSame('mp4', $result['format']);
        $this->assertSame(\strlen(self::MP4), $result['bytes']);
        $this->assertSame(901, $result['media'][0]['attachment_id']);
        $this->assertSame('video/mp4', $store->mime);

        // The bytes handed to the registry are the downloaded ones, and the
        // temporary file is gone.
        $this->assertSame(self::MP4, $store->bytes);
        $this->assertFileDoesNotExist($store->path);

        $this->assertSame(
            array(array('GET', 'https://cdn.example.test/clip.mp4')),
            $http->calls
        );
    }

    public function test_a_url_this_runtime_will_not_fetch_is_not_retried(): void
    {
        $http = $this->http();

        $handler = new VideoGenerationPollHandler(
            $this->videos(
                array(
                    'status' => 'succeeded',
                    'terminal' => true,
                    'video_url' => 'file:///etc/passwd',
                )
            ),
            $http,
            $this->store()
        );

        $this->expectException(NonRetryableJobException::class);
        $this->expectExceptionMessage('will not fetch');

        try {
            $handler->handle(array('prediction_id' => 'pred-1'));
        } finally {
            $this->assertSame(array(), $http->calls);
        }
    }

    public function test_a_payload_without_a_prediction_is_not_retried(): void
    {
        $handler = new VideoGenerationPollHandler(
            $this->videos(array('status' => 'processing', 'terminal' => false)),
            $this->http(),
            $this->store()
        );

        $this->expectException(NonRetryableJobException::class);
        $this->expectExceptionMessage('no prediction id');

        $handler->handle(array());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function videos(array $overrides): VideoGenerationInterface
    {
        $status = \array_merge(
            array(
                'prediction_id' => 'pred-1',
                'status' => 'processing',
                'terminal' => false,
                'video_url' => null,
                'error' => null,
                'model' => 'stable-video-diffusion',
                'metrics' => array(),
            ),
            $overrides
        );

        return new class ($status) implements VideoGenerationInterface {
            /** @param array<string, mixed> $status */
            public function __construct(private readonly array $status)
            {
            }

            public function submit(string $prompt, array $options = array()): array
            {
                throw new \LogicException('not used');
            }

            public function status(string $predictionId): array
            {
                return $this->status;
            }

            public function supports(string $operation): bool
            {
                return true;
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };
    }

    private function http(string $body = ''): HttpClientInterface
    {
        return new class ($body) implements HttpClientInterface {
            /** @var list<array{0: string, 1: string}> */
            public array $calls = array();

            public function __construct(private readonly string $body)
            {
            }

            public function send(string $method, string $url, array $headers = array(), ?string $body = null): HttpResponse
            {
                $this->calls[] = array($method, $url);

                return new HttpResponse(200, $this->body, array('content-type' => 'video/mp4'));
            }
        };
    }

    private function store(): FileVisibilityStoreInterface
    {
        return new class () implements FileVisibilityStoreInterface {
            public string $bytes = '';

            public string $path = '';

            public string $mime = '';

            public function storeWithVisibility(
                string $localPath,
                string $filename,
                string $mimeType,
                int $userId,
                string $visibility,
                ?string $tenantId = null
            ): StoredFile {
                $this->path = $localPath;
                $this->mime = $mimeType;
                $this->bytes = (string) \file_get_contents($localPath);

                return new StoredFile(
                    901,
                    $filename . '.mp4',
                    $mimeType,
                    (int) \strlen($this->bytes),
                    $localPath,
                    $userId,
                    new \DateTimeImmutable(),
                    'https://example.test/media/' . $filename . '.mp4',
                    array(),
                    $visibility,
                    'video'
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return true;
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };
    }
}
