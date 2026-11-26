<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\ImageProcessingInterface;

/**
 * Image processing over the media worker — Proposal 149 §160.
 *
 * The port has no PHP image library (no GD, no Imagick), and the sidecar is
 * already the platform's Sharp pipeline, so this adapter translates the domain
 * contract into the worker's transform vocabulary instead of adding a second
 * pipeline. That is the arrangement Proposal 160 §11 named as the intended
 * backend for the four `unavailable` image rows.
 *
 * Three consequences are deliberate:
 *
 *  - **`isAvailable()` is "configured", not "healthy".** It is asked before
 *    every call, and a health probe there would add a network round-trip to a
 *    capability check. A worker that is down surfaces as a `RuntimeException`
 *    from the call itself, which the tool reports as `resize_failed` &c.
 *  - **Output is written by this adapter.** The contract returns a path, and
 *    the worker returns bytes, so the decoded payload lands in
 *    `oos.media_worker.output_dir` (default `storage/app/media-worker`) under a
 *    name derived from the source and the operation. Repeating a transform
 *    rewrites the same file rather than accumulating copies.
 *  - **The source is a local path, like the WordPress adapter.** `wp_get_image_editor()`
 *    only accepts a path, and the tools pass `image_url` straight through, so a
 *    URL fails the same way in both runtimes rather than changing meaning here.
 */
final class MediaWorkerImageProcessing implements ImageProcessingInterface
{
    /**
     * Formats the worker's encoder allowlist honours.
     */
    private const FORMATS = array('png', 'jpeg', 'webp', 'gif');

    public function __construct(private readonly MediaWorkerClient $worker)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function resize(string $sourcePath, int $width, int $height, array $options = array()): array
    {
        $crop = ! array_key_exists('crop', $options) || (bool) $options['crop'];

        return $this->transform(
            $sourcePath,
            'resize',
            array(
                array(
                    'type' => 'resize',
                    'width' => $width,
                    'height' => $height,
                    'fit' => $crop ? 'cover' : 'inside',
                ),
            ),
            '',
            isset($options['quality']) ? (int) $options['quality'] : 90,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function crop(string $sourcePath, int $x, int $y, int $width, int $height): array
    {
        return $this->transform(
            $sourcePath,
            'crop',
            array(
                array(
                    'type' => 'crop',
                    'left' => $x,
                    'top' => $y,
                    'width' => $width,
                    'height' => $height,
                ),
            ),
            '',
            90,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function rotate(string $sourcePath, float $angle, string $background = '#ffffff'): array
    {
        return $this->transform(
            $sourcePath,
            'rotate',
            array(
                array(
                    'type' => 'rotate',
                    'angle' => $angle,
                    'background' => $background,
                ),
            ),
            '',
            90,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function convert(string $sourcePath, string $targetFormat, int $quality = 90): array
    {
        $format = strtolower($targetFormat);

        if ('jpg' === $format) {
            $format = 'jpeg';
        }

        if (! in_array($format, self::FORMATS, true)) {
            throw new \RuntimeException('Unsupported target format: '.$targetFormat);
        }

        return $this->transform($sourcePath, 'convert', array(), $format, $quality);
    }

    /**
     * {@inheritDoc}
     */
    public function getInfo(string $sourcePath): array
    {
        $this->assertReadable($sourcePath);

        $body = $this->worker->info($sourcePath, basename($sourcePath));

        return array(
            'width' => $body['width'],
            'height' => $body['height'],
            'mime_type' => $body['mime_type'],
            'bytes' => $body['bytes'],
            'format' => $body['format'],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return $this->worker->configured();
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedFormats(): array
    {
        return self::FORMATS;
    }

    /**
     * Send one operation set and publish the result.
     *
     * @param list<array<string, mixed>> $operations
     * @return array{path: string, width: int, height: int, mime_type: string, bytes: int}
     */
    private function transform(
        string $sourcePath,
        string $operation,
        array $operations,
        string $format,
        int $quality
    ): array {
        $this->assertReadable($sourcePath);

        $body = $this->worker->transform(
            $sourcePath,
            basename($sourcePath),
            $operations,
            $format,
            $quality,
        );

        return array(
            'path' => $this->writeOutput($body['b64'], $sourcePath, $operation, $body['format']),
            'width' => $body['width'],
            'height' => $body['height'],
            'mime_type' => $body['mime_type'],
            'bytes' => $body['bytes'],
        );
    }

    /**
     * Decode the worker's payload into the configured output directory.
     *
     * The name is stable for the same source, operation and format, and the
     * write is published with `rename()` so a reader never sees a partial file.
     */
    private function writeOutput(string $encoded, string $sourcePath, string $operation, string $format): string
    {
        $decoded = base64_decode($encoded, true);

        if (false === $decoded) {
            throw new \RuntimeException('The media worker returned an undecodable payload.');
        }

        $directory = (string) config('oos.media_worker.output_dir', '');

        if ('' === $directory) {
            $directory = storage_path('app/media-worker');
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Image output directory is not writable: '.$directory);
        }

        $source = pathinfo($sourcePath);
        $name = sprintf(
            '%s-%s.%s',
            '' !== ($source['filename'] ?? '') ? $source['filename'] : 'image',
            substr(hash('sha256', $sourcePath.'|'.$operation.'|'.$format), 0, 12),
            $format,
        );

        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$name;
        $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (false === file_put_contents($temp, $decoded)) {
            throw new \RuntimeException('Could not write the processed image.');
        }

        if (! @rename($temp, $path)) {
            @unlink($temp);

            throw new \RuntimeException('Could not publish the processed image.');
        }

        clearstatcache(true, $path);

        return $path;
    }

    private function assertReadable(string $sourcePath): void
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new \RuntimeException('Image file not found: '.$sourcePath);
        }
    }
}
