<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\VideoCompositionInterface;

/**
 * Multi-input video operations over the media worker — Proposal 164 §164.19.
 *
 * `/api/video/compose` takes an ordered `files` array and an `operation`. It is
 * a separate endpoint from `/api/video/process` because that route accepts one
 * upload; widening it would have changed the contract every existing operation
 * depends on.
 *
 * ## The overlay recipe
 *
 * `[1:v]format=rgba,scale=<px>:-2,colorchannelmixer=aa=<opacity>[wm];[0:v][wm]overlay=…`
 *
 * `format=rgba` comes first because a JPEG mark has no alpha channel and
 * `colorchannelmixer` would have nothing to multiply. The mark's pixel width is
 * a fraction of the *video's* width, which is why the route probes the video
 * before building the graph — `overlay` can compute positions from its own
 * inputs, but not the `scale` that precedes it.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */
final class MediaWorkerVideoComposition implements VideoCompositionInterface
{
    /**
     * The shapes an overlay can take. `merge` will join this list on the same
     * endpoint rather than getting a contract of its own.
     */
    private const OPERATIONS = array('watermark', 'merge');

    private const POSITIONS = array('top-left', 'top-right', 'bottom-left', 'bottom-right', 'center');

    private const FORMATS = array('mp4', 'webm', 'mov', 'mkv');

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
    public function compose(string $operation, array $inputs, array $options = array()): array
    {
        if (! \in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException(
                'Unsupported composition: '.$operation.'. Allowed: '.\implode(', ', self::OPERATIONS).'.'
            );
        }

        $inputs = \array_values($inputs);

        if (\count($inputs) < 2) {
            throw new \InvalidArgumentException(
                'merge' === $operation
                    ? 'A merge needs at least two inputs, in the order to join them.'
                    : 'A watermark needs two inputs: the video, then the mark.'
            );
        }

        foreach ($inputs as $input) {
            if (! \is_string($input) || '' === \trim($input) || ! \is_file($input) || ! \is_readable($input)) {
                throw new \InvalidArgumentException('An input does not exist or is not readable: '.(\is_string($input) ? $input : ''));
            }
        }

        $format = $this->format($options['format'] ?? 'mp4');

        // Only the fields an operation reads are sent, so a merge does not
        // carry a position nobody will look at.
        $fields = array(
            'operation' => $operation,
            'format' => $format,
        );

        if ('watermark' === $operation) {
            $fields['position'] = $this->position($options['position'] ?? 'bottom-right');
            $fields['opacity'] = $this->number($options['opacity'] ?? 0.7, 0.0, 1.0, 'opacity');
            $fields['scale'] = $this->number($options['scale'] ?? 0.2, 0.01, 1.0, 'scale');
        }

        try {
            $body = $this->worker->uploadFiles(
                '/api/video/compose',
                $inputs,
                $fields,
                \max(1, (int) \config('oos.media_worker.video_timeout', 300)),
            );
        } catch (MediaWorkerRefusedException $e) {
            // The route refused the inputs — clips that do not match, a
            // container the codec cannot be copied into. This contract reports
            // those as a caller error; the engine's own failures stay runtime.
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $remote = (string) ($body['output_file'] ?? '');

        if (! MediaWorkerRemoteName::isSafe($remote)) {
            throw new \RuntimeException(
                'The media worker answered an output name this host will not fetch: '.($remote ?: '(empty)')
            );
        }

        $bytes = $this->worker->download(
            '/api/video/download/'.$remote,
            \max(1, (int) \config('oos.media_worker.video_timeout', 300)),
        );

        $path = MediaWorkerOutput::write(
            $bytes,
            'video-'.$operation,
            $this->extension($format),
            \implode('|', $inputs).'|'.$operation.'|'.\json_encode($fields),
        );

        // The base input is the size a caller can reason about; a watermark
        // re-encode can grow the file, and a negative saving says so.
        $original = (int) \filesize($inputs[0]);
        $size = \strlen($bytes);

        $result = array(
            'path' => $path,
            'operation' => $operation,
            'format' => $format,
            'bytes' => $size,
            'original_bytes' => 'merge' === $operation ? $this->totalSize($inputs) : $original,
            'savings_percent' => 0.0,
        );

        // A merge copies, so it has no "original" of its own — the comparison
        // that means something is the sum of its inputs against the result.
        if ('merge' === $operation) {
            $total = $result['original_bytes'];
            $result['savings_percent'] = $total > 0 ? (($total - $size) / $total) * 100 : 0.0;
        } elseif ($original > 0) {
            $result['savings_percent'] = (($original - $size) / $original) * 100;
        }

        if (isset($body['source_count'])) {
            $result['source_count'] = (int) $body['source_count'];
        }

        if (isset($body['reencoded'])) {
            $result['reencoded'] = (bool) $body['reencoded'];
        }

        return $result;
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

    private function position(mixed $value): string
    {
        $slug = \strtolower(\trim((string) $value));

        if ('' === $slug) {
            return 'bottom-right';
        }

        if (! \in_array($slug, self::POSITIONS, true)) {
            throw new \InvalidArgumentException(
                'A position must be one of: '.\implode(', ', self::POSITIONS).'.'
            );
        }

        return $slug;
    }

    private function format(mixed $value): string
    {
        $slug = \strtolower(\trim((string) $value));

        if ('' === $slug) {
            return 'mp4';
        }

        if (! \in_array($slug, self::FORMATS, true)) {
            throw new \InvalidArgumentException(
                'The watermark output must be one of: '.\implode(', ', self::FORMATS).'.'
            );
        }

        return $slug;
    }

    private function number(mixed $value, float $min, float $max, string $label): float
    {
        $number = (float) $value;

        if ($number < $min || $number > $max) {
            throw new \InvalidArgumentException(
                'The '.$label.' must be between '.$min.' and '.$max.'.'
            );
        }

        return $number;
    }

    private function extension(string $format): string
    {
        return 'webm' === $format ? 'webm' : $format;
    }

    /**
     * The sum of the inputs, which is the only "original" a merge has.
     *
     * @param list<string> $inputs
     */
    private function totalSize(array $inputs): int
    {
        $total = 0;

        foreach ($inputs as $input) {
            $total += (int) \filesize($input);
        }

        return $total;
    }
}
