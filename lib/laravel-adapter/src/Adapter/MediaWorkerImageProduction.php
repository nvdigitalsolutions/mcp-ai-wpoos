<?php

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\ImageProductionInterface;

/**
 * Image production over the media worker — Proposal 164 §164.6 phase 2.
 *
 * `MediaWorkerImageProcessing` answers the geometry half of the image family
 * (`/api/image/transform`). This adapter answers the pipeline half: re-encoding
 * for delivery, sharpening, upscaling, content-aware cropping, batches, and the
 * model-backed edits. All of it is Sharp or a provider the worker already holds,
 * so no second image pipeline is introduced into the port.
 *
 * ## `supports()` asks the matrix, `isAvailable()` asks the wire
 *
 * The two questions are different and an operator needs both. `isAvailable()`
 * says "a worker URL is configured" — cheap, no round trip. `supports()` says
 * "the worker I am talking to declares this operation", which is the authority
 * because these routes are newer than the worker that first shipped geometry.
 * A tool asks `supports()` before spending a request and can then name the gap.
 *
 * ## One route per shape, not one per tool
 *
 * `optimize()` and `batch()` are the same `/api/image/batch` call with one path
 * or many, because that route is the only one that reports **both** dimensions
 * *and* accepts AVIF. Asking `/optimize` for a height it does not return would
 * be a guess; asking a second route for metadata is a second round trip.
 *
 * ## Bytes are written here, and named by content
 *
 * Every route answers base64. `MediaWorkerOutput::persist()` decodes strictly,
 * writes under `oos.media_worker.output_dir`, and names the file by a
 * fingerprint of the inputs — so an agent that retries a turn rewrites one file
 * instead of filling the disk.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */
final class MediaWorkerImageProduction implements ImageProductionInterface
{
    /**
     * Operation => the worker capability that has to be declared.
     *
     * `resize_smart` rides `image_optimization`'s transform route but depends on
     * a **new argument** (`position`), so it gets its own key. That is the house
     * rule: a newer argument is as absent as a newer route, and a caller must be
     * able to find out without failing.
     */
    private const CAPABILITIES = array(
        'optimize'     => 'image_optimization',
        'encoding'     => 'image_batch_encoding',
        'resize_smart' => 'image_smart_crop',
        'enhance'      => 'image_enhancement',
        'upscale'      => 'image_upscaling',
        'batch'        => 'image_batch',
        'edit'         => 'image_editing',
        // Composition needs a provider that accepts two images in one turn, so
        // it is a narrower capability than `image_editing`: a worker holding
        // only a Replicate key can edit and cannot compose, and this map has to
        // reflect that rather than infer it from the edit key.
        'compose'      => 'image_composition',
        'generate'     => 'image_generation',
    );

    /**
     * Format slug => MIME type. The routes report a format, not a type.
     */
    private const MIME = array(
        'png'  => 'image/png',
        'jpeg' => 'image/jpeg',
        'jpg'  => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif'  => 'image/gif',
    );

    /**
     * Positions Sharp honours for `fit: cover`, mapped from our snake_case
     * vocabulary. Anything else falls back to `centre`, which is the historical
     * behaviour of a crop.
     */
    private const POSITIONS = array(
        'attention' => 'attention',
        'entropy'   => 'entropy',
        'centre'    => 'centre',
        'center'    => 'centre',
        'top'       => 'top',
        'bottom'    => 'bottom',
        'left'      => 'left',
        'right'     => 'right',
        'top_left'  => 'left top',
        'top_right' => 'right top',
        'bottom_left'  => 'left bottom',
        'bottom_right' => 'right bottom',
    );

