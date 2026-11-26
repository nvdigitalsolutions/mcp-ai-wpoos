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
            $encoded = $this->encode($rows);

            if (null !== $encoded['encoding']) {
                $headers['Content-Encoding'] = $encoded['encoding'];
            }

            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->acceptJson()
                ->withBody($encoded['body'], 'application/json')
                ->post($endpoint);
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

    /**
     * The request body, compressed when configured and worthwhile — §77.
     *
     * The body is encoded here rather than by `asJson()` so the compressed and
     * uncompressed paths produce the same bytes: only the transport wrapper
     * differs. The flags match what Laravel's JSON body format uses.
     *
     * `Content-Encoding` is only claimed when a body was actually compressed.
     * A `gzencode()` failure falls back to plain JSON — the collector would
     * otherwise try to inflate plain text and reject the batch.
     *
     * @param list<object> $rows
     * @return array{body: string, encoding: string|null}
     *
     * @throws \JsonException When the payload cannot be encoded.
     */
    private function encode(array $rows): array
    {
        $json = json_encode(
            $this->exporter->otel($rows),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $compression = strtolower(trim((string) config('oos.routing.export.compression', 'none')));
        $threshold = max(0, (int) config('oos.routing.export.compression_threshold', 1024));

        if ('gzip' !== $compression || strlen($json) < $threshold || ! function_exists('gzencode')) {
            return ['body' => $json, 'encoding' => null];
        }

        $compressed = gzencode($json, 6);

        if (false === $compressed) {
            return ['body' => $json, 'encoding' => null];
        }

        return ['body' => $compressed, 'encoding' => 'gzip'];
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
