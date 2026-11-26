<?php

/**
 * Shared rules for the appointment triage readers.
 *
 * Proposal 164 section 164.85. `get_no_show_appointments` and
 * `get_unconfirmed_bookings` ask two different questions about the same
 * record, and both must agree with the writers about the meta keys.
 *
 * ## The keys the writers use
 *
 * WordPress's triage tools were written against a schema the writers do not
 * produce, so neither could ever match an appointment the toolkit created:
 *
 * - status was `_appointment_status`, and the value `no_show` (underscore);
 *   the writers store `_status` with the hyphenated `no-show`.
 * - the date was `_appointment_date`, which no writer sets; times live in
 *   `_start_time`.
 * - the service was `_service_type`; the writer's field is `_appointment_type`.
 * - notes were `_appointment_notes`; the writer stores them as the record
 *   content.
 *
 * The projection below keeps the WordPress row keys for clients, but every
 * value now comes from the key the writer actually wrote.
 *
 * ## What "unconfirmed" means
 *
 * An appointment is unconfirmed when its status is `pending` and no
 * confirmation timestamp exists. `create_appointment` now stamps
 * `_confirmation_sent_at` when its notification is accepted, so a booking
 * confirmed at creation is not listed again. WordPress also matched the
 * status `unconfirmed`, which its own writer could never produce.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Support\Text;

final class AppointmentTriage
{
    /** `limit` bounds, as WordPress declared them. */
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 500;

    /** The status a missed appointment carries. */
    public const NO_SHOW = 'no-show';

    /** The status a booking awaiting confirmation carries. */
    public const PENDING = 'pending';

    /**
     * The status clause.
     *
     * @return array<string, string>
     */
    public static function statusClause(string $status): array
    {
        return array(
            'key'     => '_status',
            'value'   => $status,
            'compare' => '=',
        );
    }

    /**
     * The "no confirmation timestamp" clause.
     *
     * @return array<string, string>
     */
    public static function unconfirmedClause(): array
    {
        return array(
            'key'     => '_confirmation_sent_at',
            'compare' => 'NOT EXISTS',
        );
    }

    /**
     * The appointment-type clause, or null when the filter is empty.
     *
     * The argument keeps WordPress's name, `service_type`, but the value is the
     * `_appointment_type` the writers store, normalised the same way.
     *
     * @return array<string, string>|null
     */
    public static function serviceClause(string $serviceType): ?array
    {
        $serviceType = Text::sanitizeKey($serviceType);

        if ('' === $serviceType) {
            return null;
        }

        return array(
            'key'     => '_appointment_type',
            'value'   => $serviceType,
            'compare' => '=',
        );
    }

    /**
     * Validate the date window and build its clauses.
     *
     * `date_from` includes the whole day from 00:00:00 and `date_to` includes
     * the whole day to 23:59:59, both against the stored UTC start time.
     *
     * @return array{
     *     clauses: list<array<string, string>>,
     *     error: array{field: string, message: string}|null
     * }
     */
    public static function window(mixed $from, mixed $to): array
    {
        $fromDate = self::optionalDate($from);

        if (null === $fromDate) {
            return array(
                'clauses' => array(),
                'error'   => array(
                    'field'   => 'date_from',
                    'message' => 'date_from is not a valid date.',
                ),
            );
        }

        $toDate = self::optionalDate($to);

        if (null === $toDate) {
            return array(
                'clauses' => array(),
                'error'   => array(
                    'field'   => 'date_to',
                    'message' => 'date_to is not a valid date.',
                ),
            );
        }

        if ('' !== $fromDate && '' !== $toDate && $fromDate > $toDate) {
            return array(
                'clauses' => array(),
                'error'   => array(
                    'field'   => 'date_from',
                    'message' => 'date_from cannot be later than date_to.',
                ),
            );
        }

        $clauses = array();

        if ('' !== $fromDate) {
            $clauses[] = array(
                'key'     => '_start_time',
                'value'   => $fromDate . ' 00:00:00',
                'compare' => '>=',
            );
        }

        if ('' !== $toDate) {
            $clauses[] = array(
                'key'     => '_start_time',
                'value'   => $toDate . ' 23:59:59',
                'compare' => '<=',
            );
        }

        return array('clauses' => $clauses, 'error' => null);
    }

    /**
     * Read the matching appointments.
     *
     * @param list<array<string, string>> $clauses Meta clauses.
     * @param string                      $order   Scan direction (id order).
     *
     * @return array{items: list<ContentItem>, total: int, scanned: int}
     */
    public static function scan(ContentStoreInterface $content, array $clauses, string $order = 'DESC'): array
    {
        $metaQuery = array('relation' => 'AND');

        foreach ($clauses as $clause) {
            $metaQuery[] = $clause;
        }

        return CalendarScan::scan(
            $content,
            new ContentQuery(
                types: array(AppointmentDefinition::TYPE),
                statuses: array('publish'),
                metaQuery: $metaQuery,
                orderBy: 'id',
                order: 'ASC' === strtoupper($order) ? 'ASC' : 'DESC',
            )
        );
    }

    /**
     * Sort by start time, then id.
     *
     * @param list<ContentItem> $items Appointments.
     */
    public static function sort(array &$items, bool $descending): void
    {
        usort(
            $items,
            static function (ContentItem $a, ContentItem $b) use ($descending): int {
                $order = self::compare($a, $b);

                return $descending ? -$order : $order;
            }
        );
    }

    /**
     * The triage row, keeping the WordPress keys.
     *
     * @return array<string, mixed>
     */
    public static function row(ContentItem $item): array
    {
        $meta  = $item->meta;
        $start = AppointmentDefinition::string($meta['_start_time'] ?? '');
        $end   = AppointmentDefinition::string($meta['_end_time'] ?? '');

        return array(
            'id'               => $item->id,
            'client_name'      => AppointmentDefinition::string($meta['_client_name'] ?? ''),
            'client_email'     => AppointmentDefinition::string($meta['_client_email'] ?? ''),
            'client_phone'     => AppointmentDefinition::string($meta['_client_phone'] ?? ''),
            'appointment_date' => '' !== $start ? substr($start, 0, 10) : '',
            'start_time'       => $start,
            'end_time'         => $end,
            'start_time_iso'   => AppointmentDefinition::iso($start),
            'end_time_iso'     => AppointmentDefinition::iso($end),
            'service_type'     => AppointmentDefinition::string($meta['_appointment_type'] ?? ''),
            'status'           => AppointmentDefinition::string($meta['_status'] ?? ''),
            'notes'            => $item->content,
            'created_at'       => $item->createdAt->format('c'),
            'updated_at'       => $item->updatedAt->format('c'),
        );
    }

    /**
     * Compare two appointments by start time, then id.
     */
    public static function compare(ContentItem $a, ContentItem $b): int
    {
        $order = AppointmentDefinition::string($a->meta['_start_time'] ?? '')
            <=> AppointmentDefinition::string($b->meta['_start_time'] ?? '');

        return 0 !== $order ? $order : ( $a->id <=> $b->id );
    }

    /**
     * An optional date argument: '' when absent or empty, null when invalid.
     */
    private static function optionalDate(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        return AppointmentDefinition::date($value);
    }
}
