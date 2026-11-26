<?php

/**
 * Duplicate detection across CRM lead and customer records.
 *
 * Ported from `WP_MCP_AI_Tool_Scan_Duplicate_Contacts`: the same three
 * matchers (email, phone, name), the same exact-match confidence of 100, the
 * same Levenshtein name score with an 80 default threshold, the same group
 * shape, and a `read` capability.
 *
 * ## Scope: leads and customers, not toolkit contacts
 *
 * WordPress scans `mcp_ai_lead` and `mcp_ai_customer`. It does **not** scan
 * `mcp_crm_contacts`, the store `manage_crm_contact` writes to. That is an
 * upstream gap, not a porting choice: the two tools in this slice do not share
 * a store. The port keeps the WordPress scope and a test pins it. Widening the
 * scan would report groups WordPress never reports, so it is left to the owner
 * as a follow-up rather than decided here.
 *
 * ## The dead key family, bridged
 *
 * The WordPress original reads `_contact_email` and `_contact_phone`. Nothing
 * in the CRM toolkit writes either key (checked across
 * `addons/pro/includes/tools/crm/`); leads and customers store `email` and
 * `phone` through the data store, unprefixed. Ported literally, two of the
 * three matchers would be dead and the tool would only ever compare names.
 *
 * The port reads the legacy underscore keys first, then the live keys. This is
 * a documented deviation from WordPress and an upstream bug candidate, the
 * same decision §164.64 made for activities.
 *
 * ## Cost
 *
 * WordPress runs one `posts_per_page => -1` query and then compares every
 * contact with every other. The core contract has no "all rows" idiom, so the
 * port pages in 200-row chunks and performs the same O(n²) comparison. A very
 * large CRM therefore costs what it costs in WordPress.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Crm;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;

class ScanDuplicateContactsTool extends CrmTool
{
    /** The matchers the schema offers. */
    public const MATCH_FIELDS = array('email', 'phone', 'name', 'all');

    /** The types WordPress scans, in the shorthand this tool reports. */
    private const SCANNED_TYPES = array(
        self::LEAD_TYPE     => 'lead',
        self::CUSTOMER_TYPE => 'customer',
    );

    /** Rows per store page while collecting the corpus. */
    private const PAGE_CHUNK = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        ContentStoreInterface $content,
        ?SettingsStoreInterface $settings = null,
    ) {
        parent::__construct($e, $content, $settings);
    }

    public function getSlug(): string
    {
        return 'scan_duplicate_contacts';
    }

    public function getName(): string
    {
        return 'Scan Duplicate Contacts';
    }

    public function getDescription(): string
    {
        return 'Scans the CRM for potential duplicate contacts based on email, phone, or name similarity. Returns grouped duplicates with match confidence scores to help identify and merge redundant records.';
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
                'match_field'    => array(
                    'type'        => 'string',
                    'description' => 'Field to use for duplicate detection. Default: all.',
                    'enum'        => self::MATCH_FIELDS,
                    'default'     => 'all',
                ),
                'min_similarity' => array(
                    'type'        => 'integer',
                    'description' => 'Minimum similarity percentage (0-100) to consider a match. Default: 80.',
                    'default'     => 80,
                    'minimum'     => 0,
                    'maximum'     => 100,
                ),
                'limit'          => array(
                    'type'        => 'integer',
                    'description' => 'Maximum number of duplicate groups to return. Default: 200.',
                    'default'     => 200,
                    'minimum'     => 1,
                    'maximum'     => 1000,
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $matchField    = $this->key($this->stringParam($arguments, 'match_field', 'all'));
        $minSimilarity = min(max($this->intParam($arguments, 'min_similarity', 80), 0), 100);
        $limit         = min(max($this->intParam($arguments, 'limit', 200), 1), 1000);

        try {
            $contacts = $this->allContacts();
        } catch (\Throwable $e) {
            return $this->errors->create('duplicate_scan_failed', $e->getMessage());
        }

        $duplicates = array();

        if ('all' === $matchField || 'email' === $matchField) {
            $duplicates = array_merge($duplicates, $this->scanByField($contacts, 'email'));
        }

        if ('all' === $matchField || 'phone' === $matchField) {
            $duplicates = array_merge($duplicates, $this->scanByField($contacts, 'phone'));
        }

        if ('all' === $matchField || 'name' === $matchField) {
            $duplicates = array_merge($duplicates, $this->scanByName($contacts, $minSimilarity));
        }

        $duplicates = array_slice($this->deduplicateGroups($duplicates), 0, $limit);

        return $this->success(
            sprintf('Found %d potential duplicate groups.', count($duplicates)),
            array(
                'match_field'      => $matchField,
                'min_similarity'   => $minSimilarity,
                'total_contacts'   => count($contacts),
                'duplicate_groups' => count($duplicates),
                'duplicates'       => $duplicates,
            )
        );
    }

    /**
     * Every lead and customer, paged rather than fetched "all".
     *
     * @return list<array{id:int, type:string, title:string, email:string, phone:string}>
     */
    private function allContacts(): array
    {
        $contacts = array();
        $page     = 1;

        do {
            $result = $this->content->query(
                new ContentQuery(
                    types: array_keys(self::SCANNED_TYPES),
                    statuses: array('publish'),
                    orderBy: 'date',
                    order: 'DESC',
                    page: $page,
                    perPage: self::PAGE_CHUNK,
                )
            );

            foreach ($result->items as $item) {
                if (! $item instanceof ContentItem || ! isset(self::SCANNED_TYPES[ $item->type ])) {
                    continue;
                }

                $meta = $item->meta;

                $contacts[] = array(
                    'id'    => $item->id,
                    'type'  => self::SCANNED_TYPES[ $item->type ],
                    'title' => $item->title,
                    // Legacy underscore keys first; the live data-store keys second.
                    'email' => $this->normalizeEmail($this->text($meta['_contact_email'] ?? $meta['email'] ?? '')),
                    'phone' => $this->normalizePhone($this->text($meta['_contact_phone'] ?? $meta['phone'] ?? '')),
                );
            }

            $exhausted = count($result->items) < self::PAGE_CHUNK || $page >= $result->totalPages;
            ++$page;
        } while (! $exhausted);

        return $contacts;
    }

    /**
     * Exact matches on a normalized email or phone.
     *
     * @param list<array{id:int, type:string, title:string, email:string, phone:string}> $contacts
     * @return list<array<string, mixed>>
     */
    private function scanByField(array $contacts, string $field): array
    {
        $indexed = array();

        foreach ($contacts as $contact) {
            $value = (string) $contact[ $field ];

            if ('' === $value) {
                continue;
            }

            $indexed[ $value ][] = $contact;
        }

        $duplicates = array();

        foreach ($indexed as $value => $group) {
            if (count($group) < 2) {
                continue;
            }

            $duplicates[] = array(
                'match_field' => $field,
                'match_value' => 'email' === $field ? (string) $value : $this->maskPhone((string) $value),
                'similarity'  => 100,
                'contacts'    => array_map(
                    static fn (array $contact): array => array(
                        'id'    => $contact['id'],
                        'type'  => $contact['type'],
                        'title' => $contact['title'],
                    ),
                    $group
                ),
            );
        }

        return $duplicates;
    }

    /**
     * Name pairs at or above the similarity threshold.
     *
     * @param list<array{id:int, type:string, title:string, email:string, phone:string}> $contacts
     * @return list<array<string, mixed>>
     */
    private function scanByName(array $contacts, int $minSimilarity): array
    {
        $duplicates = array();
        $seenPairs  = array();
        $count      = count($contacts);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $nameA = strtolower(trim($contacts[ $i ]['title']));
                $nameB = strtolower(trim($contacts[ $j ]['title']));

                if ('' === $nameA || '' === $nameB) {
                    continue;
                }

                $pairKey = min($contacts[ $i ]['id'], $contacts[ $j ]['id'])
                    . '_' . max($contacts[ $i ]['id'], $contacts[ $j ]['id']);

                if (isset($seenPairs[ $pairKey ])) {
                    continue;
                }

                $seenPairs[ $pairKey ] = true;

                $similarity = $this->nameSimilarity($nameA, $nameB);

                if ($similarity >= $minSimilarity) {
                    $duplicates[] = array(
                        'match_field' => 'name',
                        'match_value' => $nameA . ' ↔ ' . $nameB,
                        'similarity'  => $similarity,
                        'contacts'    => array(
                            array(
                                'id'    => $contacts[ $i ]['id'],
                                'type'  => $contacts[ $i ]['type'],
                                'title' => $contacts[ $i ]['title'],
                            ),
                            array(
                                'id'    => $contacts[ $j ]['id'],
                                'type'  => $contacts[ $j ]['type'],
                                'title' => $contacts[ $j ]['title'],
                            ),
                        ),
                    );
                }
            }
        }

        return $duplicates;
    }

    /**
     * Levenshtein-based similarity, as WordPress computes it.
     */
    private function nameSimilarity(string $a, string $b): int
    {
        if ($a === $b) {
            return 100;
        }

        $maxLength = max(strlen($a), strlen($b));

        if (0 === $maxLength) {
            return 100;
        }

        $distance = levenshtein($a, $b);

        return (int) round((1 - ( $distance / $maxLength )) * 100);
    }

    /**
     * Drop groups whose sorted id sets have already been reported.
     *
     * @param list<array<string, mixed>> $groups
     * @return list<array<string, mixed>>
     */
    private function deduplicateGroups(array $groups): array
    {
        $seenPairs = array();
        $unique    = array();

        foreach ($groups as $group) {
            $contacts = is_array($group['contacts'] ?? null) ? $group['contacts'] : array();

            if (count($contacts) < 2) {
                continue;
            }

            $ids = array_column($contacts, 'id');
            sort($ids);

            $pairKey = implode('_', $ids);

            if (isset($seenPairs[ $pairKey ])) {
                continue;
            }

            $seenPairs[ $pairKey ] = true;
            $unique[]              = $group;
        }

        return $unique;
    }

    /**
     * Lowercased email when valid, otherwise empty.
     *
     * WordPress calls `is_email()`; the core uses `filter_var()`, which accepts
     * the same ordinary addresses and likewise rejects malformed ones.
     */
    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));

        return false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * Digits only, and at least seven of them.
     */
    private function normalizePhone(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', trim($phone));

        return strlen($digits) >= 7 ? $digits : '';
    }

    /**
     * Mask all but the last four digits.
     */
    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($phone, -4);
    }
}
