<?php

/**
 * Tests for `generate_booking_link` and the booking-link record rules.
 *
 * Proposal 164 section 164.87. The cases pin the two decisions that separate
 * this port from WordPress: the token is returned once and stored only as a
 * SHA-256 hash, and the expiry is an absolute UTC deadline rather than a
 * relative day count a future reader has to reinterpret.
 *
 * The resolution cases (`findByToken`, `state`) belong to the surface a host
 * route serves, not to a tool, so they are exercised here rather than through
 * `execute()`.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use DateTimeImmutable;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\RuntimeIdentityInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Calendar\AppointmentDefinition;
use Nvoos\Core\Tool\Calendar\BookingLinkDefinition;
use Nvoos\Core\Tool\Calendar\GenerateBookingLinkTool;
use Nvoos\Core\Tests\Support\FakeCalendarStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarBookingLinksTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );
    }

    // ─── BookingLinkDefinition ───────────────────────────────────────────

    public function test_a_token_is_hex_and_unique(): void
    {
        $first  = BookingLinkDefinition::token();
        $second = BookingLinkDefinition::token();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertSame(BookingLinkDefinition::TOKEN_BYTES * 2, strlen($first));
        $this->assertNotSame($first, $second);
    }

    public function test_the_stored_verifier_is_sha256_of_the_token(): void
    {
        $token = BookingLinkDefinition::token();

        $this->assertSame(hash('sha256', $token), BookingLinkDefinition::hash($token));
        $this->assertSame(64, strlen(BookingLinkDefinition::hash($token)));
    }

    public function test_the_path_prefixes_the_token(): void
    {
        $this->assertSame('/book/abc123', BookingLinkDefinition::path('abc123'));
    }

    public function test_expiry_is_absolute_and_zero_means_none(): void
    {
        $now = new DateTimeImmutable('2026-03-01 12:00:00');

        $this->assertSame('', BookingLinkDefinition::expiresAt(0, $now));
        $this->assertSame('2026-03-31 12:00:00', BookingLinkDefinition::expiresAt(30, $now));
    }

    public function test_the_title_matches_the_wordpress_format(): void
    {
        $this->assertSame('Booking Link: Consultation', BookingLinkDefinition::title('Consultation'));
    }

    // ─── generate_booking_link ───────────────────────────────────────────

    public function test_generate_stores_a_hash_and_returns_the_token_once(): void
    {
        $store  = new FakeCalendarStore();
        $result = (new GenerateBookingLinkTool($this->errors, $store))->execute(
            array(
                'appointment_type' => 'Consultation',
                'duration_minutes' => 45,
                'expiry_days'      => 30,
                'max_bookings'     => 10,
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(5000, $result['data']['link_id']);

        $token = $result['data']['token'];
        $this->assertIsString($token);
        $this->assertSame('/book/' . $token, $result['data']['booking_path']);
        $this->assertNull($result['data']['booking_url']);

        $created = $store->created;
        $this->assertNotNull($created);
        $this->assertSame('mcp_booking_link', $created->type);
        $this->assertSame('Booking Link: Consultation', $created->title);
        $this->assertSame('publish', $created->status);
        $this->assertSame(1, $created->authorId);

        $this->assertSame(BookingLinkDefinition::hash($token), $created->meta['_token_hash']);
        $this->assertArrayNotHasKey('_token', $created->meta);
        $this->assertSame('Consultation', $created->meta['_appointment_type']);
        $this->assertSame(45, $created->meta['_duration_minutes']);
        $this->assertSame(30, $created->meta['_expiry_days']);
        $this->assertSame(10, $created->meta['_max_bookings']);
        $this->assertSame(0, $created->meta['_bookings_count']);
        $this->assertSame(1, $created->meta['_created_by']);
        $this->assertNotSame('', $created->meta['_created_at']);
        $this->assertNotSame('', $created->meta['_expires_at']);

        $this->assertSame('Consultation', $result['data']['link']['appointment_type']);
        $this->assertSame(45, $result['data']['link']['duration_minutes']);
        $this->assertSame(30, $result['data']['link']['expiry_days']);
        $this->assertSame(10, $result['data']['link']['max_bookings']);
        $this->assertSame(0, $result['data']['link']['bookings_count']);
    }

    public function test_generate_defaults_match_wordpress(): void
    {
        $store  = new FakeCalendarStore();
        $result = (new GenerateBookingLinkTool($this->errors, $store))->execute(array(), self::CONTEXT);

        $this->assertTrue($result['success']);

        $created = $store->created;
        $this->assertSame('general', $created->meta['_appointment_type']);
        $this->assertSame(AppointmentDefinition::DEFAULT_DURATION, $created->meta['_duration_minutes']);
        $this->assertSame(0, $created->meta['_expiry_days']);
        $this->assertSame('', $created->meta['_expires_at']);
        $this->assertSame(0, $created->meta['_max_bookings']);
        $this->assertNull($result['data']['link']['expires_at']);
    }

    public function test_the_expiry_deadline_is_the_requested_number_of_days_ahead(): void
    {
        $store  = new FakeCalendarStore();
        $result = (new GenerateBookingLinkTool($this->errors, $store))->execute(
            array('expiry_days' => 7),
            self::CONTEXT
        );

        $deadline = AppointmentDefinition::parseTime($result['data']['link']['expires_at']);

        $this->assertNotNull($deadline);
        $this->assertEqualsWithDelta(time() + ( 7 * 86400 ), $deadline->getTimestamp(), 120);
    }

    public function test_an_absolute_url_is_built_only_when_the_host_reports_a_site_url(): void
    {
        $identity = $this->createMock(RuntimeIdentityInterface::class);
        $identity->method('identity')->willReturn(
            array(
                'name'      => 'test',
                'version'   => '1.0.0',
                'runtime'   => 'laravel',
                'contract'  => 'mcp-ai/v1',
                'site_name' => 'Example',
                'site_url'  => 'https://example.test/',
                'api_base'  => 'https://example.test/api',
            )
        );

        $result = (new GenerateBookingLinkTool($this->errors, new FakeCalendarStore(), $identity))
            ->execute(array(), self::CONTEXT);

        $this->assertSame(
            'https://example.test/book/' . $result['data']['token'],
            $result['data']['booking_url']
        );
    }

    public function test_a_relative_site_url_is_not_concatenated(): void
    {
        $identity = $this->createMock(RuntimeIdentityInterface::class);
        $identity->method('identity')->willReturn(
            array(
                'name'      => 'test',
                'version'   => '1.0.0',
                'runtime'   => 'laravel',
                'contract'  => 'mcp-ai/v1',
                'site_name' => 'Example',
                'site_url'  => '/wp',
                'api_base'  => '/wp/api',
            )
        );

        $result = (new GenerateBookingLinkTool($this->errors, new FakeCalendarStore(), $identity))
            ->execute(array(), self::CONTEXT);

        $this->assertNull($result['data']['booking_url']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidFields(): array
    {
        return array(
            'duration below the floor'  => array(array('duration_minutes' => 10), 'duration_minutes'),
            'duration above the ceiling' => array(array('duration_minutes' => 481), 'duration_minutes'),
            'duration not numeric'      => array(array('duration_minutes' => 'soon'), 'duration_minutes'),
            'negative expiry'           => array(array('expiry_days' => -1), 'expiry_days'),
            'expiry past the ceiling'   => array(array('expiry_days' => BookingLinkDefinition::MAX_EXPIRY_DAYS + 1), 'expiry_days'),
            'negative booking cap'      => array(array('max_bookings' => -5), 'max_bookings'),
            'type too long'             => array(array('appointment_type' => str_repeat('x', 101)), 'appointment_type'),
            'type not scalar'           => array(array('appointment_type' => array('Consultation')), 'appointment_type'),
        );
    }

    /**
     * @dataProvider invalidFields
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    public function test_out_of_range_fields_are_refused(array $arguments, string $field): void
    {
        $store  = new FakeCalendarStore();
        $result = (new GenerateBookingLinkTool($this->errors, $store))->execute($arguments, self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey($field, $result['error']['data']);
        $this->assertNull($store->created);
    }

    public function test_generate_requires_an_administrator(): void
    {
        $this->expectException(AccessDeniedException::class);

        (new GenerateBookingLinkTool($this->errors, new FakeCalendarStore()))
            ->execute(array(), array('user_id' => 0));
    }

    // ─── Resolution contract ─────────────────────────────────────────────

    public function test_a_token_resolves_through_its_hash(): void
    {
        $store = new FakeCalendarStore();

        $result = (new GenerateBookingLinkTool($this->errors, $store))->execute(array(), self::CONTEXT);

        $found = BookingLinkDefinition::findByToken($store, $result['data']['token']);

        $this->assertNotNull($found);
        $this->assertSame($result['data']['link_id'], $found->id);

        $this->assertNull(BookingLinkDefinition::findByToken($store, str_repeat('0', 64)));
        $this->assertNull(BookingLinkDefinition::findByToken($store, ''));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function linkStates(): array
    {
        return array(
            'open'             => array(array('_max_bookings' => 0, '_bookings_count' => 4), ''),
            'below the cap'    => array(array('_max_bookings' => 5, '_bookings_count' => 4), ''),
            'custom expiry'    => array(array('_expires_at' => '2099-01-01 00:00:00'), ''),
            'expired'          => array(array('_expires_at' => '2020-01-01 00:00:00'), 'expired'),
            'unreadable'       => array(array('_expires_at' => 'soon'), 'unreadable_expiry'),
            'at the cap'       => array(array('_max_bookings' => 5, '_bookings_count' => 5), 'exhausted'),
            'over the cap'     => array(array('_max_bookings' => 5, '_bookings_count' => 6), 'exhausted'),
        );
    }

    /**
     * @dataProvider linkStates
     *
     * @param array<string, mixed> $meta Stored meta.
     */
    public function test_state_reports_the_first_blocking_reason(array $meta, string $reason): void
    {
        $item = (new FakeCalendarStore())->seed(81, BookingLinkDefinition::TYPE, 'Link', $meta);

        $state = BookingLinkDefinition::state($item);

        $this->assertSame('' === $reason, $state['usable']);
        $this->assertSame($reason, $state['reason']);
    }

    public function test_a_draft_link_is_inactive(): void
    {
        $item = (new FakeCalendarStore())->seed(
            82,
            BookingLinkDefinition::TYPE,
            'Link',
            array(),
            '',
            'draft'
        );

        $state = BookingLinkDefinition::state($item);

        $this->assertFalse($state['usable']);
        $this->assertSame('inactive', $state['reason']);
    }

    public function test_the_row_never_exposes_the_stored_verifier(): void
    {
        $item = (new FakeCalendarStore())->seed(
            83,
            BookingLinkDefinition::TYPE,
            'Link',
            array(
                '_token_hash' => hash('sha256', 'secret'),
                '_appointment_type' => 'General',
            )
        );

        $row = BookingLinkDefinition::row($item);

        $this->assertArrayNotHasKey('_token_hash', $row);
        $this->assertArrayNotHasKey('token_hash', $row);
        $this->assertSame('General', $row['appointment_type']);
    }
}
