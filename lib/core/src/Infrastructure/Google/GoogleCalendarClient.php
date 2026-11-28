<?php

/**
 * Google Calendar v3 event writes over the core HTTP contract.
 *
 * Port of the outbound half of `WP_MCP_AI_Tool_Sync_Google_Calendar`
 * (Proposal 164 section 164.136). The tool pushes one appointment at a time and
 * keys the link on `_google_calendar_event_id`, so the client serves two verbs
 * and the OAuth refresh that carries them.
 *
 * ## Corrections
 *
 * 1. **Credentials come from one settings key.** WordPress resolved a
 *    per-connection store first and fell back to an option; this runtime has one
 *    Google account per site, so `google_calendar` holds
 *    `client_id`/`client_secret`/`refresh_token`/`calendar_id`/`timezone`.
 *    A named connection is refused by the tool rather than ignored.
 * 2. **The token is refreshed once per client instance.** WordPress let the
 *    vendor SDK decide; here the refresh is one documented
 *    `grant_type=refresh_token` POST to `oauth2.googleapis.com/token`, cached
 *    for the lifetime of the instance, with `invalid_grant` classified as a
 *    revoked credential rather than a generic auth failure.
 * 3. **A 404 while updating means the event is gone; a 404 while inserting
 *    means the calendar is wrong.** WordPress mapped both to one code, so a
 *    mistyped calendar id looked like a deleted event and the tool "recreated"
 *    into the same bad calendar. The second case is now named.
 * 4. **No SDK.** The request is a bearer-authenticated JSON call, so the client
 *    is testable against `HttpClientInterface` instead of a vendor mock.
 * 5. **A deleted event is recreated, not patched.** WordPress recreated by
 *    inserting a new one, which is what this port does; Google also accepts
 *    `events.patch` on a cancelled resource, but a recreated event keeps the
 *    attendee list and the local id map intact.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Google;

use Nvoos\Core\Domain\Contract\GoogleCalendarClientInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Error\GoogleCalendarApiException;

final class GoogleCalendarClient implements GoogleCalendarClientInterface
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const API_BASE = 'https://www.googleapis.com/calendar/v3';

    /** The settings key holding the account and calendar. */
    public const SETTINGS_KEY = 'google_calendar';

    /**
     * Status and Google `reason` → core code and default message.
     *
     * Google puts the useful discriminator in `error.errors[0].reason`, not in
     * the status line: `insufficientPermissions` and `forbiddenForNonOrganizer`
     * are both a 403 and need different advice.
     */
    public const ERRORS = array(
        '400'                          => array('google_calendar_invalid_request', 'Google Calendar rejected the request.'),
        '401'                          => array('google_calendar_auth_failed', 'Google Calendar did not accept the access token.'),
        '403'                          => array('google_calendar_forbidden', 'Google Calendar refused the request.'),
        '403 insufficientPermissions'  => array('google_calendar_scope_missing', 'The Google credentials do not carry the calendar.events scope.'),
        '403 forbiddenForNonOrganizer' => array('google_calendar_forbidden_non_organizer', 'Only the event organiser may make this change.'),
        '404'                          => array('google_calendar_not_found', 'The Google Calendar resource could not be found.'),
        '429'                          => array('google_calendar_rate_limited', 'Google Calendar rate limit exceeded.'),
        '500'                          => array('google_calendar_unavailable', 'Google Calendar is temporarily unavailable.'),
        '503'                          => array('google_calendar_unavailable', 'Google Calendar is temporarily unavailable.'),
    );

    private ?string $token = null;

    public function __construct(
        private readonly SettingsStoreInterface $settings,
        private readonly HttpClientInterface $http,
        private readonly string $tokenUrl = self::TOKEN_URL,
        private readonly string $baseUrl = self::API_BASE,
    ) {
    }

    public function isConfigured(): bool
    {
        $config = $this->config();

        if ('' !== $this->text($config, 'access_token')) {
            return true;
        }

        return '' !== $this->text($config, 'refresh_token')
            && '' !== $this->text($config, 'client_id')
            && '' !== $this->text($config, 'client_secret');
    }

    public function connectionId(): string
    {
        // The settings connection has no id in WordPress either; the argument
        // exists for Remote Sites connections, which this runtime does not have.
        return '';
    }

    public function connectionName(): string
    {
        return $this->text($this->config(), 'connection_name');
    }

    public function calendarId(string $override = ''): string
    {
        $override = trim($override);

        if ('' !== $override) {
            return $override;
        }

        $configured = $this->text($this->config(), 'calendar_id');

        return '' !== $configured ? $configured : 'primary';
    }

    public function defaultTimeZone(): string
    {
        $zone = $this->text($this->config(), 'timezone');

        // `wp_timezone_string()` can return a bare offset, which the Calendar
        // API rejects; a missing value is UTC.
        if ('' === $zone || 1 === preg_match('/^[+-]\d{2}:?\d{2}$/', $zone)) {
            return 'UTC';
        }

        return $zone;
    }

    public function insertEvent(string $calendarId, array $body, array $params = array()): array
    {
        return $this->request(
            'POST',
            '/calendars/' . rawurlencode($calendarId) . '/events',
            $params,
            $body,
            false
        );
    }

    public function updateEvent(string $calendarId, string $eventId, array $body, array $params = array()): array
    {
        return $this->request(
            'PUT',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            $params,
            $body,
            true
        );
    }

    /**
     * Send one authenticated JSON request and return the decoded resource.
     *
     * @param array<string, mixed> $params  Query parameters.
     * @param array<string, mixed> $body    Event resource.
     * @param bool                 $onEvent Whether a 404/410 means the event is gone.
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $params, array $body, bool $onEvent): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        if (array() !== $params) {
            $url .= '?' . http_build_query($params);
        }

        $encoded = json_encode($body);

        if (false === $encoded) {
            throw new GoogleCalendarApiException(
                'The Google Calendar event could not be encoded.',
                'google_calendar_bad_request'
            );
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        );

        try {
            $response = $this->http->send($method, $url, $headers, $encoded);
        } catch (\Throwable $error) {
            throw new GoogleCalendarApiException(
                'Google Calendar request failed: ' . $error->getMessage(),
                'google_calendar_request_failed',
                0,
                '',
                0,
                array(),
                $error
            );
        }

        $decoded = $this->decode($response);

        if ($response->statusCode >= 400) {
            $this->raise($response, $decoded, $onEvent);
        }

        return $decoded;
    }

    /**
     * The access token, refreshed once per instance.
     */
    private function accessToken(): string
    {
        if (null !== $this->token) {
            return $this->token;
        }

        $config = $this->config();
        $direct = $this->text($config, 'access_token');

        if ('' !== $direct) {
            return $this->token = $direct;
        }

        return $this->token = $this->refresh($config);
    }

    /**
     * Exchange the refresh token for an access token.
     *
     * @param array<string, mixed> $config Settings.
     */
    private function refresh(array $config): string
    {
        $form = http_build_query(
            array(
                'client_id'     => $this->text($config, 'client_id'),
                'client_secret' => $this->text($config, 'client_secret'),
                'refresh_token' => $this->text($config, 'refresh_token'),
                'grant_type'    => 'refresh_token',
            )
        );

        try {
            $response = $this->http->send(
                'POST',
                $this->tokenUrl,
                array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json',
                ),
                $form
            );
        } catch (\Throwable $error) {
            throw new GoogleCalendarApiException(
                'The Google token refresh failed: ' . $error->getMessage(),
                'google_calendar_request_failed',
                0,
                '',
                0,
                array(),
                $error
            );
        }

        $decoded  = $this->decode($response);
        $apiCode  = $this->text($decoded, 'error');
        $message  = $this->text($decoded, 'error_description');

        if ($response->statusCode >= 400 || '' !== $apiCode) {
            $revoked = 'invalid_grant' === $apiCode;

            throw new GoogleCalendarApiException(
                '' !== $message
                    ? $message
                    : ( $revoked
                        ? 'The Google refresh token is expired or was revoked.'
                        : 'Google did not issue an access token.' ),
                $revoked ? 'google_calendar_credentials_revoked' : 'google_calendar_auth_failed',
                $response->statusCode,
                $apiCode,
                $this->retryAfter($response),
                $decoded
            );
        }

        $token = $this->text($decoded, 'access_token');

        if ('' === $token) {
            throw new GoogleCalendarApiException(
                'Google returned a token response without an access token.',
                'google_calendar_bad_response',
                $response->statusCode
            );
        }

        return $token;
    }

    /**
     * Decode a response body, refusing a non-JSON payload.
     *
     * @return array<string, mixed>
     */
    private function decode(HttpResponse $response): array
    {
        $body    = trim($response->body);
        $decoded = '' === $body ? array() : json_decode($body, true);

        if (! is_array($decoded)) {
            throw new GoogleCalendarApiException(
                'Google Calendar returned a non-JSON response.',
                'google_calendar_bad_response',
                $response->statusCode
            );
        }

        return $decoded;
    }

    /**
     * Throw the classified exception for an error response.
     *
     * @param array<string, mixed> $decoded Decoded body.
     * @param bool                 $onEvent Whether the path named an event.
     */
    private function raise(HttpResponse $response, array $decoded, bool $onEvent): never
    {
        $error  = is_array($decoded['error'] ?? null) ? $decoded['error'] : array();
        $reason = '';

        if (is_array($error['errors'][0] ?? null)) {
            $reason = is_scalar($error['errors'][0]['reason'] ?? null)
                ? (string) $error['errors'][0]['reason']
                : '';
        }

        $status = $response->statusCode;

        if ($onEvent && 404 === $status) {
            [ $code, $text ] = array('google_calendar_event_not_found', 'That Google Calendar event no longer exists.');
        } elseif ($onEvent && 410 === $status) {
            [ $code, $text ] = array('google_calendar_event_deleted', 'That Google Calendar event was deleted.');
        } elseif (404 === $status) {
            [ $code, $text ] = array('google_calendar_calendar_not_found', 'That Google Calendar was not found.');
        } else {
            [ $code, $text ] = self::ERRORS[ $status . ' ' . $reason ]
                ?? self::ERRORS[ (string) $status ]
                ?? array('google_calendar_api_error', 'Google Calendar returned an error.');
        }

        $detail = $this->text($error, 'message');

        throw new GoogleCalendarApiException(
            '' !== $detail ? $detail : $text,
            $code,
            $status,
            $reason,
            $this->retryAfter($response),
            $decoded
        );
    }

    /**
     * The seconds the server asked the caller to wait.
     */
    private function retryAfter(HttpResponse $response): int
    {
        $retry = '';

        foreach ($response->headers as $key => $value) {
            if (is_string($key) && 'retry-after' === strtolower($key)) {
                $retry = is_array($value) ? (string) reset($value) : (string) $value;
                break;
            }
        }

        return is_numeric(trim($retry)) ? max(0, (int) trim($retry)) : 0;
    }

    /**
     * The `google_calendar` settings block.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $config = $this->settings->get(self::SETTINGS_KEY, array());

        return is_array($config) ? $config : array();
    }

    /**
     * One scalar setting as a trimmed string.
     *
     * @param array<string, mixed> $row Settings.
     */
    private function text(array $row, string $key): string
    {
        return isset($row[ $key ]) && is_scalar($row[ $key ]) ? trim((string) $row[ $key ]) : '';
    }
}
