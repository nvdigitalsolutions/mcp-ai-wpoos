<?php

/**
 * Poll one video prediction and, when it ends, store the bytes.
 *
 * Enqueued by `product_actualization`'s video mode with a prediction id the
 * `submit()` call already returned. Each run asks once, so no request holds a
 * connection for the length of a render:
 *
 *  - still running → `VideoGenerationPendingException`, and the queue runs the
 *    handler again after the dispatch's backoff;
 *  - ended badly   → `NonRetryableJobException`, which fails the job at once
 *    with the provider's reason;
 *  - succeeded     → the provider URL is downloaded and stored in the file
 *    registry, and the job completes with the media entry.
 *
 * The provider URL is transient, so it is never returned to a caller as the
 * artifact: the durable answer is the registry entry.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Media;

use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\VideoGenerationInterface;
use Nvoos\Core\Domain\Error\NonRetryableJobException;

final class VideoGenerationPollHandler
{
    /**
     * A render is a short clip; anything this large is a wrong URL or a bug.
     */
    private const MAX_VIDEO_BYTES = 209715200;

    public function __construct(
        private readonly VideoGenerationInterface $videos,
        private readonly HttpClientInterface $http,
        private readonly FileVisibilityStoreInterface $files,
    ) {
    }

    /**
     * @param array{prediction_id?: string, user_id?: int, visibility?: string, file_name?: string} $payload
     *
     * @return array<string, mixed>
     *
     * @throws VideoGenerationPendingException When the render is still running.
     * @throws NonRetryableJobException         When the render ended without a video.
     * @throws \RuntimeException                When a still-valid URL could not be downloaded or stored.
     */
    public function handle(array $payload): array
    {
        $prediction = \trim((string) ($payload['prediction_id'] ?? ''));

        if ('' === $prediction) {
            throw new NonRetryableJobException('The queued video job carries no prediction id.');
        }

        $status = $this->videos->status($prediction);

        if (! $status['terminal']) {
            throw new VideoGenerationPendingException('Video generation is still ' . $status['status'] . '.');
        }

        if ('succeeded' !== $status['status'] || null === $status['video_url']) {
            throw new NonRetryableJobException(
                'Video generation ended as ' . $status['status']
                . (null !== $status['error'] ? ': ' . $status['error'] : '.')
            );
        }

        $bytes = $this->download($status['video_url']);
        $format = $this->format($bytes);
        $path = $this->write($bytes);

        try {
            $outcome = (new GeneratedMediaPersister($this->files))->storeFile(
                $path,
                array('user_id' => (int) ($payload['user_id'] ?? 0)),
                (string) ($payload['visibility'] ?? FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                (string) ($payload['file_name'] ?? 'product-video'),
                $format['mime'],
            );
        } finally {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }

        if (null === $outcome['media']) {
            throw new \RuntimeException(
                'The video was rendered but could not be stored: ' . ($outcome['error'] ?? 'unknown')
            );
        }

        return array(
            'status' => 'succeeded',
            'prediction_id' => $prediction,
            'provider' => 'replicate',
            'model' => $status['model'],
            'format' => $format['format'],
            'bytes' => \strlen($bytes),
            'media' => array($outcome['media']),
            'metrics' => $status['metrics'],
        );
    }

    /**
     * Fetch the finished render from the provider's URL.
     */
    private function download(string $url): string
    {
        $scheme = \strtolower((string) \parse_url($url, PHP_URL_SCHEME));

        if (! \in_array($scheme, array('http', 'https'), true)) {
            throw new NonRetryableJobException('The provider returned a video URL this runtime will not fetch.');
        }

        $response = $this->http->send('GET', $url);

        if ($response->statusCode < 200 || $response->statusCode >= 300 || '' === $response->body) {
            throw new \RuntimeException('The rendered video URL answered HTTP ' . $response->statusCode . '.');
        }

        if (\strlen($response->body) > self::MAX_VIDEO_BYTES) {
            throw new NonRetryableJobException('The rendered video is larger than 200 MB.');
        }

        return $response->body;
    }

    private function write(string $bytes): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'oos-video-');

        if (false === $path || \strlen($bytes) !== \file_put_contents($path, $bytes)) {
            throw new \RuntimeException('Could not write the rendered video to a temporary file.');
        }

        return $path;
    }

    /**
     * The container of the bytes themselves.
     *
     * @return array{format: string, mime: string}
     */
    private function format(string $bytes): array
    {
        $header = \substr($bytes, 0, 12);

        if ('ftyp' === \substr($header, 4, 4)) {
            return array('format' => 'mp4', 'mime' => 'video/mp4');
        }

        if (0 === \strncmp($header, "\x1A\x45\xDF\xA3", 4)) {
            return array('format' => 'webm', 'mime' => 'video/webm');
        }

        if (0 === \strncmp($header, 'GIF8', 4)) {
            return array('format' => 'gif', 'mime' => 'image/gif');
        }

        return array('format' => 'mp4', 'mime' => 'video/mp4');
    }
}
