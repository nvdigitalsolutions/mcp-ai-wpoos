<?php

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\MediaWorkerClientInterface;
use Throwable;

/**
 * The media worker sidecar (`addons/media-worker`).
 *
 * The same service the WordPress plugin already calls, so this runtime gets
 * Sharp-based processing — optimisation, enhancement, video/PDF/document
 * handling — without a PHP image pipeline of its own. The contract is the
 * sidecar's, not a new one:
 *
 *   GET  /api/health          → { status: 'ok', version, uptime, memory }
 *   POST /api/image/optimize  → { savings_bytes, savings_percent, b64, … }
 *
 * Auth mirrors `WP_MCP_AI_Media_Worker_Client`: `X-Site-Token` (single- or
 * multi-tenant, decided by the worker's own `SITE_TOKENS`) and `X-Site-Url`.
 *
 * A worker that is down is **not** an exception: `health()` reports
 * `available: false` so the command centre can still show local library
 * numbers beside an honest "worker unreachable" line.
 */
final class MediaWorkerClient implements MediaWorkerClientInterface
{
    /**
     * Base URL, without a trailing slash. Empty when unconfigured.
     */
    public function url(): string
    {
        return rtrim((string) config('oos.media_worker.url', ''), '/');
    }

    /**
     * Whether this runtime has a worker to talk to.
     *
     * The canonical spelling, from {@see MediaWorkerClientInterface}. The
     * shorter `configured()` predates the contract and stays as a delegate so
     * the existing call sites did not need a rename for a spelling.
     */
    public function isConfigured(): bool
    {
        return '' !== $this->url();
    }

    /**
     * Whether this runtime has a worker to talk to.
     *
     * @deprecated Use {@see isConfigured()}; this is the pre-contract spelling.
     */
    public function configured(): bool
    {
        return $this->isConfigured();
    }

    /**
     * The worker's own liveness.
     *
     * @return array{available: bool, version: string|null, uptime: float|null}
     */
    public function health(): array
    {
        $unavailable = ['available' => false, 'version' => null, 'uptime' => null];

        if (! $this->configured()) {
            return $unavailable;
        }

        try {
            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->withHeaders($this->headers())
                ->get($this->url().'/api/health');
        } catch (Throwable) {
            // Timeout, DNS failure, refused connection: all mean "not
            // available", not "the request was wrong".
            return $unavailable;
        }

        $body = $response->json();

        if (! $response->ok() || ! is_array($body) || 'ok' !== ($body['status'] ?? null)) {
            return $unavailable;
        }

        return [
            'available' => true,
            'version' => isset($body['version']) ? (string) $body['version'] : null,
            'uptime' => isset($body['uptime']) ? (float) $body['uptime'] : null,
        ];
    }

