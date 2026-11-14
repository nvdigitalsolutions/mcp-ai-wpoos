<?php

namespace App\Support\Media;

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
