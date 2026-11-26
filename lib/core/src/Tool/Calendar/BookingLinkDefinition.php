<?php

/**
 * Shared rules for public booking links.
 *
 * Proposal 164 section 164.87. WordPress's `generate_booking_link` stored a
 * 32-character `wp_generate_password()` token in plaintext meta and returned
 * `home_url( '/book/{token}' )`. Two things cannot cross the port unchanged: the
 * runtime has no site URL, and a plaintext token turns every database copy — a
 * backup, a support export, a read replica — into a set of working booking
 * links.
 *
 * ## What is stored
 *
 * | key | meaning |
 * |---|---|
 * | `_token_hash` | `hash( 'sha256', $token )`, the only copy of the secret |
 * | `_appointment_type` | free-text label, also used in the record title |
 * | `_duration_minutes` | the link's default appointment length |
 * | `_expiry_days` | the requested window, echoed for round-trip |
 * | `_expires_at` | absolute UTC deadline, **authoritative** |
 * | `_max_bookings` | `0` means unlimited |
 * | `_bookings_count` | incremented by the resolver, starts at `0` |
 *
 * `_expires_at` is the authority because it is absolute. Recomputing from
 * `_expiry_days` later would silently move a deadline every time the record was
 * read; the relative value is kept only so an exported record reads back the
 * same numbers it was created with. An empty `_expires_at` means "no expiry",
 * which is what WordPress's `expiry_days: 0` meant.
 *
 * ## Resolution is not a tool in this slice
 *
 * WordPress served `/book/{token}` from the front end, not from a tool, so there
 * is no resolution slug to port. `findByToken()` and `state()` are the contract
 * that surface needs, kept here so a later route cannot re-derive the rules
 * differently. An unguessable token is **not** an authorisation check on its
 * own (OWASP): the record must also be published, unexpired and under its
 * booking cap before a resolution may proceed.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;

final class BookingLinkDefinition
{
    /** The content type WordPress stored booking links under. */
    public const TYPE = 'mcp_booking_link';

    /** The public path prefix a host route serves. */
    public const PATH_PREFIX = '/book/';

    /** The label used when the caller names no appointment type. */
    public const DEFAULT_APPOINTMENT_TYPE = 'general';

    /** The ceiling on the echoed appointment-type label, in bytes. */
    public const MAX_APPOINTMENT_TYPE = 100;

    /** Entropy of a link token: 32 bytes, hex-encoded to 64 characters. */
    public const TOKEN_BYTES = 32;

    /**
     * The longest expiry window, in days.
     *
     * `3650` is ten years. WordPress accepted any `absint` here; a link that
     * outlives the business is not an invitation, so the value is refused
     * beyond the ceiling rather than silently clamped.
     */
    public const MAX_EXPIRY_DAYS = 3650;

    public const META_TOKEN_HASH = '_token_hash';

    public const META_APPOINTMENT_TYPE = '_appointment_type';

    public const META_DURATION = '_duration_minutes';

    public const META_EXPIRY_DAYS = '_expiry_days';

    public const META_EXPIRES_AT = '_expires_at';

    public const META_MAX_BOOKINGS = '_max_bookings';

    public const META_BOOKINGS_COUNT = '_bookings_count';

    public const META_CREATED_BY = '_created_by';

    public const META_CREATED_AT = '_created_at';

    /**
     * Mint a link token.
     *
     * 256 bits from `random_bytes()`, hex-encoded so the value is URL-safe
     * without further escaping. WordPress's `wp_generate_password( 32, false )`
     * drew from a 62-character alphabet; hex trades a longer string for an
     * unambiguous CSPRNG and no character-set assumptions in a path segment.
     */
    public static function token(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * The stored verifier for a token.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The public path a token resolves at.
     */
    public static function path(string $token): string
    {
        return self::PATH_PREFIX . $token;
    }

    /**
     * The record title, matching WordPress's format.
     */
    public static function title(string $appointmentType): string
    {
        return sprintf('Booking Link: %s', $appointmentType);
    }

    /**
     * The absolute deadline to store, or `''` for no expiry.
     */
    public static function expiresAt(int $days, DateTimeImmutable $now): string
    {
        if ($days <= 0) {
            return '';
        }

        return $now
            ->setTimezone(new DateTimeZone('UTC'))
            ->modify('+' . $days . ' days')
            ->format(AppointmentDefinition::TIME_FORMAT);
    }

    /**
     * The published record a token belongs to, or null.
     *
     * The token is hashed before the query, so a caller cannot tell "wrong
     * token" from "no such link" by timing: both compare one hash against the
     * stored `_token_hash`.
     */
    public static function findByToken(ContentStoreInterface $content, string $token): ?ContentItem
    {
        $token = trim($token);

        if ('' === $token) {
            return null;
        }

        $page = $content->query(
            new ContentQuery(
                types: array(self::TYPE),
                statuses: array('publish'),
                metaQuery: array(
                    'relation' => 'AND',
                    array(
                        'key'   => self::META_TOKEN_HASH,
                        'value' => self::hash($token),
                    ),
                ),
                orderBy: 'id',
                order: 'ASC',
                page: 1,
                perPage: 1,
            )
        );

        foreach ($page->items as $item) {
            if ($item instanceof ContentItem) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Whether a resolved link may be used, and why not when it may not.
     *
     * Fail-closed: an expiry that cannot be read blocks the link rather than
     * granting an unbounded one.
     *
     * @return array{usable: bool, reason: string}
     */
    public static function state(ContentItem $item, ?DateTimeImmutable $now = null): array
    {
        if ('publish' !== $item->status) {
            return array('usable' => false, 'reason' => 'inactive');
        }

        $expires = $item->meta[ self::META_EXPIRES_AT ] ?? '';
        $expires = is_scalar($expires) ? trim((string) $expires) : '';

        if ('' !== $expires) {
            $deadline = AppointmentDefinition::parseTime($expires);

            if (null === $deadline) {
                return array('usable' => false, 'reason' => 'unreadable_expiry');
            }

            $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

            if ($now >= $deadline) {
                return array('usable' => false, 'reason' => 'expired');
            }
        }

        $max   = (int) ( $item->meta[ self::META_MAX_BOOKINGS ] ?? 0 );
        $count = (int) ( $item->meta[ self::META_BOOKINGS_COUNT ] ?? 0 );

        if ($max > 0 && $count >= $max) {
            return array('usable' => false, 'reason' => 'exhausted');
        }

        return array('usable' => true, 'reason' => '');
    }

    /**
     * The API projection of a booking link.
     *
     * The token hash is deliberately absent: it is a verifier, not a field a
     * caller has any use for, and a payload that carries it invites a caller to
     * treat it as the link.
     *
     * @return array<string, mixed>
     */
    public static function row(ContentItem $item): array
    {
        $meta = $item->meta;

        return array(
            'id'               => $item->id,
            'appointment_type' => self::string($meta[ self::META_APPOINTMENT_TYPE ] ?? ''),
            'duration_minutes' => is_numeric($meta[ self::META_DURATION ] ?? null)
                ? (int) $meta[ self::META_DURATION ]
                : AppointmentDefinition::DEFAULT_DURATION,
            'expiry_days'      => (int) ( $meta[ self::META_EXPIRY_DAYS ] ?? 0 ),
            'expires_at'       => AppointmentDefinition::iso($meta[ self::META_EXPIRES_AT ] ?? null),
            'max_bookings'     => (int) ( $meta[ self::META_MAX_BOOKINGS ] ?? 0 ),
            'bookings_count'   => (int) ( $meta[ self::META_BOOKINGS_COUNT ] ?? 0 ),
            'created_at'       => AppointmentDefinition::iso($meta[ self::META_CREATED_AT ] ?? null),
            'created_by'       => (int) ( $meta[ self::META_CREATED_BY ] ?? 0 ),
            'status'           => $item->status,
        );
    }

    /**
     * A scalar meta value as a string; arrays become ''.
     */
    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
