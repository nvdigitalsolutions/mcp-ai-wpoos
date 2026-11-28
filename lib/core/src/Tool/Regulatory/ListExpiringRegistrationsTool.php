<?php

/**
 * List registrations that are expired or expiring within a window.
 *
 * Port of `WP_MCP_AI_Tool_List_Expiring_Registrations` (Proposal 164 section
 * 164.153). A read-only view for renewal planning; the writing counterpart is
 * `send_expiry_alerts`.
 *
 * ## The declared default here was the actual one
 *
 * `include_expired` is declared `true` and read with
 * `isset( $arguments[...] ) ? (bool) ... : true`, which is the same answer for
 * an omitted argument. The port uses `boolParam( ..., true )`, which is the
 * same rule expressed once instead of twice.
 *
 * ## Expired registrations were sorted against the comment
 *
 * `expiring_soon` was sorted ascending — nearest first, correct — and `expired`
 * descending, so the most recently expired registration came first and the one
 * that had been invalid longest came last. Both sorts carried a comment saying
 * "most urgent first". The port orders both ascending on `days_to_expiry`, so
 * the overdue list leads with the longest-invalid registration.
 *
 * ## An unreadable date is expired and named
 *
 * `strtotime( $date )` returning `false` produced `false - time()` — a large
 * negative day count — so a registration whose date could not be read appeared
 * as expired decades ago. The port keeps the verdict, reports
 * `days_to_expiry: null`, and lists the value in `unparsed_expiry_dates`.
 *
 * ## The horizon is a window, not a filter on the past
 *
 * A registration expiring beyond `warning_days` is excluded and is not reported
 * as anything; that is what the tool is for. `include_expired: false` removes
 * the overdue list, which makes the response a forward-looking list only.
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

class ListExpiringRegistrationsTool extends RegulatoryTool
{
    /** The default warning window, in days. */
    private const DEFAULT_WARNING_DAYS = 90;

    /** The bounds the schema and the handler agree on. */
    private const MIN_WARNING_DAYS = 1;

    private const MAX_WARNING_DAYS = 365;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'list_expiring_registrations';
    }

    public function getName(): string
    {
        return 'List Expiring Registrations';
    }

    public function getDescription(): string
    {
        return 'Lists registrations expiring within a 1-365 day window, and expired ones unless include_expired is false, ordered by days to expiry with the longest-invalid or nearest first. A date that cannot be read is treated as expired and named in unparsed_expiry_dates.';
    }

    public function getRequiredCapability(): string
    {
        // WordPress checks `read` in the handler and declares `edit_posts`.
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'warning_days'    => array(
                    'type'        => 'integer',
                    'description' => 'Days ahead that count as expiring soon. Default 90.',
                    'minimum'     => self::MIN_WARNING_DAYS,
                    'maximum'     => self::MAX_WARNING_DAYS,
                    'default'     => self::DEFAULT_WARNING_DAYS,
                ),
                'country'         => array(
                    'type'        => 'string',
                    'description' => 'Only registrations in this country.',
                    'maxLength'   => 10,
                ),
                'include_expired' => array(
                    'type'        => 'boolean',
                    'description' => 'Include registrations that have already expired. Default true.',
                    'default'     => true,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $warningDays = $this->intParam($arguments, 'warning_days', self::DEFAULT_WARNING_DAYS);

        if ($warningDays < self::MIN_WARNING_DAYS || $warningDays > self::MAX_WARNING_DAYS) {
            return $this->errors->validationFailed(
                'The expiry list cannot be built.',
                array(
                    'warning_days' => array(
                        sprintf(
                            'A whole number of days from %d to %d.',
                            self::MIN_WARNING_DAYS,
                            self::MAX_WARNING_DAYS
                        ),
                    ),
                )
            );
        }

        $country       = trim($this->stringParam($arguments, 'country'));
        $includeExpired = $this->boolParam($arguments, 'include_expired', true);

        $metaQuery = array('relation' => 'AND');

        if ('' !== $country) {
            $metaQuery[] = array('key' => 'country', 'value' => $country, 'compare' => '=');
        }

        $now          = time();
        $expired      = array();
        $expiringSoon = array();
        $unparsed     = array();

        foreach ($this->collectContent(array(self::REGISTRATION_TYPE), $metaQuery) as $registration) {
            $expiryDate = $this->text($registration->meta['expiry_date'] ?? null);

            if ('' === $expiryDate) {
                continue;
            }

            $days = $this->daysUntil($expiryDate, $now);
            $row  = $this->row($registration, $expiryDate, $days);

            if (null === $days) {
                // The diagnostic is reported even when expired rows are
                // excluded: a date nobody can read is a data fault the caller
                // should see, not a result the filter is entitled to hide.
                $unparsed[] = array(
                    'registration_id' => $registration->id,
                    'expiry_date'     => $expiryDate,
                );

                if (! $includeExpired) {
                    continue;
                }

                $expired[] = $row;

                continue;
            }

            if ($days < 0) {
                if (! $includeExpired) {
                    continue;
                }

                $expired[] = $row;

                continue;
            }

            if ($days > $warningDays) {
                continue;
            }

            $expiringSoon[] = $row;
        }

        $expired      = $this->oldestFirst($expired);
        $expiringSoon = $this->oldestFirst($expiringSoon);

        return $this->success(
            sprintf(
                '%1$d expired registrations, %2$d expiring within %3$d days',
                count($expired),
                count($expiringSoon),
                $warningDays
            ),
            array(
                'expired_count'         => count($expired),
                'expiring_soon_count'   => count($expiringSoon),
                'total_returned'        => count($expired) + count($expiringSoon),
                'expired'               => $expired,
                'expiring_soon'         => $expiringSoon,
                'unparsed_expiry_dates' => $unparsed,
                'warning_days'          => $warningDays,
                'include_expired'       => $includeExpired,
                'country'               => $country,
            )
        );
    }

    /**
     * One registration row.
     *
     * @return array<string, mixed>
     */
    private function row(ContentItem $registration, string $expiryDate, ?int $days): array
    {
        $productId = $this->intValue($registration->meta['product_id'] ?? null);

        $row = array(
            'id'             => $registration->id,
            'title'          => $registration->title,
            'product_id'     => $productId,
            'country'        => $this->text($registration->meta['country'] ?? null),
            'authority'      => $this->text($registration->meta['authority'] ?? null),
            'cos_number'     => $this->text($registration->meta['cos_number'] ?? null),
            'expiry_date'    => $expiryDate,
            'days_to_expiry' => $days,
            'is_expired'     => null === $days || $days < 0,
        );

        try {
            $statuses = $this->termNames(
                $this->content->getTaxonomyTerms($registration->id),
                self::STATUS_TAX
            );
        } catch (\Throwable $e) {
            $statuses = array();
        }

        if (array() !== $statuses) {
            $row['status'] = $statuses[0];
        }

        if ($productId >= 1) {
            $product = $this->content->find($productId);

            if ($product instanceof ContentItem && self::PRODUCT_TYPE === $product->type) {
                $row['product_name'] = $product->title;
            }
        }

        return $row;
    }

    /**
     * Order rows by days to expiry, longest-invalid first, then by id.
     *
     * An unreadable date sorts ahead of a merely overdue one: it is the case
     * that needs a human most.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function oldestFirst(array $rows): array
    {
        usort(
            $rows,
            static function (array $a, array $b): int {
                $left  = $a['days_to_expiry'] ?? null;
                $right = $b['days_to_expiry'] ?? null;

                $left  = is_int($left) ? $left : PHP_INT_MIN;
                $right = is_int($right) ? $right : PHP_INT_MIN;

                $idLeft  = $a['id'] ?? 0;
                $idRight = $b['id'] ?? 0;

                return array($left, is_int($idLeft) ? $idLeft : 0)
                    <=> array($right, is_int($idRight) ? $idRight : 0);
            }
        );

        return $rows;
    }
}
