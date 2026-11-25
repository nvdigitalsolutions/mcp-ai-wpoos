<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\VideoFrameExtractionInterface;

/**
 * Frame extraction over `/api/video/process` — Proposal 164 §164.25.
 *
 * The route already had an `extract_frames` operation with no slug asking for
 * it; what it did not have was a way to return many files. `process()` is one
 * file in, one file out, and eight tools read that shape, so this is its own
 * contract rather than a widened return type.
 *
 * ## Two calls per frame, and that is the contract
 *
 * `/process` answers `output_files` on the **worker's** disk and keeps the
 * directory for five minutes. There is no bytes-in-response path, so each name
 * becomes a `GET /api/video/download/<name>` and the host writes the bytes it
 * receives. Every name is re-validated by `MediaWorkerRemoteName` first — the
 * names are nested (`frames_<ts>/frame_000.jpg`), and that helper validates
 * per segment for exactly this case.
 *
 * ## The modes are resolved here, not merged
 *
 * `timestamps` wins, then `interval`, then `count`. If a caller sends two, the
 * first is honoured and the second is not silently folded in: the WordPress tool
 * treats them as alternatives and so does this adapter.
 *
 * @package Nvoos\Laravel
 * @since   1.3.0
 * @license MIT
 */

final class MediaWorkerVideoFrames implements VideoFrameExtractionInterface
{
    /**
     * The route's own ceiling. A contact sheet is not a video.
     */
    private const MAX_FRAMES = 120;

    private const MAX_TIMESTAMP = 86400;

    public function __construct(
        private readonly MediaWorkerClient $worker,
        private readonly MediaWorkerCapabilityProbe $probe,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function extract(string $localPath, array $options = array()): array
    {
        if ('' === \trim($localPath) || ! \is_file($localPath) || ! \is_readable($localPath)) {
            throw new \InvalidArgumentException(
                'The video does not exist or is not readable: '.$localPath
            );
        }

        $fields = array(
            'operation' => 'extract_frames',
            'quality' => $this->integer($options['quality'] ?? 2, 1, 31, 'quality') ?? 2,
        );

        $timestamps = $this->timestamps($options['timestamps'] ?? array());

        if (array() !== $timestamps) {
            $fields['timestamps'] = \implode(',', $timestamps);
        }

        $interval = $this->number($options['interval'] ?? null, 0.1, 3600.0, 'interval');
        $count = $this->integer($options['count'] ?? null, 1, self::MAX_FRAMES, 'count');
        $width = $this->integer($options['width'] ?? null, 16, 8192, 'width');

        if (null !== $interval) {
            $fields['interval'] = $interval;
        }

        if (null !== $count) {
            $fields['count'] = $count;
        }

        if (null !== $width) {
            $fields['width'] = $width;
        }

        try {
            $body = $this->worker->uploadFile(
                '/api/video/process',
                $localPath,
                \basename($localPath),
                $fields,
                \max(1, (int) \config('oos.media_worker.video_timeout', 300)),
            );
        } catch (MediaWorkerRefusedException $e) {
            // A 400 from the route is the caller's to fix: a bad timestamp, an
            // impossible interval. It must not be reported as a broken engine.
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $names = $body['output_files'] ?? null;

        if (! \is_array($names) || array() === $names) {
            throw new \RuntimeException('The media worker answered no frames.');
        }

        $fingerprint = $localPath.'|'.\json_encode($fields);
        $frames = array();

        foreach ($names as $name) {
            $name = (string) $name;

            if (! MediaWorkerRemoteName::isSafe($name)) {
                throw new \RuntimeException(
                    'The media worker answered a frame name this host will not fetch: '.($name ?: '(empty)')
                );
            }

            $bytes = $this->worker->download(
                '/api/video/download/'.$name,
                \max(1, (int) \config('oos.media_worker.video_timeout', 300)),
            );

            $frames[] = array(
                'path' => MediaWorkerOutput::write($bytes, 'frame', 'jpg', $fingerprint.'|'.$name),
                'bytes' => \strlen($bytes),
            );
        }

        return array(
            'frames' => $frames,
            'frame_count' => \count($frames),
            'source_duration' => (float) ($body['source_duration'] ?? 0.0),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function supports(): bool
    {
        if (! $this->worker->isConfigured()) {
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

    /**
     * A bounded timestamp list.
     *
     * Non-numeric entries are dropped rather than refused: a list from a form is
     * allowed to contain the empty string, and dropping it cannot move a frame
     * that was asked for. A numeric value out of range is a mistake and is named.
     *
     * @return list<float>
     */
    private function timestamps(mixed $value): array
    {
        $raw = \is_array($value) ? $value : array($value);
        $timestamps = array();

        foreach ($raw as $item) {
            if (! \is_numeric($item)) {
                continue;
            }

            $number = (float) $item;

            if ($number < 0.0 || $number > (float) self::MAX_TIMESTAMP) {
                throw new \InvalidArgumentException(
                    'A timestamp must be between 0 and '.self::MAX_TIMESTAMP.' seconds.'
                );
            }

            $timestamps[] = $number;
        }

        return \array_slice($timestamps, 0, self::MAX_FRAMES);
    }

    private function number(mixed $value, float $min, float $max, string $label): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $number = (float) $value;

        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException(
                'The '.$label.' must be between '.$min.' and '.$max.'.'
            );
        }

        return $number;
    }

    private function integer(mixed $value, int $min, int $max, string $label): ?int
    {
        $number = $this->number($value, (float) $min, (float) $max, $label);

        return null === $number ? null : (int) \round($number);
    }
}
