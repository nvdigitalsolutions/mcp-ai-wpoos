<?php

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\Http;
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
final class MediaWorkerClient
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
     */
    public function configured(): bool
    {
        return '' !== $this->url();
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
        if (! $this->configured()) {
            throw new \RuntimeException('The media worker is not configured.');
        }

        $stream = is_file($localPath) ? fopen($localPath, 'rb') : false;

        if (false === $stream) {
            throw new \RuntimeException('Source file is not readable: '.$localPath);
        }

        try {
            $response = Http::timeout($timeout)
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

        $body = $response->json();

        if (! $response->ok() || ! is_array($body)) {
            $reason = is_array($body) && isset($body['error'])
                ? (string) $body['error']
                : 'HTTP '.$response->status();

            throw new \RuntimeException('Media worker error: '.$reason);
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
}
