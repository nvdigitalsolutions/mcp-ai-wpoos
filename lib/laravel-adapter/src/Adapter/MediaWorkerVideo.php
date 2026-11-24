<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\VideoProcessingInterface;

/**
 * Video processing over the media worker — Proposal 164 §164.4.5.
 *
 * `/api/video/process` writes its output to the **worker's** disk and answers a
 * filename; `/api/video/download/<name>` is how this host gets the bytes. The
 * download is part of `process()` rather than a second call a caller has to know
 * about, so no intermediate name ever escapes the adapter.
 *
 * ## Everything is a string field
 *
 * The route reads multipart text fields and passes them to ffmpeg as argv, never
 * through a shell. The adapter still bounds them here — an operation outside the
 * allowlist, a dimension of zero, a negative start — because a refusal from this
 * side names the option, while the worker's refusal is a 400 about ffmpeg.
 *
 * ## The filename is checked before the fetch
 *
 * `output_file` comes from the worker, so it is not attacker-controlled in the
 * usual sense — but this host then interpolates it into a URL path, and a worker
 * that had been compromised (or a future route that echoed a caller-supplied
 * value) must not be able to make this host fetch `../../etc/passwd`. Every
 * segment of the name is therefore validated by name as well as by charset, so
 * the URL the client sends stays under `/api/video/download/`.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */
final class MediaWorkerVideo implements VideoProcessingInterface
{
    /**
     * The operations the route implements. `extract_frames` is deliberately
     * absent: it answers many files, which is a different return shape and a
     * separate tool.
     */
    private const OPERATIONS = array(
        'resize',
        'crop',
        'fit',
        'trim',
        'convert',
        'compress',
        'gif',
        'thumbnail',
        'extract_audio',
        'speed',
    );

    private const FORMATS = array('mp4', 'webm', 'mov', 'mkv', 'gif', 'mp3', 'm4a', 'aac', 'jpg', 'jpeg', 'png');

