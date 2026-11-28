<?php

/**
 * Tests for the Google Calendar event-write client.
 *
 * Proposal 164 section 164.136. The cases pin the three things the client owns:
 * the `grant_type=refresh_token` exchange and its `invalid_grant`
 * classification, the two event verbs with `sendUpdates` as a query parameter,
 * and the error taxonomy that tells a deleted event from a mistyped calendar.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Infrastructure;

use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Error\GoogleCalendarApiException;
use Nvoos\Core\Infrastructure\Google\GoogleCalendarClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GoogleCalendarClientTest extends TestCase
{
    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const BASE = 'https://www.googleapis.com/calendar/v3';

    public function test_an_empty_settings_block_is_not_configured(): void
    {
        $this->assertFalse($this->client(array(), null, false)->isConfigured());
    }

    public function test_a_refresh_triple_is_configured(): void
    {
        $this->assertTrue($this->client(array())->isConfigured());
    }

    public function test_a_refresh_token_without_a_secret_is_not_configured(): void
    {
        $client = $this->client(
            array(
                'client_id'     => 'id',
                'refresh_token' => 'refresh',
            ),
            null,
            false
        );

        $this->assertFalse($client->isConfigured());
    }

    public function test_a_direct_access_token_is_configured(): void
    {
        $this->assertTrue($this->client(array('access_token' => 'ya29.token'))->isConfigured());
    }

    public function test_the_refresh_token_is_exchanged_once_and_carried_as_a_bearer(): void
    {
        $http = $this->http(
            array(
                $this->tokenResponse('ya29.first'),
                new HttpResponse(200, '{"id":"evt-1"}'),
                new HttpResponse(200, '{"id":"evt-2"}'),
            )
        );

        $client = $this->client(array(), $http);

        $client->insertEvent('primary', array('summary' => 'One'));
        $client->insertEvent('primary', array('summary' => 'Two'));

        $this->assertCount(3, $http->calls);

        [$method, $url, $headers, $body] = $http->calls[0];

        $this->assertSame('POST', $method);
        $this->assertSame(self::TOKEN, $url);
        $this->assertSame('application/x-www-form-urlencoded', $headers['Content-Type']);
        $this->assertStringContainsString('grant_type=refresh_token', $body);
        $this->assertStringContainsString('client_id=id', $body);
        $this->assertStringContainsString('client_secret=secret', $body);
        $this->assertStringContainsString('refresh_token=refresh', $body);

        $this->assertSame('Bearer ya29.first', $http->calls[1][2]['Authorization']);
        $this->assertSame('Bearer ya29.first', $http->calls[2][2]['Authorization']);
    }

    public function test_a_revoked_refresh_token_is_named(): void
    {
        $http = $this->http(
            array(
                new HttpResponse(
                    400,
                    '{"error":"invalid_grant","error_description":"Token has been expired or revoked."}'
                ),
            )
        );

        $client = $this->client(array(), $http);

        try {
            $client->insertEvent('primary', array('summary' => 'One'));
            $this->fail('Expected a refused refresh.');
        } catch (GoogleCalendarApiException $error) {
            $this->assertSame('google_calendar_credentials_revoked', $error->errorCode);
            $this->assertSame(400, $error->statusCode);
            $this->assertSame('invalid_grant', $error->apiCode);
            $this->assertStringContainsString('revoked', $error->getMessage());
        }
    }

    public function test_an_insert_posts_a_json_event_with_send_updates(): void
    {
        $http = $this->http(array(new HttpResponse(200, '{"id":"evt-1","htmlLink":"https://cal/e/1"}')));

        $client = $this->client(array('access_token' => 'ya29.token'), $http);

        $body = $client->insertEvent(
            'team@group.calendar.google.com',
            array('summary' => 'One'),
            array('sendUpdates' => 'externalOnly')
        );

        [$method, $url, $headers, $encoded] = $http->calls[0];

        $this->assertSame('POST', $method);
        $this->assertSame(
            self::BASE . '/calendars/team%40group.calendar.google.com/events?sendUpdates=externalOnly',
            $url
        );
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('{"summary":"One"}', $encoded);
        $this->assertSame('evt-1', $body['id']);
        $this->assertSame('https://cal/e/1', $body['htmlLink']);
    }

    public function test_an_update_puts_the_event_and_encodes_its_id(): void
    {
        $http   = $this->http(array(new HttpResponse(200, '{"id":"evt/1"}')));
        $client = $this->client(array('access_token' => 'ya29.token'), $http);

        $client->updateEvent('primary', 'evt/1', array('summary' => 'Two'));

        [$method, $url] = $http->calls[0];

        $this->assertSame('PUT', $method);
        $this->assertSame(self::BASE . '/calendars/primary/events/evt%2F1', $url);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string, 3: bool}>
     */
    public static function updateFailures(): array
    {
        return array(
            'hard deleted'  => array(404, 'notFound', 'google_calendar_event_not_found', true),
            'cancelled'     => array(410, 'deleted', 'google_calendar_event_deleted', true),
            'scope'         => array(403, 'insufficientPermissions', 'google_calendar_scope_missing', false),
            'non organiser' => array(403, 'forbiddenForNonOrganizer', 'google_calendar_forbidden_non_organizer', false),
            'unauthorised'  => array(401, 'authError', 'google_calendar_auth_failed', false),
            'rate limited'  => array(429, 'rateLimitExceeded', 'google_calendar_rate_limited', false),
            'server'        => array(500, 'backendError', 'google_calendar_unavailable', false),
        );
    }

    #[DataProvider('updateFailures')]
    public function test_an_update_failure_is_classified(
        int $status,
        string $reason,
        string $expected,
        bool $gone
    ): void {
        $payload = sprintf(
            '{"error":{"code":%d,"message":"Refused.","errors":[{"reason":"%s"}]}}',
            $status,
            $reason
        );
        $http   = $this->http(array(new HttpResponse($status, $payload)));
        $client = $this->client(array('access_token' => 'ya29.token'), $http);

        try {
            $client->updateEvent('primary', 'evt-1', array('summary' => 'One'));
            $this->fail('Expected a refused update.');
        } catch (GoogleCalendarApiException $error) {
            $this->assertSame($expected, $error->errorCode);
            $this->assertSame($status, $error->statusCode);
            $this->assertSame($reason, $error->apiCode);
            $this->assertSame($gone, $error->isGone());
        }
    }

    public function test_a_404_on_insert_names_the_calendar_not_the_event(): void
    {
        $http = $this->http(
            array(new HttpResponse(404, '{"error":{"code":404,"message":"Not Found","errors":[{"reason":"notFound"}]}}'))
        );
        $client = $this->client(array('access_token' => 'ya29.token'), $http);

        try {
            $client->insertEvent('wrong-calendar', array('summary' => 'One'));
            $this->fail('Expected a refused insert.');
        } catch (GoogleCalendarApiException $error) {
            $this->assertSame('google_calendar_calendar_not_found', $error->errorCode);
            $this->assertFalse($error->isGone());
        }
    }

    public function test_retry_after_is_carried(): void
    {
        $http = $this->http(
            array(
                new HttpResponse(
                    429,
                    '{"error":{"code":429,"message":"Slow down","errors":[{"reason":"rateLimitExceeded"}]}}',
                    array('Retry-After' => '7')
                ),
            )
        );
        $client = $this->client(array('access_token' => 'ya29.token'), $http);

        try {
            $client->updateEvent('primary', 'evt-1', array());
            $this->fail('Expected a refused update.');
        } catch (GoogleCalendarApiException $error) {
            $this->assertSame(7, $error->retryAfter);
        }
    }

    public function test_a_transport_failure_is_named(): void
    {
        $http         = $this->http(array());
        $http->throws = new \RuntimeException('connection refused');

        $client = $this->client(array('access_token' => 'ya29.token'), $http);

        try {
            $client->updateEvent('primary', 'evt-1', array());
            $this->fail('Expected a failed request.');
        } catch (GoogleCalendarApiException $error) {
            $this->assertSame('google_calendar_request_failed', $error->errorCode);
            $this->assertSame(0, $error->statusCode);
            $this->assertStringContainsString('connection refused', $error->getMessage());
        }
    }

    public function test_a_non_json_body_is_refused(): void
    {
        $http   = $this->http(array(new HttpResponse(200, '<html>gateway</html>')));
        $client = $this->client(array('access_token' => 'ya29.token'), $http);

        $this->expectException(GoogleCalendarApiException::class);
        $this->expectExceptionMessage('non-JSON');

        $client->insertEvent('primary', array());
    }

    // ─── Calendar and zone resolution ────────────────────────────────────

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function calendarIds(): array
    {
        return array(
            'configured'      => array(array('calendar_id' => 'team@group'), '', 'team@group'),
            'override wins'   => array(array('calendar_id' => 'team@group'), 'other@group', 'other@group'),
            'default primary' => array(array(), '', 'primary'),
        );
    }

    /**
     * @param array<string, mixed> $settings Settings.
     */
    #[DataProvider('calendarIds')]
    public function test_the_calendar_id_resolves(array $settings, string $override, string $expected): void
    {
        $this->assertSame($expected, $this->client($settings)->calendarId($override));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function zones(): array
    {
        return array(
            'iana'    => array(array('timezone' => 'Europe/London'), 'Europe/London'),
            'offset'  => array(array('timezone' => '+05:30'), 'UTC'),
            'missing' => array(array(), 'UTC'),
        );
    }

    /**
     * @param array<string, mixed> $settings Settings.
     */
    #[DataProvider('zones')]
    public function test_the_zone_falls_back_to_utc(array $settings, string $expected): void
    {
        $this->assertSame($expected, $this->client($settings)->defaultTimeZone());
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * A client over a settings mock.
     *
     * @param array<string, mixed> $settings     Settings overlay.
     * @param object|null          $http         Queue transport, or null for none.
     * @param bool                 $withDefaults Whether the refresh triple is present.
     */
    private function client(array $settings, ?object $http = null, bool $withDefaults = true): GoogleCalendarClient
    {
        $store = $this->createMock(SettingsStoreInterface::class);
        $base  = $withDefaults
            ? array(
                'client_id'     => 'id',
                'client_secret' => 'secret',
                'refresh_token' => 'refresh',
            )
            : array();

        $store->method('get')->willReturn(array_merge($base, $settings));

        return new GoogleCalendarClient(
            $store,
            $http ?? $this->http(array()),
            self::TOKEN,
            self::BASE
        );
    }

    private function tokenResponse(string $token): HttpResponse
    {
        return new HttpResponse(
            200,
            (string) json_encode(
                array(
                    'access_token' => $token,
                    'expires_in'   => 3599,
                    'token_type'   => 'Bearer',
                    'scope'        => 'https://www.googleapis.com/auth/calendar.events',
                )
            )
        );
    }

    /**
     * A queued transport that records each request.
     *
     * @param list<HttpResponse> $responses Queued responses.
     */
    private function http(array $responses): object
    {
        return new class ($responses) implements HttpClientInterface {
            /** @var list<array{0: string, 1: string, 2: array<string, string>, 3: ?string}> */
            public array $calls = array();

            public ?\Throwable $throws = null;

            /**
             * @param list<HttpResponse> $responses Queued responses.
             */
            public function __construct(private array $responses)
            {
            }

            public function send(string $method, string $url, array $headers = array(), ?string $body = null): HttpResponse
            {
                $this->calls[] = array($method, $url, $headers, $body);

                if (null !== $this->throws) {
                    throw $this->throws;
                }

                $next = array_shift($this->responses);

                if (null === $next) {
                    throw new \RuntimeException('no queued response for ' . $method . ' ' . $url);
                }

                return $next;
            }
        };
    }
}
