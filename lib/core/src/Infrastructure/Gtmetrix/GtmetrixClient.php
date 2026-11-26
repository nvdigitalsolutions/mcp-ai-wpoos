<?php

/**
 * GTmetrix REST API v2.0 over the core HTTP contract.
 *
 * Port of `WP_MCP_AI_GTmetrix_Client` (Proposal 164 section 164.91). The auth
 * scheme, base URL, error taxonomy and rate-limit headers are the published v2.0
 * contract; the request loop is not.
 *
 * ## Corrections
 *
 * 1. **The JSON:API `meta` block is preserved.** WordPress returned
 *    `$decoded['data']` only, so `start_test` could never read the
 *    `meta.credits_left` / `meta.credits_used` it was wired for and reported its
 *    own estimate instead.
 * 2. **A `303` is returned, not followed.** `GET /tests/{id}` answers `303` with
 *    `Location: /reports/{id}` when a test completes; `wp_remote_request`
 *    followed it, so the caller received a report and no test state. The status
 *    and location are in the response for the tool to interpret.
 * 3. **No sleeping retry.** WordPress retried `E42901` after 1, 2 and 4 seconds
 *    inside the request. The exception carries `retryAfter` from the server's
 *    `Retry-After` or `X-RateLimit-Reset` and the caller decides.
 * 4. **The fixture switch is gone.** Tests inject `HttpClientInterface`; the
 *    committed JSON fixtures are the oracle, not a runtime branch.
 * 5. **The decoded body is returned.** A report resource (`lighthouse.json`,
 *    `resource-summary`) answers a bare object rather than a JSON:API datum,
 *    and the WordPress client's `get_resource()` special-cased that per call.
 *    Returning `document` alongside `data` lets one verb serve both shapes and
 *    keeps the endpoint knowledge in the tools.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Gtmetrix;

use Nvoos\Core\Domain\Contract\GtmetrixClientInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Error\GtmetrixApiException;

final class GtmetrixClient implements GtmetrixClientInterface
{
    public const API_BASE = 'https://gtmetrix.com/api/2.0/';

    /** The settings key the API key is stored under. */
    public const PROVIDER_KEY = 'gtmetrix';

    /**
     * API error code → core code and default message.
     *
     * The API numbers request rejections `E400xx`–`E403xx`; anything unmapped in
     * that band is classified as `gtmetrix_invalid_request` rather than the
     * generic `gtmetrix_api_error`, because the distinction is what tells a
     * caller whether retrying could help.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const ERRORS = array(
        'E40100' => array('gtmetrix_auth_failed', 'Invalid GTmetrix API key.'),
        'E40300' => array('gtmetrix_email_unvalidated', 'GTmetrix account e-mail address is not validated.'),
        'E40301' => array('gtmetrix_role_denied', 'Your GTmetrix role does not permit this operation.'),
        'E40201' => array('gtmetrix_insufficient_credits', 'Insufficient GTmetrix API credits for this request.'),
        'E41500' => array('gtmetrix_unsupported_media_type', 'Unsupported media type (JSON:API content type required).'),
        'E42900' => array('gtmetrix_too_many_tests', 'Too many tests pending — retry after an existing test completes.'),
        'E42901' => array('gtmetrix_rate_limited', 'GTmetrix API rate limit exceeded.'),
        'E42902' => array('gtmetrix_report_list_busy', 'A report-list query is already running — retry shortly.'),
        'E42903' => array('gtmetrix_pdf_queue_full', 'PDF generation is busy — retry in a few moments.'),
        'E40400' => array('gtmetrix_not_found', 'GTmetrix test not found.'),
        'E40401' => array('gtmetrix_not_found', 'GTmetrix report not found.'),
        'E40402' => array('gtmetrix_not_found', 'GTmetrix page not found.'),
        'E40403' => array('gtmetrix_not_found', 'GTmetrix browser not found.'),
        'E40404' => array('gtmetrix_not_found', 'GTmetrix location not found.'),
        'E40405' => array('gtmetrix_not_found', 'The report screenshot was not found.'),
        'E40406' => array('gtmetrix_not_found', 'The HAR file was not found.'),
        'E40407' => array('gtmetrix_not_found', 'The requested report resource is unavailable.'),
        'E40408' => array('gtmetrix_not_found', 'GTmetrix simulated device not found.'),
        'E40409' => array('gtmetrix_not_found', 'The filmstrip is unavailable for this report.'),
        'E40410' => array('gtmetrix_not_found', 'The Lighthouse JSON is unavailable for this report.'),
        'E40411' => array('gtmetrix_not_found', 'The optimized images archive is unavailable.'),
        'E40412' => array('gtmetrix_not_found', 'The optimized images archive has expired.'),
        'E40413' => array('gtmetrix_not_found', 'The PageSpeed JSON is unavailable for this report.'),
        'E40414' => array('gtmetrix_not_found', 'The YSlow JSON is unavailable for this report.'),
        'E40415' => array('gtmetrix_not_found', 'The PageSpeed files archive is unavailable.'),
        'E40416' => array('gtmetrix_not_found', 'The PageSpeed files archive has expired.'),
        'E40417' => array('gtmetrix_not_found', 'GTmetrix page tag not found.'),
        'E50000' => array('gtmetrix_server_error', 'GTmetrix internal server error.'),
        'E50001' => array('gtmetrix_server_error', 'Error generating the GTmetrix report PDF.'),
        'E50002' => array('gtmetrix_server_error', 'Error generating the GTmetrix Lighthouse JSON.'),
        'E50200' => array('gtmetrix_unavailable', 'GTmetrix application server temporarily unavailable.'),
        'E50300' => array('gtmetrix_unavailable', 'GTmetrix application server temporarily unavailable.'),
    );

    public function __construct(
        private readonly SettingsStoreInterface $settings,
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl = self::API_BASE,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->apiKey();
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $key = $this->apiKey();

        if ('' === $key) {
            throw new GtmetrixApiException(
                'No GTmetrix API key is configured.',
                'gtmetrix_not_configured'
            );
        }

        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($key . ':'),
            'Accept'        => 'application/vnd.api+json',
        );

        $encoded = null;

        if (null !== $body) {
            $encoded = json_encode($body);

            if (false === $encoded) {
                throw new GtmetrixApiException(
                    'The GTmetrix request body could not be encoded.',
                    'gtmetrix_bad_request'
                );
            }

            $headers['Content-Type'] = 'application/vnd.api+json';
        }

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        try {
            $response = $this->http->send(strtoupper($method), $url, $headers, $encoded);
        } catch (\Throwable $error) {
            throw new GtmetrixApiException(
                'GTmetrix request failed: ' . $error->getMessage(),
                'gtmetrix_request_failed',
                0,
                '',
                0,
                array(),
                $error
            );
        }

        $body    = trim($response->body);
        $decoded = '' === $body ? null : json_decode($body, true);

        // A redirect carries its meaning in the Location header, and the 303 that
        // marks a completed test may carry no body at all. An empty body on a
        // 2xx/3xx is a payload with data null, not a parse failure.
        if (null === $decoded) {
            if ('' === $body && $response->statusCode >= 200 && $response->statusCode < 400) {
                return array(
                    'status'     => $response->statusCode,
                    'data'       => null,
                    'meta'       => array(),
                    'links'      => array(),
                    'location'   => $this->header($response, 'location'),
                    'rate_limit' => $this->rateLimit($response),
                    'document'   => array(),
                );
            }

            throw new GtmetrixApiException(
                'GTmetrix returned a non-JSON response.',
                'gtmetrix_bad_response',
                $response->statusCode
            );
        }

        if (! is_array($decoded)) {
            throw new GtmetrixApiException(
                'GTmetrix returned an unexpected response shape.',
                'gtmetrix_bad_response',
                $response->statusCode
            );
        }

        if (! empty($decoded['errors']) && is_array($decoded['errors'])) {
            $this->raise($decoded, $response);
        }

        return array(
            'status'     => $response->statusCode,
            'data'       => $decoded['data'] ?? null,
            'meta'       => is_array($decoded['meta'] ?? null) ? $decoded['meta'] : array(),
            'links'      => is_array($decoded['links'] ?? null) ? $decoded['links'] : array(),
            'location'   => $this->header($response, 'location'),
            'rate_limit' => $this->rateLimit($response),
            'document'   => $decoded,
        );
    }

    /**
     * Throw the classified exception for a JSON:API error envelope.
     *
     * @param array<string, mixed> $decoded Decoded body.
     */
    private function raise(array $decoded, HttpResponse $response): never
    {
        $first   = is_array($decoded['errors'][0] ?? null) ? $decoded['errors'][0] : array();
        $apiCode = isset($first['code']) ? strtoupper((string) $first['code']) : '';
        $map     = self::ERRORS[ $apiCode ] ?? $this->fallback($apiCode);
        $detail  = isset($first['detail']) ? trim((string) $first['detail']) : '';

        throw new GtmetrixApiException(
            '' !== $detail ? $detail : $map[1],
            $map[0],
            $response->statusCode,
            $apiCode,
            $this->retryAfter($response, $apiCode),
            $decoded
        );
    }

    /**
     * The classification for an unmapped API code.
     *
     * @return array{0: string, 1: string}
     */
    private function fallback(string $apiCode): array
    {
        if (str_starts_with($apiCode, 'E4')) {
            return array('gtmetrix_invalid_request', 'GTmetrix rejected the request parameters.');
        }

        return array('gtmetrix_api_error', 'GTmetrix API error.');
    }

    /**
     * The seconds the server asked the caller to wait.
     */
    private function retryAfter(HttpResponse $response, string $apiCode): int
    {
        $retry = $this->header($response, 'retry-after');

        if (is_numeric($retry)) {
            return max(0, (int) $retry);
        }

        if ('E42901' === $apiCode) {
            $reset = $this->header($response, 'x-ratelimit-reset');

            if (is_numeric($reset)) {
                return max(0, (int) $reset);
            }
        }

        return 0;
    }

    /**
     * The rate-limit state the API reports.
     *
     * `X-RateLimit-Limit` carries the quota plus a window directive
     * (`240, 240;window=60`), so the first integer is the quota.
     *
     * @return array{limit: int, remaining: int|null, reset: int|null}
     */
    private function rateLimit(HttpResponse $response): array
    {
        $limit     = $this->header($response, 'x-ratelimit-limit');
        $remaining = $this->header($response, 'x-ratelimit-remaining');
        $reset     = $this->header($response, 'x-ratelimit-reset');

        $quota = 0;

        if ('' !== $limit && 1 === preg_match('/\d+/', $limit, $match)) {
            $quota = (int) $match[0];
        }

        return array(
            'limit'     => $quota,
            'remaining' => is_numeric($remaining) ? (int) $remaining : null,
            'reset'     => is_numeric($reset) ? (int) $reset : null,
        );
    }

    /**
     * One response header, casing-insensitively.
     */
    private function header(HttpResponse $response, string $name): string
    {
        foreach ($response->headers as $key => $value) {
            if (! is_string($key) || strtolower($key) !== $name) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            return is_scalar($value) ? trim((string) $value) : '';
        }

        return '';
    }

    /**
     * The configured API key, trimmed.
     */
    private function apiKey(): string
    {
        $key = $this->settings->getApiKey(self::PROVIDER_KEY);

        return null === $key ? '' : trim($key);
    }
}