    public function __construct(
        private readonly MediaWorkerClient $worker,
        private readonly MediaWorkerCapabilityProbe $probe,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public static function operations(): array
    {
        return self::OPERATIONS;
    }

    /**
     * {@inheritDoc}
     */
    public function info(string $localPath): array
    {
        $this->assertReadable($localPath);

        $body = $this->worker->uploadFile(
            '/api/video/info',
            $localPath,
            basename($localPath),
            array(),
            $this->timeout('video_info_timeout', 60),
        );

        $video = \is_array($body['video'] ?? null) ? $body['video'] : null;
        $audio = \is_array($body['audio'] ?? null) ? $body['audio'] : null;

        return array(
            'format' => isset($body['format']) && \is_string($body['format']) ? $body['format'] : null,
            'duration' => (float) ($body['duration'] ?? 0),
            'size' => (int) ($body['size'] ?? 0),
            'bitrate' => (int) ($body['bitrate'] ?? 0),
            'video' => null === $video ? null : array(
                'codec' => (string) ($video['codec'] ?? ''),
                'width' => (int) ($video['width'] ?? 0),
                'height' => (int) ($video['height'] ?? 0),
                'fps' => (float) ($video['fps'] ?? 0),
                'bitrate' => (int) ($video['bitrate'] ?? 0),
                'pixel_format' => (string) ($video['pixel_format'] ?? ''),
            ),
            'audio' => null === $audio ? null : array(
                'codec' => (string) ($audio['codec'] ?? ''),
                'channels' => (int) ($audio['channels'] ?? 0),
                'sample_rate' => (int) ($audio['sample_rate'] ?? 0),
                'bitrate' => (int) ($audio['bitrate'] ?? 0),
            ),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function process(string $localPath, string $operation, array $options = array()): array
    {
        if (! \in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException(
                'Unsupported video operation: '.$operation.'. Allowed: '.\implode(', ', self::OPERATIONS).'.'
            );
        }

        $this->assertReadable($localPath);

        $format = $this->format($options['format'] ?? ('gif' === $operation ? 'gif' : 'mp4'));

        $fields = array(
            'operation' => $operation,
            'format' => $format,
        );

        $width = $this->dimension($options['width'] ?? null);

        if (null !== $width) {
            $fields['width'] = $width;
        }

        $height = $this->dimension($options['height'] ?? null);

        if (null !== $height) {
            $fields['height'] = $height;
        }

        $start = $this->seconds($options['start'] ?? null, 'start', true);

        if (null !== $start) {
            $fields['start'] = $start;
        }

        $duration = $this->seconds($options['duration'] ?? null, 'duration');

        if (null !== $duration) {
            $fields['duration'] = $duration;
        }

        $fps = $this->seconds($options['fps'] ?? null, 'fps');

        if (null !== $fps) {
            $fields['fps'] = $fps;
        }

        // `speed` is the reciprocal of the route's `setpts` multiplier, so the
        // adapter sends the caller's factor and lets the route invert it.
        if ('speed' === $operation) {
            $fields['speed'] = $this->speedFactor($options['speed'] ?? null);
            $fields['maintain_pitch'] = $this->boolean($options['maintain_pitch'] ?? true) ? 'true' : 'false';
        }

        if ('resize' === $operation && null === $width && null === $height) {
            throw new \InvalidArgumentException('Resize needs a width, a height, or both.');
        }

        if ('crop' === $operation && (null === $width || null === $height)) {
            throw new \InvalidArgumentException('Crop needs both a width and a height.');
        }

        // `fit` is the one operation whose whole point is an exact frame, so a
        // missing side is a mistake rather than a "keep the aspect" request.
        if ('fit' === $operation) {
            if (null === $width || null === $height) {
                throw new \InvalidArgumentException('Fit needs both a width and a height.');
            }

            $fields['fit'] = $this->fitMode($options['fit'] ?? 'cover');
        }

        $body = $this->worker->uploadFile(
            '/api/video/process',
            $localPath,
            basename($localPath),
            $fields,
            $this->timeout('video_timeout', 300),
        );

        $remote = (string) ($body['output_file'] ?? '');

        if (! $this->safeRemoteName($remote)) {
            throw new \RuntimeException(
                'The media worker answered an output name this host will not fetch: '.($remote ?: '(empty)')
            );
        }

        $bytes = $this->worker->download(
            '/api/video/download/'.$remote,
            $this->timeout('video_timeout', 300),
        );

        $path = MediaWorkerOutput::write(
            $bytes,
            'video-'.$operation,
            $this->extension($format),
            $localPath.'|'.$operation.'|'.$format.'|'.\json_encode($fields),
        );

        return array(
            'path' => $path,
            'operation' => $operation,
            'format' => $format,
            'bytes' => \strlen($bytes),
            'original_bytes' => (int) ($body['original_size'] ?? 0),
            'savings_percent' => (float) \rtrim((string) ($body['savings_percent'] ?? '0'), '%'),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function supports(string $operation): bool
    {
        if (! \in_array($operation, self::OPERATIONS, true) || ! $this->worker->isConfigured()) {
            return false;
        }

        return $this->probe->report()->supports('video_processing');
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return $this->worker->isConfigured();
    }

    private function format(mixed $value): string
    {
        $slug = \strtolower(\trim((string) $value));

        return \in_array($slug, self::FORMATS, true) ? $slug : 'mp4';
    }

    /**
     * Whether the worker's answer is a relative name this host will fetch.
     *
     * A charset check alone is not enough: `..` is made entirely of allowed
     * characters, so `../../etc/passwd` would pass a regex and the built URL
     * would climb out of `/api/video/download/` when the client normalises it.
     * Each segment is therefore validated by name, and dot segments — which
     * have no legitimate use in an output filename — are refused outright.
     */
    private function safeRemoteName(string $name): bool
    {
        if ('' === $name || \strlen($name) > 255) {
            return false;
        }

        foreach (\explode('/', $name) as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return false;
            }

            if (1 !== \preg_match('/^[A-Za-z0-9._-]+$/', $segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A positive pixel count, or null when not supplied.
     */
    private function dimension(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $pixels = (int) $value;

        if ($pixels < 1 || $pixels > 8192) {
            throw new \InvalidArgumentException('A dimension must be between 1 and 8192 pixels.');
        }

        return $pixels;
    }

    /**
     * How a fitted frame is filled.
     *
     * `cover` crops the overflow so the frame is all picture; `contain` pads it
     * so nothing is cropped. Two behaviours, both named — anything else is
     * refused rather than guessed, because the value becomes a filter argument.
     */
    private function fitMode(mixed $value): string
    {
        $mode = \strtolower(\trim((string) $value));

        if ('' === $mode) {
            return 'cover';
        }

        if (! \in_array($mode, array('cover', 'contain'), true)) {
            throw new \InvalidArgumentException('A fit must be either cover or contain.');
        }

        return $mode;
    }

    /**
     * The multiplier for the `speed` operation.
     *
     * Bounded to the same 0.25–4 the tool offers, so an out-of-range factor is
     * named here rather than relayed from an ffmpeg error.
     */
    private function speedFactor(mixed $value): float
    {
        if (null === $value || '' === $value) {
            throw new \InvalidArgumentException('The speed operation needs a speed factor between 0.25 and 4.0.');
        }

        $factor = (float) $value;

        if ($factor < 0.25 || $factor > 4.0) {
            throw new \InvalidArgumentException('A speed factor must be between 0.25 and 4.0.');
        }

        return $factor;
    }

    /**
     * A boolean option as a multipart field.
     *
     * Multipart bodies carry every field as text, so `false` has to be spelled
     * out. The route reads the same four spellings.
     */
    private function boolean(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return ! \in_array(\strtolower(\trim((string) $value)), array('false', '0', 'no', 'off'), true);
    }

    /**
     * A number of seconds (or a frame rate).
     *
     * `$allowZero` exists for `start`: a thumbnail at 0 s is the most common
     * request there is, while a duration of 0 or a frame rate of 0 is a mistake.
     */
    private function seconds(mixed $value, string $label, bool $allowZero = false): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $number = (float) $value;

        $floor = $allowZero ? 0.0 : 0.0001;

        if ($number < $floor || $number > 86400) {
            throw new \InvalidArgumentException(
                $label.($allowZero ? ' must be at least 0' : ' must be greater than 0').' and at most 86400.'
            );
        }

        return $number;
    }

    /**
     * The extension for a container, so a downloaded file has a name a player
     * recognises.
     */
    private function extension(string $format): string
    {
        return match ($format) {
            'mp4' => 'mp4',
            'webm' => 'webm',
            'mov' => 'mov',
            'mkv' => 'mkv',
            'gif' => 'gif',
            'mp3' => 'mp3',
            'm4a' => 'm4a',
            'aac' => 'aac',
            'jpg' => 'jpg',
            'jpeg' => 'jpg',
            'png' => 'png',
            default => 'bin',
        };
    }

    private function timeout(string $key, int $fallback): int
    {
        return \max(1, (int) \config('oos.media_worker.'.$key, $fallback));
    }

    private function assertReadable(string $localPath): void
    {
        if ('' === \trim($localPath)) {
            throw new \InvalidArgumentException('A video path is required.');
        }

        if (! \is_file($localPath) || ! \is_readable($localPath)) {
            throw new \InvalidArgumentException('The video does not exist or is not readable: '.$localPath);
        }
    }
}