    /**
     * The worker's capability matrix, from `GET /api/health/full`.
     *
     * This is the probe besides `/api/health`: that one answers "is it up",
     * this one answers "can it do the thing". A managed Node host can be up and
     * still have no `ffmpeg`, so the two questions have different answers.
     *
     * An empty array means the worker did not answer — unconfigured,
     * unreachable, or erroring. It is not a claim that the worker can do
     * nothing, and `MediaWorkerCapabilityReport` reports that distinction as
     * `unknown` rather than `unavailable`.
     *
     * Deliberately uncached: this is a transport, and caching here would make
     * `MediaWorkerCapabilityProbe::report( fresh: true )` a lie — the probe holds
     * one client instance, so a memoised matrix would outlive every refresh. The
     * probe owns the TTL; the client asks.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        if (! $this->configured()) {
            return [];
        }

        try {
            $response = Http::timeout($this->capabilitiesTimeout())
                ->acceptJson()
                ->withHeaders($this->headers())
                ->get($this->url().'/api/health/full');
        } catch (Throwable) {
            return [];
        }

        $body = $response->json();

        if (! $response->ok() || ! is_array($body) || ! is_array($body['capabilities'] ?? null)) {
            return [];
        }

        $capabilities = [];

        foreach ($body['capabilities'] as $key => $value) {
            if (is_string($key) && '' !== $key) {
                $capabilities[$key] = (bool) $value;
            }
        }

        return $capabilities;
    }

    /**
     * Optimise one image through the worker.
     *
     * @param string $localPath Absolute path to the source file.
     * @param string $filename  Original filename, for the multipart part.
     * @param string $format    Output format (webp, avif, jpeg, png).
     * @param int    $quality   Encoder quality, 1-100.
     *
     * @return array{optimized_size: int, savings_bytes: int, b64: string}
     *
     * @throws \RuntimeException When unconfigured, unreachable, or the worker
     *                           answers with an error. The caller is a queued
     *                           job, and a job must fail loudly to retry.
     */
    public function optimize(string $localPath, string $filename, string $format = 'webp', int $quality = 80): array
    {
        if (! $this->configured()) {
            throw new \RuntimeException('The media worker is not configured.');
        }

        $stream = is_file($localPath) ? fopen($localPath, 'rb') : false;

        if (false === $stream) {
            throw new \RuntimeException('Source file is not readable: '.$localPath);
        }

        try {
            $response = Http::timeout(max(1, (int) config('oos.media_worker.optimize_timeout', 60)))
                ->acceptJson()
                ->withHeaders($this->headers())
                ->attach('file', $stream, $filename)
                ->post($this->url().'/api/image/optimize', [
                    'format' => $format,
                    'quality' => max(1, min(100, $quality)),
                ]);
        } catch (Throwable $e) {
            throw new \RuntimeException('Media worker request failed: '.$e->getMessage(), 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $body = $response->json();

        if (! $response->ok() || ! is_array($body)) {
            $reason = is_array($body) && isset($body['error'])
                ? (string) $body['error']
                : 'HTTP '.$response->status();

            throw new \RuntimeException('Media worker error: '.$reason);
        }

        foreach (['b64', 'optimized_size', 'savings_bytes'] as $required) {
            if (! array_key_exists($required, $body)) {
                throw new \RuntimeException('Media worker response is missing `'.$required.'`.');
            }
        }

        return [
            'optimized_size' => (int) $body['optimized_size'],
            'savings_bytes' => (int) $body['savings_bytes'],
            'b64' => (string) $body['b64'],
        ];
    }

    /**
     * Run geometry operations through the worker (Proposal 149 §160).
     *
     * The operations are the sidecar's vocabulary, not a PHP one:
     * `{type: resize|crop|rotate, …}` applied in order, then one encoder.
     *
     * @param string                    $localPath  Absolute path to the source file.
     * @param string                    $filename   Original filename, for the multipart part.
     * @param list<array<string, mixed>> $operations Transform operations.
     * @param string                    $format     Output format; empty keeps the input format.
     * @param int                       $quality    Encoder quality, 1-100.
     *
     * @return array{width: int, height: int, format: string, mime_type: string, bytes: int, b64: string}
     *
     * @throws \RuntimeException When unconfigured, unreachable, or the worker
     *                           answers with an error.
     */
    public function transform(
        string $localPath,
        string $filename,
        array $operations,
        string $format = '',
        int $quality = 90,
    ): array {
        $encoded = json_encode($operations, JSON_UNESCAPED_SLASHES);

        $body = $this->postFile(
            '/api/image/transform',
            $localPath,
            $filename,
            array(
                'operations' => false === $encoded ? '[]' : $encoded,
                'format' => $format,
                'quality' => max(1, min(100, $quality)),
            ),
            $this->transformTimeout(),
        );

        foreach (array('b64', 'width', 'height', 'format', 'mime_type', 'bytes') as $required) {
            if (! array_key_exists($required, $body)) {
                throw new \RuntimeException('Media worker response is missing `'.$required.'`.');
            }
        }

        return array(
            'width' => (int) $body['width'],
            'height' => (int) $body['height'],
            'format' => (string) $body['format'],
            'mime_type' => (string) $body['mime_type'],
            'bytes' => (int) $body['bytes'],
            'b64' => (string) $body['b64'],
        );
    }

    /**
     * Read an image's metadata through the worker.
     *
     * @param string $localPath Absolute path to the source file.
     * @param string $filename  Original filename, for the multipart part.
     *
     * @return array{width: int, height: int, format: string, mime_type: string, bytes: int}
     *
     * @throws \RuntimeException When unconfigured, unreachable, or the worker
     *                           answers with an error.
     */
    public function info(string $localPath, string $filename): array
    {
        $body = $this->postFile(
            '/api/image/info',
            $localPath,
            $filename,
            array(),
            $this->transformTimeout(),
        );

        foreach (array('width', 'height', 'format', 'mime_type', 'bytes') as $required) {
            if (! array_key_exists($required, $body)) {
                throw new \RuntimeException('Media worker response is missing `'.$required.'`.');
            }
        }

        return array(
            'width' => (int) $body['width'],
            'height' => (int) $body['height'],
            'format' => (string) $body['format'],
            'mime_type' => (string) $body['mime_type'],
            'bytes' => (int) $body['bytes'],
        );
    }

    /**
     * POST a JSON body to a worker endpoint and decode the JSON envelope.
     *
     * The worker's own routes are JSON-in/JSON-out (`/api/pdf/generate`,
     * `/api/document/*`, `/api/email/*`), so this is the counterpart to
     * `postFile()`. It exists so the adapter for each domain does not need its
     * own transport: one place raises `RuntimeException`, one place reads the
     * worker's `error` field, and the not-configured message stays identical.
     *
     * Proposal 164 §164.4.2.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     *
     * @throws \RuntimeException When the worker is unconfigured, unreachable, or answers an error.
     */
    public function postJson(string $endpoint, array $body, int $timeout = 30): array
    {
        if (! $this->configured()) {
            throw new \RuntimeException('The media worker is not configured.');
        }

        try {
            $response = Http::timeout(max(1, $timeout))
                ->acceptJson()
                ->withHeaders($this->headers())
                ->post($this->url().$endpoint, $body);
        } catch (Throwable $e) {
            throw new \RuntimeException('Media worker request failed: '.$e->getMessage(), 0, $e);
        }

        return $this->decodeJsonResponse($response);
    }

    /**
     * GET a worker JSON endpoint and decode the envelope.
     *
     * The read half of the JSON family (`/api/social/accounts`) has no body, so
     * `postJson()` would be a lie in the request log. Same error contract as its
     * POST sibling, on purpose: an adapter should not need to know which verb it
     * used to know what a failure looks like.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException When the worker is unconfigured, unreachable, or answers an error.
     */
    public function getJson(string $endpoint, int $timeout = 30): array
    {
        if (! $this->configured()) {
            throw new \RuntimeException('The media worker is not configured.');
        }

        try {
            $response = Http::timeout(max(1, $timeout))
                ->acceptJson()
                ->withHeaders($this->headers())
                ->get($this->url().$endpoint);
        } catch (Throwable $e) {
            throw new \RuntimeException('Media worker request failed: '.$e->getMessage(), 0, $e);
        }

        return $this->decodeJsonResponse($response);
    }

    /**
     * GET a worker endpoint and return the raw body.
     *
     * `/api/video/process` writes its output on the **worker's** filesystem and
     * answers a name; `/api/video/download/<name>` is how the calling host gets
     * the bytes. That is a two-step contract, so the fetch lives here rather than
     * in each adapter.
     *
     * @throws \RuntimeException When the worker is unconfigured, unreachable, refuses, or answers an error envelope.
     */
    public function download(string $path, int $timeout = 120): string
    {
        if (! $this->configured()) {
            throw new \RuntimeException('The media worker is not configured.');
        }

        try {
            $response = Http::timeout(max(1, $timeout))
                ->withHeaders($this->headers())
                ->get($this->url().$path);
        } catch (Throwable $e) {
            throw new \RuntimeException('Media worker request failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->ok()) {
            $decoded = $response->json();

            $reason = is_array($decoded) && isset($decoded['error'])
                ? (string) $decoded['error']
                : 'HTTP '.$response->status();

            throw new \RuntimeException('Media worker error: '.$reason);
        }

        $body = $response->body();

        if ('' === $body) {
            throw new \RuntimeException('The media worker returned an empty file.');
        }

        return $body;
    }

    /**
     * POST one file to a worker endpoint and decode the JSON envelope.
     *
     * `optimize()` predates this helper and is left untouched so its shipped
     * error contract does not change; the transform paths share this.
     *
     * @param array<string, scalar> $fields
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function postFile(string $endpoint, string $localPath, string $filename, array $fields, int $timeout): array
    {
        return $this->uploadFile($endpoint, $localPath, $filename, $fields, $timeout);
    }

    /**
     * POST one file to a worker endpoint and decode the JSON envelope.
     *
     * The multipart endpoint family (`/api/pdf/extract`, `/api/pdf/watermark`,
     * `/api/image/transform`, …) shares one error contract, so the domain
     * adapters do not each invent one.
     *
     * @param array<string, scalar> $fields
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function uploadFile(string $endpoint, string $localPath, string $filename, array $fields = array(), int $timeout = 60): array
    {
        if (! $this->configured()) {
            throw new \RuntimeException('The media worker is not configured.');
        }

        $stream = is_file($localPath) ? fopen($localPath, 'rb') : false;

        if (false === $stream) {
            throw new \RuntimeException('Source file is not readable: '.$localPath);
        }

        try {
            $response = Http::timeout(max(1, $timeout))
                ->acceptJson()
                ->withHeaders($this->headers())
                ->attach('file', $stream, $filename)
                ->post($this->url().$endpoint, $fields);
        } catch (Throwable $e) {
            throw new \RuntimeException('Media worker request failed: '.$e->getMessage(), 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $this->decodeFileResponse($response);
    }

    /**
     * POST several files to one worker endpoint, in order.
     *
     * `/api/pdf/merge` reads `files` or `files[n]` parts and sorts them by
     * index, so the order is carried in the field name rather than trusted to
     * arrival order — a merge that reordered pages would be a silent corruption.
     *
     * @param array<int, string>    $localPaths Absolute paths, in the order to send.
     * @param array<string, scalar> $fields
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function uploadFiles(string $endpoint, array $localPaths, array $fields = array(), int $timeout = 60): array
    {
        if (! $this->configured()) {
            throw new \RuntimeException('The media worker is not configured.');
        }

        $request = Http::timeout(max(1, $timeout))
            ->acceptJson()
            ->withHeaders($this->headers());

        $streams = array();

        foreach (array_values($localPaths) as $index => $path) {
            $stream = is_string($path) && is_file($path) ? fopen($path, 'rb') : false;

            if (false === $stream) {
                foreach ($streams as $open) {
                    if (is_resource($open)) {
                        fclose($open);
                    }
                }

                throw new \RuntimeException(
                    'Source file is not readable: '.(is_string($path) ? $path : '')
                );
            }

            $streams[] = $stream;
            $request   = $request->attach('files['.$index.']', $stream, basename($path));
        }

        try {
            $response = $request->post($this->url().$endpoint, $fields);
        } catch (Throwable $e) {
            throw new \RuntimeException('Media worker request failed: '.$e->getMessage(), 0, $e);
        } finally {
            foreach ($streams as $open) {
                if (is_resource($open)) {
                    fclose($open);
                }
            }
        }

        return $this->decodeFileResponse($response);
    }

    /**
     * Turn a worker response into a decoded body, or a named failure.
     *
     * @param \Illuminate\Http\Client\Response $response
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function decodeFileResponse($response): array
    {
        return $this->decodeJsonResponse($response);
    }

    /**
     * Turn a worker JSON response into a decoded body, or a named failure.
     *
     * Shared by the JSON verb pair and the multipart pair, so a 400 from any
     * route reads as a refusal rather than a runtime failure whichever verb and
     * body shape carried it. `postJson()` and `getJson()` used to carry their own
     * copy of this block and therefore reported a bad request as a runtime
     * error, which made a malformed expression indistinguishable from an
     * unreachable worker.
     *
     * @param \Illuminate\Http\Client\Response $response
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function decodeJsonResponse($response): array
    {
        $body = $response->json();

        if (! $response->ok() || ! is_array($body)) {
            $reason = is_array($body) && isset($body['error'])
                ? (string) $body['error']
                : 'HTTP '.$response->status();

            $message = 'Media worker error: '.$reason;

            // 400, 413 and 422 mean the worker is refusing the *request* — an
            // option it does not take, inputs it cannot use, a payload that will
            // not fit. The message and the base class stay exactly as they have
            // always been, so no existing caller or test changes; the narrower
            // class only lets a caller that can tell the difference report a bad
            // request as a bad request.
            if (in_array($response->status(), array(400, 413, 422), true)) {
                throw new MediaWorkerRefusedException($message);
            }

            throw new \RuntimeException($message);
        }

        return $body;
    }

    private function transformTimeout(): int
    {
        return max(1, (int) config(
            'oos.media_worker.transform_timeout',
            config('oos.media_worker.optimize_timeout', 60)
        ));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['X-Site-Url' => (string) config('app.url', '')];
        $token = (string) config('oos.media_worker.token', '');

        if ('' !== $token) {
            $headers['X-Site-Token'] = $token;
        }

        return $headers;
    }

    private function timeout(): int
    {
        return max(1, (int) config('oos.media_worker.timeout', 3));
    }

    /**
     * `health/full` shells out to `which` on a cold worker, so it gets its own
     * budget rather than the 3-second liveness timeout.
     */
    private function capabilitiesTimeout(): int
    {
        return max(1, (int) config('oos.media_worker.capabilities_timeout', 8));
    }
}