    public function __construct(
        private readonly MediaWorkerClient $worker,
        private readonly MediaWorkerCapabilityProbe $probe,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function optimize(string $sourcePath, array $options = array()): array
    {
        $this->assertReadable($sourcePath);

        $format = $this->format($options['format'] ?? 'webp', array('webp', 'avif', 'jpeg', 'png', 'original'), 'webp');
        $quality = $this->quality($options['quality'] ?? 82);
        $width = (int) ($options['width'] ?? 0);

        $fields = array(
            'format' => $format,
            'quality' => $quality,
        );

        if ($width > 0) {
            $fields['width'] = $width;
        }

        if (isset($options['lossless'])) {
            $fields['lossless'] = $options['lossless'] ? 'true' : 'false';
        }

        if (isset($options['progressive'])) {
            $fields['progressive'] = $options['progressive'] ? 'true' : 'false';
        }

        $body = $this->worker->uploadFiles(
            '/api/image/batch',
            array($sourcePath),
            $fields,
            $this->timeout('optimize_timeout', 60),
        );

        $result = $this->firstBatchResult($body, $sourcePath);

        return $this->describe(
            $result,
            $this->write($result, 'optimised', $sourcePath.'|optimize|'.$format.'|'.$quality.'|'.$width),
            $format,
        ) + array(
            'savings_bytes' => (int) ($result['original_size'] ?? 0) - (int) ($result['optimized_size'] ?? 0),
            'savings_percent' => $this->percent($result['savings_percent'] ?? null),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function enhance(string $sourcePath, array $options = array()): array
    {
        $this->assertReadable($sourcePath);

        $fields = array(
            'strength' => $this->unit($options['strength'] ?? 1.0),
        );

        // `sharpen` is a boolean *or* a 0-1 amount in the worker contract. A
        // numeric value is forwarded as an amount; a boolean as a flag. The
        // default is **off**: an enhancement the caller did not ask for must not
        // be applied, or `enhancements` reports a list nobody requested.
        //
        // Amounts are formatted to two decimals so `1.0` is sent as `1.00`.
        // The route treats the bare strings `true` and `1` as flags, and `1` is a
        // legitimate amount, so the longer spelling is what keeps a full-strength
        // sharpen on the scalar path where `strength` applies to it.
        $sharpen = $options['sharpen'] ?? false;

        $fields['sharpen'] = \is_bool($sharpen)
            ? ($sharpen ? 'true' : 'false')
            : \number_format($this->unit($sharpen), 2, '.', '');

        foreach (array('contrast' => 1.0, 'saturation' => 1.0) as $key => $neutral) {
            $value = (float) ($options[$key] ?? $neutral);

            if (\abs($value - $neutral) > 0.0001) {
                $fields[$key] = $value;
            }
        }

        if (! empty($options['denoise'])) {
            $fields['denoise'] = 'true';
        }

        $body = $this->worker->uploadFile(
            '/api/image/enhance',
            $sourcePath,
            basename($sourcePath),
            $fields,
            $this->timeout('optimize_timeout', 60),
        );

        $format = (string) ($body['format'] ?? 'png');

        $path = MediaWorkerOutput::persist(
            $body['b64'] ?? null,
            'enhanced',
            $this->extension($format),
            MediaWorkerOutput::fingerprint(array($sourcePath, 'enhance', $fields)),
            'image',
        );

        return $this->metadata($sourcePath, $body, $path, $format) + array(
            'savings_bytes' => (int) ($body['savings_bytes'] ?? 0),
            'savings_percent' => $this->percent($body['savings_percent'] ?? null),
            'enhancements' => \array_values(\array_map('strval', (array) ($body['enhancements'] ?? array()))),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function upscale(string $sourcePath, int $factor = 2, string $kernel = 'lanczos3'): array
    {
        if (! \in_array($factor, array(2, 4, 8), true)) {
            throw new \InvalidArgumentException('The upscale factor must be 2, 4 or 8.');
        }

        $this->assertReadable($sourcePath);

        $body = $this->worker->uploadFile(
            '/api/image/upscale',
            $sourcePath,
            basename($sourcePath),
            array(
                'factor' => $factor,
                'kernel' => $kernel,
            ),
            $this->timeout('upscale_timeout', 120),
        );

        $format = (string) ($body['format'] ?? 'png');

        $path = MediaWorkerOutput::persist(
            $body['b64'] ?? null,
            'upscaled',
            $this->extension($format),
            $sourcePath.'|upscale|'.$factor.'|'.$kernel,
            'image',
        );

        return $this->metadata($sourcePath, $body, $path, $format) + array(
            'factor' => $factor,
            'kernel' => (string) ($body['kernel'] ?? $kernel),
            'method' => (string) ($body['upscale_method'] ?? $kernel),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function resizeSmart(string $sourcePath, int $width, int $height, string $position = 'attention'): array
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('The target width and height must be positive.');
        }

        $this->assertReadable($sourcePath);

        $resolved = self::POSITIONS[$position] ?? 'centre';

        $body = $this->worker->transform(
            $sourcePath,
            basename($sourcePath),
            array(
                array(
                    'type' => 'resize',
                    'width' => $width,
                    'height' => $height,
                    'fit' => 'cover',
                    'position' => $resolved,
                ),
            ),
            '',
            90,
        );

        $format = (string) ($body['format'] ?? 'png');

        $path = MediaWorkerOutput::persist(
            $body['b64'] ?? null,
            'smart-crop',
            $this->extension($format),
            $sourcePath.'|smart|'.$width.'|'.$height.'|'.$resolved,
            'image',
        );

        return $this->metadata($sourcePath, $body, $path, $format) + array(
            'position' => $resolved,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function batch(array $sourcePaths, array $options = array()): array
    {
        $paths = array_values(array_filter(
            \array_map(static fn ($path): string => \is_string($path) ? $path : '', $sourcePaths),
            static fn (string $path): bool => '' !== $path && \is_file($path),
        ));

        if (array() === $paths) {
            throw new \InvalidArgumentException('No readable image paths were given.');
        }

        $format = $this->format($options['format'] ?? 'webp', array('webp', 'avif', 'jpeg', 'png', 'original'), 'webp');
        $quality = $this->quality($options['quality'] ?? 82);
        $width = (int) ($options['width'] ?? 0);

        $fields = array(
            'format' => $format,
            'quality' => $quality,
        );

        if ($width > 0) {
            $fields['width'] = $width;
        }

        if (isset($options['lossless'])) {
            $fields['lossless'] = $options['lossless'] ? 'true' : 'false';
        }

        if (isset($options['progressive'])) {
            $fields['progressive'] = $options['progressive'] ? 'true' : 'false';
        }

        $body = $this->worker->uploadFiles(
            '/api/image/batch',
            $paths,
            $fields,
            $this->timeout('batch_timeout', 300),
        );

        $results = array();
        $totalSaved = 0;

        foreach ((array) ($body['results'] ?? array()) as $row) {
            if (! \is_array($row)) {
                continue;
            }

            $rowFormat = (string) ($row['format'] ?? $format);
            $original = (int) ($row['original_size'] ?? 0);
            $optimized = (int) ($row['optimized_size'] ?? 0);

            $path = MediaWorkerOutput::persist(
                $row['b64'] ?? null,
                'optimised',
                $this->extension($rowFormat),
                (string) ($row['filename'] ?? 'image').'|batch|'.$fields['format'].'|'.$quality.'|'.$width,
                'image',
            );

            $totalSaved += $original - $optimized;

            $results[] = array(
                'path' => $path,
                'filename' => (string) ($row['filename'] ?? ''),
                'width' => (int) ($row['width'] ?? 0),
                'height' => (int) ($row['height'] ?? 0),
                'format' => $rowFormat,
                'mime_type' => self::MIME[$rowFormat] ?? 'application/octet-stream',
                'bytes' => $optimized,
                'original_bytes' => $original,
                'savings_percent' => $this->percent($row['savings_percent'] ?? null),
            );
        }

        $errors = array();

        foreach ((array) ($body['errors'] ?? array()) as $row) {
            if (! \is_array($row)) {
                continue;
            }

            $errors[] = array(
                'filename' => (string) ($row['filename'] ?? ''),
                'error' => (string) ($row['error'] ?? 'unknown'),
            );
        }

        return array(
            'results' => $results,
            'errors' => $errors,
            'processed' => \count($results),
            'failed' => \count($errors),
            'total_bytes_saved' => $totalSaved,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function edit(string $sourcePath, string $operation, array $options = array()): array
    {
        if (! \in_array($operation, array('colorize', 'style_transfer'), true)) {
            throw new \InvalidArgumentException('Unsupported edit operation: '.$operation);
        }

        $this->assertReadable($sourcePath);

        $fields = array(
            'operation' => $operation,
            'provider' => (string) ($options['provider'] ?? 'auto'),
        );

        foreach (array('style', 'prompt') as $key) {
            $value = \trim((string) ($options[$key] ?? ''));

            if ('' !== $value) {
                $fields[$key] = $value;
            }
        }

        $body = $this->worker->uploadFile(
            '/api/image/edit',
            $sourcePath,
            basename($sourcePath),
            $fields,
            $this->timeout('edit_timeout', 120),
        );

        $format = (string) ($body['format'] ?? 'png');

        $path = MediaWorkerOutput::persist(
            $body['b64'] ?? null,
            'edited',
            $this->extension($format),
            MediaWorkerOutput::fingerprint(array($sourcePath, 'edit', $fields)),
            'image',
        );

        return $this->metadata($sourcePath, $body, $path, $format) + array(
            'operation' => $operation,
            'provider' => (string) ($body['provider'] ?? $fields['provider']),
            'model' => (string) ($body['model'] ?? ''),
            'style' => null === ($body['style'] ?? null) ? null : (string) $body['style'],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function compose(string $scenePath, string $productPath, array $options = array()): array
    {
        $provider = \strtolower(\trim((string) ($options['provider'] ?? 'auto')));

        if (! \in_array($provider, array('auto', 'gemini', 'openai'), true)) {
            throw new \InvalidArgumentException('Unsupported compose provider: '.$provider);
        }

        $this->assertReadable($scenePath);
        $this->assertReadable($productPath);

        $fields = array('provider' => $provider);

        $prompt = \trim((string) ($options['prompt'] ?? ''));

        if ('' !== $prompt) {
            $fields['prompt'] = $prompt;
        }

        // Scene first, product second. The order is the instruction, and the
        // worker sorts `files[n]` by the index in the name rather than trusting
        // arrival order, so `uploadFiles()`'s sequence is what carries it.
        $body = $this->worker->uploadFiles(
            '/api/image/compose',
            array($scenePath, $productPath),
            $fields,
            $this->timeout('compose_timeout', 180),
        );

        $format = (string) ($body['format'] ?? 'png');

        $path = MediaWorkerOutput::persist(
            $body['b64'] ?? null,
            'composed',
            $this->extension($format),
            MediaWorkerOutput::fingerprint(array($scenePath, $productPath, 'compose', $fields)),
            'image',
        );

        // No `original_bytes`: two sources do not add up to anything meaningful.
        // The worker's `bytes` is the output's own count, which is what a caller
        // reporting savings or quota needs.
        return array(
            'path' => $path,
            'width' => (int) ($body['width'] ?? 0),
            'height' => (int) ($body['height'] ?? 0),
            'format' => $format,
            'mime_type' => self::MIME[$format] ?? 'application/octet-stream',
            'bytes' => (int) ($body['bytes'] ?? (\is_file($path) ? \filesize($path) : 0)),
            'scene_bytes' => (int) (\is_file($scenePath) ? \filesize($scenePath) : 0),
            'product_bytes' => (int) (\is_file($productPath) ? \filesize($productPath) : 0),
            'operation' => 'compose',
            'provider' => (string) ($body['provider'] ?? $provider),
            'model' => (string) ($body['model'] ?? ''),
            'prompt' => (string) ($body['prompt'] ?? $prompt),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function generate(string $prompt, array $options = array()): array
    {
        $prompt = \trim($prompt);

        if ('' === $prompt) {
            throw new \InvalidArgumentException('A prompt is required.');
        }

        $provider = \strtolower(\trim((string) ($options['provider'] ?? 'auto')));

        if (! \in_array($provider, array('auto', 'gemini', 'openai'), true)) {
            throw new \InvalidArgumentException('Unsupported generate provider: '.$provider);
        }

        $aspectRatio = \strtolower(\trim((string) ($options['aspect_ratio'] ?? '3:2')));

        $request = array(
            'prompt' => $prompt,
            'provider' => $provider,
            'size' => $this->generationSize($aspectRatio),
            'count' => 1,
        );

        // `auto` is resolved by the worker, which is the only side that can see
        // the keys. Naming a model here would name the wrong provider's model
        // whenever the resolution went the other way.
        $model = \trim((string) ($options['model'] ?? ''));

        if ('' !== $model) {
            $request['model'] = $model;
        } elseif ('auto' !== $provider) {
            $request['model'] = 'gemini' === $provider ? 'gemini-2.5-flash-image' : 'dall-e-3';
        }

        $body = $this->worker->postJson(
            '/api/image/generate',
            $request,
            $this->timeout('generate_timeout', 180),
        );

        $images = (array) ($body['images'] ?? array());
        $image = (array) ($images[0] ?? array());
        $bytes = $this->generatedBytes($image);

        if ('' === $bytes) {
            throw new \RuntimeException('The media worker returned no image for the generation.');
        }

        $format = $this->formatFromBytes($bytes, $image['mime'] ?? null);

        $path = MediaWorkerOutput::persist(
            \base64_encode($bytes),
            'generated',
            $this->extension($format),
            MediaWorkerOutput::fingerprint(array($prompt, 'generate', $request)),
            'image',
        );

        // The worker may not know the geometry (OpenAI answers a URL), so read
        // it from the bytes that were actually stored. `getimagesize()` is part
        // of ext/standard and does not need GD or Imagick.
        $size = @\getimagesize($path);

        $width = \is_array($size) ? (int) $size[0] : null;
        $height = \is_array($size) ? (int) $size[1] : null;

        if (null === $width && isset($image['width'])) {
            $width = (int) $image['width'];
        }

        if (null === $height && isset($image['height'])) {
            $height = (int) $image['height'];
        }

        return array(
            'path' => $path,
            'width' => $width,
            'height' => $height,
            'format' => $format,
            'mime_type' => self::MIME[$format] ?? 'application/octet-stream',
            'bytes' => \strlen($bytes),
            'operation' => 'generate',
            'provider' => (string) ($body['provider'] ?? $provider),
            'model' => (string) ($image['model'] ?? ($body['model'] ?? '')),
            'prompt' => (string) ($body['prompt'] ?? $prompt),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function supports(string $operation): bool
    {
        $capability = self::CAPABILITIES[$operation] ?? null;

        if (null === $capability || ! $this->worker->isConfigured()) {
            return false;
        }

        return $this->probe->report()->supports($capability);
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return $this->worker->isConfigured();
    }

    /**
     * The bytes of a generation entry, whichever shape the provider answered.
     *
     * Gemini and Imagen answer inline base64; OpenAI answers a short-lived URL.
     * Both are handled here so the tool above sees one representation.
     *
     * @param array<string, mixed> $image
     */
    private function generatedBytes(array $image): string
    {
        $encoded = $image['b64_json'] ?? null;

        if (\is_string($encoded) && '' !== $encoded) {
            $bytes = \base64_decode($encoded, true);

            if (! \is_string($bytes) || '' === $bytes) {
                throw new \RuntimeException('The media worker returned undecodable image data.');
            }

            return $bytes;
        }

        $url = $image['url'] ?? null;

        return \is_string($url) && '' !== $url ? $this->fetchGeneratedUrl($url) : '';
    }

    /**
     * Download an image the worker's provider hosted.
     *
     * The URL is the worker's answer, not caller input, so it is not an SSRF
     * surface introduced here — but only http(s) is followed, and the response
     * must be non-empty, because a torn download would be stored as a corrupt
     * image and only fail when someone opened it.
     */
    private function fetchGeneratedUrl(string $url): string
    {
        $scheme = \strtolower((string) \parse_url($url, PHP_URL_SCHEME));

        if (! \in_array($scheme, array('http', 'https'), true)) {
            throw new \RuntimeException('The provider returned an image URL this adapter will not fetch.');
        }

        try {
            $response = Http::timeout($this->timeout('generate_timeout', 180))->get($url);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not download the generated image: '.$e->getMessage(), 0, $e);
        }

        if (! $response->ok() || '' === $response->body()) {
            throw new \RuntimeException('The generated image URL answered HTTP '.$response->status().'.');
        }

        return $response->body();
    }

    /**
     * The format of the bytes themselves, not what a route claimed.
     *
     * Magic bytes first because they are the bytes that will be stored; the
     * declared MIME is only a fallback, and a format nothing recognises is
     * named `png` so the file has a usable extension rather than none.
     */
    private function formatFromBytes(string $bytes, mixed $mime): string
    {
        $header = \substr($bytes, 0, 12);

        if (0 === \strncmp($header, "\x89PNG", 4)) {
            return 'png';
        }

        if (0 === \strncmp($header, "\xFF\xD8\xFF", 3)) {
            return 'jpeg';
        }

        if ('RIFF' === \substr($header, 0, 4) && 'WEBP' === \substr($header, 8, 4)) {
            return 'webp';
        }

        if (0 === \strncmp($header, 'GIF8', 4)) {
            return 'gif';
        }

        if (\is_string($mime) && \str_starts_with($mime, 'image/')) {
            return $this->format(\substr($mime, 6), array('png', 'jpeg', 'webp', 'avif', 'gif'), 'png');
        }

        return 'png';
    }

    /**
     * The canvas to ask for, in the intersection of both providers' vocabularies.
     *
     * DALL·E 3 accepts only 1024x1024, 1792x1024 and 1024x1792, so those three
     * serve every ratio; Gemini treats the size as a text hint and follows the
     * stated dimensions, so the same three work there. `4:5` rounds to square
     * because it is the nearest of the three to 0.8, which is the same choice
     * the WordPress tool documented.
     */
    private function generationSize(string $aspectRatio): string
    {
        $map = array(
            '1:1' => '1024x1024',
            '4:5' => '1024x1024',
            '16:9' => '1792x1024',
            '9:16' => '1024x1792',
            '3:2' => '1792x1024',
            '2:3' => '1024x1792',
            'auto' => '1024x1024',
        );

        return $map[$aspectRatio] ?? '1024x1024';
    }

    /**
     * The single result a one-file batch must contain.
     *
     * A batch route reports per-file failures in `errors` and still answers 200,
     * so "no results" is a failure that has to be named rather than read as an
     * empty success.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function firstBatchResult(array $body, string $sourcePath): array
    {
        $results = (array) ($body['results'] ?? array());
        $first = $results[0] ?? null;

        if (\is_array($first)) {
            return $first;
        }

        $errors = (array) ($body['errors'] ?? array());
        $reason = \is_array($errors[0] ?? null)
            ? (string) ($errors[0]['error'] ?? 'unknown')
            : 'the worker returned no result';

        throw new \RuntimeException(
            'The media worker could not process '.basename($sourcePath).': '.$reason
        );
    }

    /**
     * Decode one batch row's bytes and return the path they were written to.
     *
     * @param array<string, mixed> $row
     */
    private function write(array $row, string $prefix, string $fingerprint): string
    {
        return MediaWorkerOutput::persist(
            $row['b64'] ?? null,
            $prefix,
            $this->extension((string) ($row['format'] ?? 'png')),
            $fingerprint,
            'image',
        );
    }

    /**
     * The common shape every operation reports.
     *
     * @param array<string, mixed> $body
     * @return array{
     *     path: string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     mime_type: string,
     *     bytes: int,
     *     original_bytes: int
     * }
     */
    private function metadata(string $sourcePath, array $body, string $path, string $format): array
    {
        return array(
            'path' => $path,
            'width' => (int) ($body['width'] ?? 0),
            'height' => (int) ($body['height'] ?? 0),
            'format' => $format,
            'mime_type' => self::MIME[$format] ?? 'application/octet-stream',
            'bytes' => (int) ($body['optimized_size'] ?? (\is_file($path) ? \filesize($path) : 0)),
            'original_bytes' => (int) ($body['original_size'] ?? (\is_file($sourcePath) ? \filesize($sourcePath) : 0)),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     path: string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     mime_type: string,
     *     bytes: int,
     *     original_bytes: int
     * }
     */
    private function describe(array $row, string $path, string $format): array
    {
        $rowFormat = (string) ($row['format'] ?? $format);

        return array(
            'path' => $path,
            'width' => (int) ($row['width'] ?? 0),
            'height' => (int) ($row['height'] ?? 0),
            'format' => $rowFormat,
            'mime_type' => self::MIME[$rowFormat] ?? 'application/octet-stream',
            'bytes' => (int) ($row['optimized_size'] ?? 0),
            'original_bytes' => (int) ($row['original_size'] ?? 0),
        );
    }

    /**
     * Normalise an encoder slug, refusing anything the route would silently swap.
     *
     * @param list<string> $allowed
     */
    private function format(mixed $value, array $allowed, string $default): string
    {
        $slug = \strtolower(\trim((string) $value));

        if ('jpg' === $slug) {
            $slug = 'jpeg';
        }

        if ('' === $slug) {
            return $default;
        }

        return \in_array($slug, $allowed, true) ? $slug : $default;
    }

    private function quality(mixed $value): int
    {
        $quality = (int) $value;

        return \max(1, \min(100, 0 === $quality ? 82 : $quality));
    }

    private function unit(mixed $value): float
    {
        return \max(0.0, \min(1.0, (float) $value));
    }

    /**
     * The worker reports savings as `"12.3%"`; callers want a number.
     */
    private function percent(mixed $value): float
    {
        if (\is_numeric($value)) {
            return (float) $value;
        }

        return (float) \rtrim((string) $value, '%');
    }

    /**
     * A filename extension for a format slug.
     */
    private function extension(string $format): string
    {
        return 'jpeg' === $format ? 'jpg' : $format;
    }

    private function timeout(string $key, int $fallback): int
    {
        return \max(1, (int) \config('oos.media_worker.'.$key, $fallback));
    }

    private function assertReadable(string $sourcePath): void
    {
        if ('' === \trim($sourcePath)) {
            throw new \InvalidArgumentException('An image path is required.');
        }

        if (! \is_file($sourcePath) || ! \is_readable($sourcePath)) {
            throw new \InvalidArgumentException('The image does not exist or is not readable: '.$sourcePath);
        }
    }
}
