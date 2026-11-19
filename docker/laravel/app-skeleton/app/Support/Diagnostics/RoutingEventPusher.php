<?php

namespace App\Support\Diagnostics;

use Illuminate\Support\Facades\Http;

/**
 * Push routing events to an OTLP-compatible collector — Proposal 149 §74.
 *
 * The endpoint and its credential come from **operator configuration only**
 * (`oos.routing.export.*` / environment variables), never from a request, so
 * there is no SSRF surface here beyond what an operator can already do with a
 * config file.
 *
 * Two rules:
 *
 *  1. **Never throw.** A collector outage must not fail a scheduled command with
 *     a stack trace; the caller needs a result it can record, not an exception.
 *  2. **Never guess success.** Only a 2xx advances the watermark. A redirect, a
 *     204 with an error body, anything else is a failure and the rows stay
 *     pending.
 */
final class RoutingEventPusher
{
    public function __construct(private readonly RoutingEventExporter $exporter)
    {
    }

    /**
     * Send one batch of rows as OTLP/JSON log records.
     *
     * @param list<object> $rows Rows from `nvoos_routing_metrics`.
     * @return array{ok: bool, status: int|null, error: string|null}
     */
    public function push(array $rows): array
    {
        $endpoint = trim((string) config('oos.routing.export.endpoint', ''));

        if ('' === $endpoint) {
            return ['ok' => false, 'status' => null, 'error' => 'No export endpoint configured.'];
        }

        if (! $this->isHttpUrl($endpoint)) {
            return ['ok' => false, 'status' => null, 'error' => 'Export endpoint must be an http(s) URL.'];
        }

        $headers = $this->headers();
        $timeout = max(1, (int) config('oos.routing.export.timeout', 10));

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $this->exporter->otel($rows));
        } catch (\Throwable $exception) {
            report($exception);

            return ['ok' => false, 'status' => null, 'error' => $exception->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true, 'status' => $response->status(), 'error' => null];
        }

        $body = trim($response->body());

        return [
            'ok' => false,
            'status' => $response->status(),
            'error' => sprintf(
                'HTTP %d%s',
                $response->status(),
                '' === $body ? '' : ': '.mb_substr($body, 0, 200)
            ),
        ];
    }

    /**
     * Whether a push is configured at all.
     */
    public function isConfigured(): bool
    {
        return '' !== trim((string) config('oos.routing.export.endpoint', ''));
    }

    /**
     * Auth headers for the collector.
     *
     * A bearer token is the common case for an OTLP gateway; anything else is a
     * JSON map in `oos.routing.export.headers` for collectors that expect
     * vendor-specific keys.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = (array) config('oos.routing.export.headers', []);

        $token = trim((string) config('oos.routing.export.token', ''));

        if ('' !== $token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return array_filter(
            array_map(static fn ($value): string => (string) $value, $headers),
            static fn (string $value): bool => '' !== $value
        );
    }

    private function isHttpUrl(string $url): bool
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
