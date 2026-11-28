<?php

/**
 * Report which documents are expired or about to expire.
 *
 * Port of `WP_MCP_AI_Tool_Check_Document_Expiry` (Proposal 164 section
 * 164.153). Every document carrying an expiry date, bucketed into expired,
 * expiring soon and valid against a caller-supplied window.
 *
 * ## The two sorts contradicted each other
 *
 * WordPress sorted `expiring_soon` ascending — nearest expiry first, which is
 * what "most urgent first" means — and sorted `expired` **descending**, which
 * puts the *least* overdue document first. Both carried a comment claiming
 * urgency. The port orders both lists by `days_to_expiry` ascending, so the
 * overdue list starts with the registration that has been invalid longest and
 * the two lists read as one monotone sequence when concatenated.
 *
 * ## An unreadable date became a large negative number
 *
 * `strtotime` returns `false` for input it cannot parse, `false` is zero, and
 * `floor( ( 0 - time() ) / 86400 )` is roughly minus twenty thousand days. A
 * document with `expiry_date: "n/a"` was therefore reported as expired some
 * fifty-five years ago, with a day count the value does not support. The port
 * keeps the verdict — a date that cannot be read is not evidence of validity —
 * reports `days_to_expiry: null`, and lists the value in
 * `unparsed_expiry_dates`.
 *
 * ## Documents without a date are not "valid"
 *
 * Only documents with a non-empty `expiry_date` are considered. A document with
 * no expiry is not counted in `valid_count`, because a document with no
 * renewal date has not been shown valid; it simply has nothing to check here.
 * WordPress behaved the same way and never said so.
 *
 * `warning_days` is bounded to 1-365 and rejected outside that range rather
 * than silently clamped, so a caller that asks for a ten-year window is told it
 * is not a supported question.
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

class CheckDocumentExpiryTool extends RegulatoryTool
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
        return 'check_document_expiry';
    }

    public function getName(): string
    {
        return 'Check Document Expiry';
    }

    public function getDescription(): string
    {
        return 'Buckets every document that carries an expiry date into expired, expiring soon and valid against a 1-365 day window, with an alert level. A date that cannot be read is treated as expired and named in unparsed_expiry_dates rather than given a day count.';
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
                'product_id'      => array(
                    'type'        => 'integer',
                    'description' => 'Only documents attached to this product.',
                    'minimum'     => 1,
                ),
                'registration_id' => array(
                    'type'        => 'integer',
                    'description' => 'Only documents attached to this registration.',
                    'minimum'     => 1,
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
                'The expiry check cannot run.',
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

        $productId      = $this->intValue($arguments['product_id'] ?? null);
        $registrationId = $this->intValue($arguments['registration_id'] ?? null);

        $metaQuery = array('relation' => 'AND');

        if ($productId >= 1) {
            $metaQuery[] = array('key' => 'product_id', 'value' => $productId, 'compare' => '=');
        }

        if ($registrationId >= 1) {
            $metaQuery[] = array('key' => 'registration_id', 'value' => $registrationId, 'compare' => '=');
        }

        $now        = time();
        $expired    = array();
        $expiring   = array();
        $unparsed   = array();
        $validCount = 0;

        foreach ($this->collectContent(array(self::DOCUMENT_TYPE), $metaQuery) as $document) {
            $expiryDate = $this->text($document->meta['expiry_date'] ?? null);

            if ('' === $expiryDate) {
                continue;
            }

            $row  = $this->row($document, $expiryDate);
            $days = $this->daysUntil($expiryDate, $now);

            if (null === $days) {
                $row['days_to_expiry'] = null;
                $row['status']         = 'expired';

                $expired[]  = $row;
                $unparsed[] = array(
                    'document_id'   => $document->id,
                    'document_type' => $row['document_type'],
                    'expiry_date'   => $expiryDate,
                );

                continue;
            }

            $row['days_to_expiry'] = $days;

            if ($days < 0) {
                $row['status'] = 'expired';
                $expired[]     = $row;

                continue;
            }

            if ($days <= $warningDays) {
                $row['status'] = 'expiring_soon';
                $expiring[]    = $row;

                continue;
            }

            ++$validCount;
        }

        $expired  = $this->oldestFirst($expired);
        $expiring = $this->oldestFirst($expiring);

        $alertLevel = 'ok';

        if (array() !== $expired) {
            $alertLevel = 'critical';
        } elseif (array() !== $expiring) {
            $alertLevel = 'warning';
        }

        return $this->success(
            sprintf(
                '%1$d expired, %2$d expiring soon (within %4$d days), %3$d valid',
                count($expired),
                count($expiring),
                $validCount,
                $warningDays
            ),
            array(
                'alert_level'           => $alertLevel,
                'expired_count'         => count($expired),
                'expiring_soon_count'   => count($expiring),
                'valid_count'           => $validCount,
                'total_documents'       => count($expired) + count($expiring) + $validCount,
                'expired_documents'     => $expired,
                'expiring_soon'         => $expiring,
                'unparsed_expiry_dates' => $unparsed,
                'warning_days'          => $warningDays,
            )
        );
    }

    /**
     * One document row, before its bucket is decided.
     *
     * @return array<string, mixed>
     */
    private function row(ContentItem $document, string $expiryDate): array
    {
        return array(
            'id'              => $document->id,
            'title'           => $document->title,
            'document_type'   => $this->documentType($document),
            'expiry_date'     => $expiryDate,
            'product_id'      => $this->intValue($document->meta['product_id'] ?? null),
            'registration_id' => $this->intValue($document->meta['registration_id'] ?? null),
        );
    }

    /**
     * A document's type: the term when one is attached, else the meta value.
     */
    private function documentType(ContentItem $document): string
    {
        $type = $this->text($document->meta['document_type'] ?? null);

        try {
            $terms = $this->termNames(
                $this->content->getTaxonomyTerms($document->id),
                self::DOC_TYPE_TAX
            );
        } catch (\Throwable $e) {
            $terms = array();
        }

        return array() !== $terms ? $terms[0] : $type;
    }

    /**
     * Order rows by days to expiry, most overdue first, then by id.
     *
     * An unreadable date sorts ahead of a merely overdue one: it is the case
     * that needs a human most, and `null` sorts before every integer here.
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

                return array($left, $a['id']) <=> array($right, $b['id']);
            }
        );

        return $rows;
    }
}
