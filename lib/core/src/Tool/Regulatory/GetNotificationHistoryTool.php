<?php

/**
 * Read the notification send history.
 *
 * Port of `WP_MCP_AI_Tool_Get_Notification_History` (Proposal 164 section
 * 164.161). Reads `mcp_ai_notification_log` items, newest first.
 *
 * ## The store has one writer, and it is the status-change sender
 *
 * In WordPress the history option was written only by
 * `send_status_change_notification`. Expiry alerts logged to a different option
 * and configuration changes to a third, so "notification history" here has never
 * contained anything but status-change entries. The port keeps the store's shape
 * — the sender writes to it — and does not pretend to show a unified history of
 * every notification the toolkit can send.
 *
 * ## The same three faults as the workflow log
 *
 * The filter guards read `isset( $entry['type'] ) && $entry['type'] !== $type`,
 * so an entry without the field **passed** the guard and was kept; filtering by
 * type returned that type plus every entry missing it. `by_type` counted the
 * whole store while sitting beside `filtered_results`, which counted the sliced
 * result. Dates went through `strtotime()` on both sides, so an unreadable
 * timestamp became 1970.
 *
 * The port compares unconditionally, counts every statistic over the matched
 * set, compares the first ten characters of the timestamp against the `Y-m-d`
 * argument, counts unreadable timestamps instead of dating them, and reports
 * `total` (returned), `matched` (before the limit) and `store_entries`
 * separately.
 *
 * ## The 100-entry cap is not ported
 *
 * `array_slice( $history, -100 )` was an option-size decision: an option loads on
 * every request. A filtered query does not, so the log keeps growing and
 * `store_entries` makes that visible.
 *
 * Gated on `read`, matching the capability the WordPress handler checked.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Regulatory;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;

class GetNotificationHistoryTool extends RegulatoryTool
{
    /** The largest number of entries one call returns. */
    protected const MAX_LIMIT = 500;

    /** The number of entries returned when none is given. */
    protected const DEFAULT_LIMIT = 50;

    /** The meta keys an entry always carries. */
    protected const KNOWN_KEYS = array(
        'timestamp',
        'user_id',
        'registration_id',
        'type',
        'old_status',
        'new_status',
        'recipients',
        'emails_sent',
    );

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'get_notification_history';
    }

    public function getName(): string
    {
        return 'Get Notification History';
    }

    public function getDescription(): string
    {
        return 'Reads recorded notification sends, newest first, filtered by type, registration and date range. The store is written by send_status_change_notification, so it holds status-change entries; statistics are counted over the matched set and store_entries reports the whole log.';
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'notification_type' => array(
                    'type'        => 'string',
                    'description' => 'Only entries of this type.',
                    'enum'        => self::NOTIFICATION_TYPES,
                ),
                'registration_id'   => array(
                    'type'        => 'integer',
                    'description' => 'Only entries for this registration.',
                    'minimum'     => 1,
                ),
                'start_date'        => array(
                    'type'        => 'string',
                    'description' => 'Earliest entry date, inclusive (YYYY-MM-DD).',
                    'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
                ),
                'end_date'          => array(
                    'type'        => 'string',
                    'description' => 'Latest entry date, inclusive (YYYY-MM-DD).',
                    'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
                ),
                'limit'             => array(
                    'type'        => 'integer',
                    'description' => 'Maximum entries to return. Default 50.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $type           = trim($this->stringParam($arguments, 'notification_type'));
        $registrationId = max(0, $this->intParam($arguments, 'registration_id'));
        $startDate      = trim($this->stringParam($arguments, 'start_date'));
        $endDate        = trim($this->stringParam($arguments, 'end_date'));
        $limit          = $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT);

        $fields = array();

        if ('' !== $type && ! in_array($type, self::NOTIFICATION_TYPES, true)) {
            $fields['notification_type'] = array(
                'One of: ' . implode(', ', self::NOTIFICATION_TYPES) . '.',
            );
        }

        if ('' !== $startDate && ! $this->isIsoDate($startDate)) {
            $fields['start_date'] = array('A date in YYYY-MM-DD form.');
        }

        if ('' !== $endDate && ! $this->isIsoDate($endDate)) {
            $fields['end_date'] = array('A date in YYYY-MM-DD form.');
        }

        if ('' !== $startDate && '' !== $endDate && $startDate > $endDate) {
            $fields['end_date'] = array('The end date cannot precede the start date.');
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            $fields['limit'] = array(
                sprintf('A whole number from 1 to %d.', self::MAX_LIMIT),
            );
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The notification history cannot be read.', $fields);
        }

        $items = $this->collectContent(array(self::NOTIFICATION_LOG_TYPE));

        $matched    = 0;
        $unreadable = 0;
        $byType     = array();
        $pairs      = array();

        foreach ($items as $item) {
            $meta = $item->meta;

            if ('' !== $type && $this->text($meta['type'] ?? null) !== $type) {
                continue;
            }

            if ($registrationId > 0 && $this->intValue($meta['registration_id'] ?? null) !== $registrationId) {
                continue;
            }

            $stamp    = $this->text($meta['timestamp'] ?? null);
            $readable = $this->stampDate($stamp);

            if ('' === $readable) {
                ++$unreadable;

                if ('' !== $startDate || '' !== $endDate) {
                    continue;
                }
            } else {
                if ('' !== $startDate && $readable < $startDate) {
                    continue;
                }

                if ('' !== $endDate && $readable > $endDate) {
                    continue;
                }
            }

            ++$matched;

            $entryType = $this->text($meta['type'] ?? null);

            if ('' !== $entryType) {
                $byType[ $entryType ] = ( $byType[ $entryType ] ?? 0 ) + 1;
            }

            $pairs[] = array(
                'sort' => $readable,
                'id'   => $item->id,
                'row'  => $this->entryRow($item, $stamp, $entryType),
            );
        }

        // Newest first; entries without a readable timestamp sort last; the
        // content id breaks ties so paging does not depend on store order.
        usort(
            $pairs,
            static function (array $a, array $b): int {
                if ($a['sort'] !== $b['sort']) {
                    return $b['sort'] <=> $a['sort'];
                }

                return $b['id'] <=> $a['id'];
            }
        );

        $rows = array();

        foreach (array_slice($pairs, 0, $limit) as $pair) {
            $rows[] = $pair['row'];
        }

        ksort($byType);

        return $this->success(
            sprintf('Retrieved %d notification entr(ies).', count($rows)),
            array(
                'total'                 => count($rows),
                'matched'               => $matched,
                'store_entries'         => count($items),
                'truncated'             => $matched > count($rows),
                'unreadable_timestamps' => $unreadable,
                'limit'                 => $limit,
                'filters'               => array(
                    'notification_type' => $type,
                    'registration_id'   => $registrationId,
                    'start_date'        => $startDate,
                    'end_date'          => $endDate,
                ),
                'statistics'            => array('by_type' => $byType),
                'history'               => $rows,
                'retrieved_at'          => $this->nowStamp(),
            )
        );
    }

    /**
     * One returned history row. Unknown meta keys are carried under `details`.
     *
     * @return array<string, mixed>
     */
    protected function entryRow(ContentItem $item, string $stamp, string $type): array
    {
        $meta = $item->meta;

        $recipients = $meta['recipients'] ?? array();

        $row = array(
            'timestamp'       => $stamp,
            'user_id'         => $this->intValue($meta['user_id'] ?? null),
            'registration_id' => $this->intValue($meta['registration_id'] ?? null),
            'type'            => $type,
            'old_status'      => $this->text($meta['old_status'] ?? null),
            'new_status'      => $this->text($meta['new_status'] ?? null),
            'recipients'      => is_array($recipients) ? array_values($recipients) : array(),
            'emails_sent'     => $this->intValue($meta['emails_sent'] ?? null),
            'content_id'      => $item->id,
        );

        $extras = array_diff_key($meta, array_flip(self::KNOWN_KEYS));

        if (array() !== $extras) {
            $row['details'] = $extras;
        }

        return $row;
    }
}
