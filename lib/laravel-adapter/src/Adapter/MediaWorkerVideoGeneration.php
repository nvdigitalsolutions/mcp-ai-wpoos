<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\VideoGenerationInterface;

/**
 * Video generation over the media worker — Proposal 164 §164.131.
 *
 * The worker's route blocks by default and can spend ten minutes inside one
 * request. This adapter never does: `submit()` sends `wait: false` so the call
 * returns when Replicate has the prediction, and `status()` asks the worker for
 * the state. A queued job owns the waiting, one short run per poll.
 *
 * ## The frame crosses as a data URI
 *
 * Replicate accepts file inputs as `data:` URIs, so a reference frame is read
 * from a local path, sniffed by magic bytes and inlined. Nothing here needs the
 * worker to be publicly reachable, which a hosted URL from this host could not
 * guarantee. The worker bounds and re-encodes the frame; this side only refuses
 * what the route's JSON body cannot carry.
 *
 * ## Two refusals worth naming
 *
 * A prompt-only call with no model is refused here rather than at the provider:
 * the worker's default model animates a frame and cannot read a prompt, so the
 * caller would pay a round trip to learn something this adapter already knows.
 *
 * @package Nvoos\Laravel
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */
final class MediaWorkerVideoGeneration implements VideoGenerationInterface
{
    /**
     * The route's JSON body is capped at 10 MB, and base64 adds a third.
     */
    private const MAX_REFERENCE_BYTES = 6000000;

    /**
     * Provider states that mean the render has stopped.
     */
    private const TERMINAL = array('succeeded', 'failed', 'canceled');

    public function __construct(
        private readonly MediaWorkerClient $worker,
        private readonly MediaWorkerCapabilityProbe $probe,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function submit(string $prompt, array $options = array()): array
    {
        $prompt = \trim($prompt);
        $reference = $this->referenceDataUri(
            isset($options['reference_path']) ? (string) $options['reference_path'] : null
        );

        if ('' === $prompt && null === $reference) {
            throw new \InvalidArgumentException('A prompt or a reference frame is required.');
        }

        $model = \trim((string) ($options['model'] ?? ''));

        if (null === $reference && '' === $model) {
            throw new \InvalidArgumentException(
                'A text-to-video call must name a model: the default model animates a reference frame. '
                .'Add reference_path, or choose a text-to-video model such as zeroscope-v2.'
            );
        }

        $body = array(
            // The whole point of this adapter: never block for the render.
            'wait' => false,
            'prompt' => $prompt,
        );

        if ('' !== $model) {
            $body['model'] = $model;
        }

        if (null !== $reference) {
            $body['reference_image'] = $reference;
        }

        $duration = isset($options['duration_seconds']) ? (int) $options['duration_seconds'] : 0;

        if ($duration > 0) {
            $body['duration'] = $duration;
        }

        if (isset($options['fps'])) {
            $body['fps'] = \max(1, (int) $options['fps']);
        }

        if (isset($options['seed']) && (int) $options['seed'] > 0) {
            $body['seed'] = (int) $options['seed'];
        }

        $negative = \trim((string) ($options['negative_prompt'] ?? ''));

        if ('' !== $negative) {
            $body['negative_prompt'] = $negative;
        }

        foreach (array('width', 'height') as $key) {
            if (isset($options[$key]) && (int) $options[$key] > 0) {
                $body[$key] = (int) $options[$key];
            }
        }

        $response = $this->worker->postJson(
            '/api/video/generate',
            $body,
            $this->timeout('video_submit_timeout', 120),
        );

        $prediction = (string) ($response['prediction_id'] ?? '');

        if ('' === $prediction) {
            throw new \RuntimeException('The media worker returned no prediction id.');
        }

        return array(
            'prediction_id' => $prediction,
            'provider' => 'replicate',
            'model' => (string) ($response['model'] ?? $model),
            'status' => (string) ($response['status'] ?? 'starting'),
            'prompt' => (string) ($response['prompt'] ?? $prompt),
            'duration_seconds' => (int) ($response['duration'] ?? ($body['duration'] ?? 0)),
            'fps' => (int) ($response['fps'] ?? ($body['fps'] ?? 0)),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function status(string $predictionId): array
    {
        $prediction = \trim($predictionId);

        if ('' === $prediction) {
            throw new \InvalidArgumentException('A prediction id is required.');
        }

        $response = $this->worker->getJson(
            '/api/video/prediction/'.\rawurlencode($prediction),
            $this->timeout('video_status_timeout', 30),
        );

        $status = \strtolower(\trim((string) ($response['status'] ?? 'unknown')));
        $url = $response['video_url'] ?? null;
        $error = $response['error'] ?? null;

        return array(
            'prediction_id' => (string) ($response['id'] ?? $prediction),
            'status' => $status,
            'terminal' => \in_array($status, self::TERMINAL, true),
            'video_url' => \is_string($url) && '' !== $url ? $url : null,
            'error' => (null !== $error && \is_scalar($error) && '' !== (string) $error) ? (string) $error : null,
            'model' => (string) ($response['model'] ?? ''),
            'metrics' => \is_array($response['metrics'] ?? null) ? $response['metrics'] : array(),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function supports(string $operation): bool
    {
        if ('generate' !== $operation || ! $this->worker->isConfigured()) {
            return false;
        }

        return $this->probe->report()->supports('video_generation');
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return $this->worker->isConfigured();
    }

    /**
     * Read a reference frame and inline it as a data URI.
     *
     * The type is sniffed from the bytes, not from the extension or a caller
     * claim: the provider is told one media type and a wrong one fails there.
     */
    private function referenceDataUri(?string $path): ?string
    {
        $path = \trim((string) $path);

        if ('' === $path) {
            return null;
        }

        if (! \is_file($path) || ! \is_readable($path)) {
            throw new \InvalidArgumentException('The reference frame does not exist or is not readable: '.$path);
        }

        $bytes = @\file_get_contents($path);

        if (! \is_string($bytes) || '' === $bytes) {
            throw new \InvalidArgumentException('The reference frame could not be read: '.$path);
        }

        $size = \strlen($bytes);

        if ($size > self::MAX_REFERENCE_BYTES) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'The reference frame is %d bytes; the route accepts at most %d. Resize it first.',
                    $size,
                    self::MAX_REFERENCE_BYTES
                )
            );
        }

        $mime = $this->referenceMime($bytes);

        if (null === $mime) {
            throw new \InvalidArgumentException('The reference frame must be a PNG, JPEG or WebP image.');
        }

        return 'data:'.$mime.';base64,'.\base64_encode($bytes);
    }

    /**
     * The media type of the bytes themselves.
     */
    private function referenceMime(string $bytes): ?string
    {
        $header = \substr($bytes, 0, 12);

        if (0 === \strncmp($header, "\x89PNG", 4)) {
            return 'image/png';
        }

        if (0 === \strncmp($header, "\xFF\xD8\xFF", 3)) {
            return 'image/jpeg';
        }

        if ('RIFF' === \substr($header, 0, 4) && 'WEBP' === \substr($header, 8, 4)) {
            return 'image/webp';
        }

        return null;
    }

    private function timeout(string $key, int $fallback): int
    {
        return \max(1, (int) \config('oos.media_worker.'.$key, $fallback));
    }
}
