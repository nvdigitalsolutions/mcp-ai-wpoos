<?php

/**
 * Bulk import bookable services from JSON or CSV.
 *
 * Port of `WP_MCP_AI_Tool_Import_Services` (Proposal 164 section 164.86), with
 * the ingestion playbook applied: structure over filename, per-row validation,
 * a dry run that reports what would happen, idempotent matching, and a visible
 * window instead of a silent row cap.
 *
 * ## Corrections
 *
 * 1. **`batch_size` only flushed the WordPress object cache.** It never
 *    chunked the import; every row was processed in one call. The port replaces
 *    it with `offset` and `limit`, so a large import is resumable and reports
 *    `next_offset` instead of quietly stopping. `limit` defaults to 0, meaning
 *    every remaining row up to the per-call ceiling.
 * 2. **The dry run validated nothing.** It counted each non-skipped row as
 *    created, so a negative price or an unknown field looked fine and failed
 *    later. The dry run now builds each record through the same writer as the
 *    write path and reports `would create` and `would update` separately.
 * 3. **The CSV parser had no BOM, delimiter or header handling.** A UTF-8 BOM
 *    became part of the first header, so `name` was missing; a semicolon file
 *    parsed as one column. The port strips the BOM, sniffs the delimiter,
 *    normalises headers and maps common aliases, and reports ignored columns.
 * 4. **`auto_create_categories` was ineffective.** WordPress called
 *    `wp_set_object_terms()` with an unknown term even when the flag was false,
 *    which creates it anyway. The port checks the vocabulary and reports an
 *    unknown category per row.
 * 5. **Deduplication matched the exact post title.** The port matches a
 *    caller-supplied `external_id` first, then a derived `_service_key` (the
 *    slug plus the place), so a re-run is idempotent and case-insensitive.
 *
 * A service row may carry `image_urls` for source compatibility, but remote
 * images are refused with a per-row error: this runtime has no SSRF-guarded
 * media fetch. Pass `media_ids` instead.
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
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class ImportServicesTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** CSV header aliases, normalised to the service field names. */
    private const HEADERS = array(
        'name'                => 'name',
        'service'             => 'name',
        'service_name'        => 'name',
        'title'               => 'name',
        'description'         => 'description',
        'details'             => 'description',
        'duration'            => 'duration_minutes',
        'duration_minutes'    => 'duration_minutes',
        'minutes'             => 'duration_minutes',
        'price'               => 'price',
        'cost'                => 'price',
        'amount'              => 'price',
        'buffer'              => 'buffer_time_minutes',
        'buffer_time'         => 'buffer_time_minutes',
        'buffer_minutes'      => 'buffer_time_minutes',
        'buffer_time_minutes' => 'buffer_time_minutes',
        'category'            => 'category',
        'service_category'    => 'category',
        'place'               => 'place_id',
        'place_id'            => 'place_id',
        'capacity'            => 'max_participants',
        'max_pax'             => 'max_participants',
        'max_participants'    => 'max_participants',
        'tags'                => 'tags',
        'source_url'          => 'source_url',
        'url'                 => 'source_url',
        'external_id'         => 'external_id',
        'source_id'           => 'external_id',
        'sku'                 => 'external_id',
        'media_ids'           => 'media_ids',
        'images'              => 'media_ids',
    );

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'import_services';
    }

    public function getName(): string
    {
        return 'Import Services';
    }

    public function getDescription(): string
    {
        return 'Bulk-imports bookable services from a JSON array, a JSON document or CSV text, with per-row validation, a dry run, idempotent deduplication and resumable paging.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'source'                 => array(
                    'type'        => 'string',
                    'enum'        => array('json', 'json_array', 'csv'),
                    'default'     => 'json_array',
                    'description' => 'Where the rows come from.',
                ),
                'data'                   => array(
                    'type'        => 'array',
                    'description' => 'Service objects for source json_array.',
                    'items'       => array('type' => 'object'),
                ),
                'json_string'            => array(
                    'type'        => 'string',
                    'description' => 'JSON text: an array of service objects, or an object with a services array.',
                ),
                'csv_string'             => array(
                    'type'        => 'string',
                    'description' => 'CSV text with a header row.',
                ),
                'skip_existing'          => array(
                    'type'        => 'boolean',
                    'default'     => true,
                    'description' => 'Skip services that already exist.',
                ),
                'update_existing'        => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Update existing services with the supplied fields. Takes precedence over skip_existing.',
                ),
                'dedup_strategy'         => array(
                    'type'        => 'string',
                    'enum'        => array('name', 'name_and_place'),
                    'default'     => 'name',
                    'description' => 'How the fallback identity is derived when a row has no external_id.',
                ),
                'dry_run'                => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Validate and report without writing anything.',
                ),
                'offset'                 => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'default'     => 0,
                    'description' => 'Row to start at, for resuming a large import.',
                ),
                'limit'                  => array(
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'maximum'     => ServiceDefinition::MAX_ROWS,
                    'default'     => 0,
                    'description' => 'Rows to process in this call; 0 means every remaining row up to the ceiling.',
                ),
                'default_place_id'       => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Place reference applied to rows that do not carry one.',
                ),
                'auto_create_categories' => array(
                    'type'        => 'boolean',
                    'default'     => true,
                    'description' => 'Create unknown categories. When false, an unknown category is a per-row error.',
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return CapabilityFlagVocabulary::LOCAL_WRITE;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $userId = (int) ( $context['user_id'] ?? 0 );

        $parsed = $this->parse($arguments);

        if (isset($parsed['error'])) {
            return $this->errors->validationFailed($parsed['error'], array());
        }

        $rows  = $parsed['rows'];
        $total = count($rows);

        if (0 === $total) {
            return $this->errors->create('empty_data', 'No service data was provided.');
        }

        $strategy = Text::sanitizeKey($this->stringParam($arguments, 'dedup_strategy', 'name'));

        if (! in_array($strategy, array('name', 'name_and_place'), true)) {
            return $this->errors->validationFailed(
                'dedup_strategy must be name or name_and_place.',
                array('dedup_strategy' => array('Use name or name_and_place.'))
            );
        }

        $dryRun      = $this->boolParam($arguments, 'dry_run', false);
        $skip        = $this->boolParam($arguments, 'skip_existing', true);
        $update      = $this->boolParam($arguments, 'update_existing', false);
        $defaultPlace = max(0, $this->intParam($arguments, 'default_place_id', 0));
        $autoCats    = $this->boolParam($arguments, 'auto_create_categories', true);
        $dedupe      = $skip || $update;
        $allowTitle  = 'name' === $strategy;

        $offset = max(0, $this->intParam($arguments, 'offset', 0));
        $limit  = max(0, min(
            ServiceDefinition::MAX_ROWS,
            $this->intParam($arguments, 'limit', 0)
        ));

        $remaining  = max(0, $total - $offset);
        $windowSize = 0 === $limit ? min(ServiceDefinition::MAX_ROWS, $remaining) : min($limit, $remaining);
        $window     = array_slice($rows, $offset, $windowSize);

        $results = array(
            'dry_run'   => $dryRun,
            'source'    => Text::sanitizeKey($this->stringParam($arguments, 'source', 'json_array')),
            'total'     => $total,
            'offset'    => $offset,
            'limit'     => $limit,
            'window'    => $windowSize,
            'processed' => 0,
            'created'   => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'ids'       => array(),
            'errors'    => array(),
        );

        if (isset($parsed['ignored_headers']) && array() !== $parsed['ignored_headers']) {
            $results['ignored_headers'] = $parsed['ignored_headers'];
        }

        $map        = $dedupe
            ? $this->existingMap()
            : array('external' => array(), 'key' => array(), 'title' => array(), 'truncated' => false);
        $vocabulary = $autoCats ? array() : $this->categoryVocabulary();

        foreach ($window as $position => $row) {
            $index = $offset + $position;
            ++$results['processed'];

            $failure = $this->importRow(
                $row,
                $index,
                $userId,
                $defaultPlace,
                $autoCats,
                $vocabulary,
                $dedupe,
                $allowTitle,
                $skip,
                $update,
                $dryRun,
                $map,
                $results
            );

            if (null !== $failure) {
                ++$results['failed'];
                $entry = array(
                    'index' => $index,
                    'name'  => $this->rowName($row),
                    'error' => $failure,
                );

                if ('csv' === $results['source']) {
                    $entry['row'] = $index + 2;
                }

                $results['errors'][] = $entry;
            }
        }

        $nextOffset = $offset + $results['processed'];
        $complete   = $nextOffset >= $total;

        $results['complete']    = $complete;
        $results['has_more']    = ! $complete;
        $results['next_offset'] = $nextOffset;
        $results['truncated']   = (bool) ( $map['truncated'] ?? false );

        $summary = $dryRun
            ? sprintf(
                'Dry run complete: %1$d would be created, %2$d would be updated, %3$d failed, %4$d skipped.',
                $results['created'],
                $results['updated'],
                $results['failed'],
                $results['skipped']
            )
            : sprintf(
                'Import complete: %1$d created, %2$d updated, %3$d failed, %4$d skipped.',
                $results['created'],
                $results['updated'],
                $results['failed'],
                $results['skipped']
            );

        if (! $complete) {
            $summary .= sprintf(' (%1$d of %2$d rows processed.)', $results['processed'], $total);
        }

        return $this->success($summary, $results);
    }

    /**
     * Import one row, writing the counts into `$results`.
     *
     * @param mixed                $row         Raw row.
     * @param int                  $index       Absolute row index in the payload.
     * @param int                  $userId      Acting user.
     * @param int                  $defaultPlace Place applied when the row has none.
     * @param bool                 $autoCats    Whether unknown categories may be created.
     * @param array<string, bool>  $vocabulary  Known category names, lowercased.
     * @param bool                 $dedupe      Whether to match existing services.
     * @param bool                 $allowTitle  Whether a stored title may match (legacy rows).
     * @param bool                 $skip        Skip existing.
     * @param bool                 $update      Update existing.
     * @param bool                 $dryRun      Validate without writing.
     * @param array{external: array<string, int>, key: array<string, int>, title: array<string, int>, truncated: bool} $map Existing-service maps.
     * @param array<string, mixed> $results     Result accumulator.
     *
     * @return string|null The per-row error, or null when the row was handled.
     */
    private function importRow(
        mixed $row,
        int $index,
        int $userId,
        int $defaultPlace,
        bool $autoCats,
        array $vocabulary,
        bool $dedupe,
        bool $allowTitle,
        bool $skip,
        bool $update,
        bool $dryRun,
        array &$map,
        array &$results,
    ): ?string {
        if (! is_array($row)) {
            return 'The row is not an object.';
        }

        $name = $this->rowName($row);

        if ('' === $name) {
            return 'Missing required field: name.';
        }

        if ($defaultPlace > 0 && ! $this->rowHasPlace($row)) {
            $row['place_id'] = $defaultPlace;
        }

        if (! $autoCats) {
            $category = isset($row['category']) && is_scalar($row['category'])
                ? Text::singleLine((string) $row['category'])
                : '';

            if ('' !== $category && ! isset($vocabulary[ strtolower($category) ])) {
                return sprintf(
                    'Unknown category "%s"; create it first or set auto_create_categories true.',
                    $category
                );
            }
        }

        if ($this->hasRemoteImages($row)) {
            return 'Remote image import is not available in this runtime; pass media_ids instead.';
        }

        $external = ServiceDefinition::externalId($row['external_id'] ?? '');
        $place    = ServiceDefinition::place($row['place_id'] ?? 0) ?? 0;
        $key      = ServiceDefinition::key($name, $place);

        $exists     = false;
        $existingId = 0;

        if ($dedupe) {
            $found      = $this->existing($map, $external, $key, $name, $allowTitle);
            $exists     = 0 !== $found;
            $existingId = $found > 0 ? $found : 0;
        }

        $existing = $existingId > 0 ? $this->content->find($existingId) : null;

        if ($existingId > 0 && null === $existing) {
            // Deleted between the prefetch and now: treat it as new.
            $exists     = false;
            $existingId = 0;
        }

        $action = 'create';

        if ($exists && $update) {
            $action = 'update';
        } elseif ($exists && $skip) {
            ++$results['skipped'];

            return null;
        }

        try {
            $built = ServiceWriter::build($row, $existing);
        } catch (ValidationException $error) {
            return $this->fieldMessage($error);
        }

        if ($dryRun) {
            ++$results[ 'update' === $action ? 'updated' : 'created' ];

            if ('create' === $action) {
                $this->remember($map, $external, $key, -1);
                $this->rememberTitle($map, $name, -1);
            }

            return null;
        }

        try {
            $item = ServiceWriter::persist($this->content, $userId, $built, $existing);
        } catch (AccessDeniedException $error) {
            return $error->getMessage();
        } catch (ValidationException $error) {
            return $this->fieldMessage($error);
        } catch (\Throwable $error) {
            return $error->getMessage();
        }

        $results['ids'][] = $item->id;

        if ('update' === $action) {
            ++$results['updated'];
        } else {
            ++$results['created'];
        }

        $this->remember($map, $external, $key, $item->id);
        $this->rememberTitle($map, $name, $item->id);

        return null;
    }

    /**
     * Parse the requested source into rows.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array{rows: list<mixed>, ignored_headers?: list<string>, error?: string}
     */
    private function parse(array $arguments): array
    {
        $source = Text::sanitizeKey($this->stringParam($arguments, 'source', 'json_array'));

        if ('json_array' === $source) {
            $data = $arguments['data'] ?? array();

            if (! is_array($data)) {
                return array('error' => 'data must be an array of service objects.');
            }

            return array('rows' => array_values($data));
        }

        if ('json' === $source) {
            $raw = $arguments['json_string'] ?? null;

            if (! is_string($raw) || '' === trim($raw)) {
                return array('error' => 'json_string is required for source json.');
            }

            $decoded = json_decode($raw, true);

            if (JSON_ERROR_NONE !== json_last_error()) {
                return array('error' => 'Invalid JSON: ' . json_last_error_msg());
            }

            if (! is_array($decoded)) {
                return array('error' => 'The JSON must be an array of service objects or an object with a services array.');
            }

            if (isset($decoded['services']) && is_array($decoded['services'])) {
                return array('rows' => array_values($decoded['services']));
            }

            if (array_is_list($decoded)) {
                return array('rows' => $decoded);
            }

            return array('rows' => array($decoded));
        }

        if ('csv' === $source) {
            $raw = $arguments['csv_string'] ?? null;

            if (! is_string($raw) || '' === trim($raw)) {
                return array('error' => 'csv_string is required for source csv.');
            }

            return $this->csv($raw);
        }

        return array('error' => 'Unknown source; use json_array, json or csv.');
    }

    /**
     * Parse CSV text into associative rows.
     *
     * @return array{rows: list<array<string, string>>, ignored_headers?: list<string>, error?: string}
     */
    private function csv(string $raw): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            return array('error' => 'The CSV is UTF-16 encoded; convert it to UTF-8 first.');
        }

        $firstLine = strtok($raw, "\r\n");
        $delimiter = $this->sniff(false === $firstLine ? '' : $firstLine);

        $handle = fopen('php://temp', 'r+');

        if (false === $handle) {
            return array('error' => 'Could not read the CSV.');
        }

        fwrite($handle, $raw);
        rewind($handle);

        $headers = null;
        $ignored = array();
        $rows    = array();

        while (false !== ( $cells = fgetcsv($handle, 0, $delimiter, '"', '') )) {
            if (null === $headers) {
                $headers = array();

                foreach ($cells as $cell) {
                    $header = $this->header((string) $cell);

                    if ('' === $header) {
                        $headers[] = null;
                        continue;
                    }

                    if (isset(self::HEADERS[ $header ])) {
                        $headers[] = self::HEADERS[ $header ];
                    } else {
                        $headers[] = null;
                        $ignored[] = trim((string) $cell);
                    }
                }

                if (array() === array_filter($headers, static fn ($header): bool => null !== $header)) {
                    fclose($handle);

                    return array('error' => 'The CSV header row has no recognisable service columns.');
                }

                continue;
            }

            $row      = array();
            $hasValue = false;

            foreach ($headers as $column => $field) {
                if (null === $field) {
                    continue;
                }

                $value = isset($cells[ $column ]) ? trim((string) $cells[ $column ]) : '';

                if ('' !== $value) {
                    $hasValue = true;
                }

                $row[ $field ] = $value;
            }

            if ($hasValue) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        if (null === $headers) {
            return array('error' => 'The CSV has no header row.');
        }

        $parsed = array('rows' => $rows);

        if (array() !== $ignored) {
            $parsed['ignored_headers'] = array_values(array_unique($ignored));
        }

        return $parsed;
    }

    /**
     * Pick the delimiter that yields the most columns in the header line.
     */
    private function sniff(string $line): string
    {
        $best      = ',';
        $bestCount = 1;

        foreach (array(',', ';', "\t", '|') as $candidate) {
            $count = count(str_getcsv($line, $candidate, '"', ''));

            if ($count > $bestCount) {
                $best      = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Normalise a CSV header cell.
     */
    private function header(string $cell): string
    {
        $cell = strtolower(trim($cell));
        $cell = (string) preg_replace('/[\s\-]+/', '_', $cell);
        $cell = (string) preg_replace('/[^a-z0-9_]/', '', $cell);

        return trim($cell, '_');
    }

    /**
     * The existing-service maps, built from one bounded scan.
     *
     * @return array{external: array<string, int>, key: array<string, int>, truncated: bool}
     */
    private function existingMap(): array
    {
        $scan = CalendarScan::scan(
            $this->content,
            new ContentQuery(
                types: array(ServiceDefinition::TYPE),
                statuses: array('publish'),
                orderBy: 'id',
                order: 'ASC',
            ),
            ServiceDefinition::DEDUPE_SCAN
        );

        $map = array(
            'external'  => array(),
            'key'       => array(),
            'title'     => array(),
            'truncated' => false,
        );

        foreach ($scan['items'] as $item) {
            $this->remember(
                $map,
                ServiceDefinition::string($item->meta[ ServiceDefinition::META_EXTERNAL_ID ] ?? ''),
                ServiceDefinition::string($item->meta[ ServiceDefinition::META_KEY ] ?? ''),
                $item->id,
                false
            );

            $this->rememberTitle($map, $item->title, $item->id, false);
        }

        $map['truncated'] = $scan['total'] > $scan['scanned'];

        return $map;
    }

    /**
     * Find a stored service id by external id, derived key, or legacy title.
     *
     * @param array{external: array<string, int>, key: array<string, int>, title: array<string, int>, truncated: bool} $map Maps.
     * @param string $external   Caller-supplied identity.
     * @param string $key        Derived identity.
     * @param string $name       Row name, for the legacy title match.
     * @param bool   $allowTitle Whether the title may be used.
     */
    private function existing(array &$map, string $external, string $key, string $name, bool $allowTitle): int
    {
        if ('' !== $external && isset($map['external'][ $external ])) {
            return (int) $map['external'][ $external ];
        }

        if (isset($map['key'][ $key ])) {
            return (int) $map['key'][ $key ];
        }

        if ($allowTitle) {
            $title = strtolower(trim($name));

            if ('' !== $title && isset($map['title'][ $title ])) {
                return (int) $map['title'][ $title ];
            }
        }

        if (empty($map['truncated'])) {
            return 0;
        }

        $id = $this->queryExisting($external, $key);

        if ($id > 0) {
            $this->remember($map, $external, $key, $id);
        }

        return $id;
    }

    /**
     * Query the store when the prefetch window did not cover the collection.
     */
    private function queryExisting(string $external, string $key): int
    {
        $clause = '' !== $external
            ? array('key' => ServiceDefinition::META_EXTERNAL_ID, 'value' => $external, 'compare' => '=')
            : array('key' => ServiceDefinition::META_KEY, 'value' => $key, 'compare' => '=');

        $page = $this->content->query(
            new ContentQuery(
                types: array(ServiceDefinition::TYPE),
                statuses: array('publish'),
                metaQuery: array($clause),
                orderBy: 'id',
                order: 'ASC',
                page: 1,
                perPage: 1,
            )
        );

        foreach ($page->items as $item) {
            if ($item instanceof ContentItem) {
                return $item->id;
            }
        }

        return 0;
    }

    /**
     * Record an identity in the maps, keeping the first id seen.
     *
     * @param array{external?: array<string, int>, key?: array<string, int>, title?: array<string, int>, truncated?: bool} $map Maps.
     */
    private function remember(array &$map, string $external, string $key, int $id, bool $overwrite = true): void
    {
        if ('' !== $external && ($overwrite || ! isset($map['external'][ $external ]))) {
            $map['external'][ $external ] = $id;
        }

        if ('' !== $key && ($overwrite || ! isset($map['key'][ $key ]))) {
            $map['key'][ $key ] = $id;
        }
    }

    /**
     * Record a lowercased title in the map.
     *
     * @param array{title?: array<string, int>} $map Maps.
     */
    private function rememberTitle(array &$map, string $title, int $id, bool $overwrite = true): void
    {
        $title = strtolower(trim($title));

        if ('' === $title) {
            return;
        }

        if ($overwrite || ! isset($map['title'][ $title ])) {
            $map['title'][ $title ] = $id;
        }
    }

    /**
     * The category vocabulary, keyed by lowercased name.
     *
     * @return array<string, bool>
     */
    private function categoryVocabulary(): array
    {
        $known = array();

        foreach ($this->content->listTaxonomyTerms(ServiceDefinition::TAXONOMY, 500) as $term) {
            $name = isset($term['name']) ? strtolower((string) $term['name']) : '';

            if ('' !== $name) {
                $known[ $name ] = true;
            }
        }

        return $known;
    }

    /**
     * The row name, or '' when it is missing.
     *
     * @param mixed $row Raw row.
     */
    private function rowName(mixed $row): string
    {
        if (! is_array($row) || ! isset($row['name']) || ! is_scalar($row['name'])) {
            return '';
        }

        return Text::singleLine((string) $row['name']);
    }

    /**
     * Whether the row already carries a place reference.
     *
     * @param array<string, mixed> $row Raw row.
     */
    private function rowHasPlace(array $row): bool
    {
        if (! isset($row['place_id']) || ! is_scalar($row['place_id'])) {
            return false;
        }

        $value = trim((string) $row['place_id']);

        if ('' === $value) {
            return false;
        }

        // A numeric zero means "no place" and takes the default; any other
        // value is left for validation so a typo is reported, not hidden.
        return ! ( is_numeric($value) && 0 === (int) $value );
    }

    /**
     * Whether the row asks for remote images, which this runtime refuses.
     *
     * @param array<string, mixed> $row Raw row.
     */
    private function hasRemoteImages(array $row): bool
    {
        if (! array_key_exists('image_urls', $row)) {
            return false;
        }

        $urls = $row['image_urls'];

        if (! is_array($urls)) {
            return is_scalar($urls) && '' !== trim((string) $urls);
        }

        foreach ($urls as $url) {
            if (is_scalar($url) && '' !== trim((string) $url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Flatten field errors into one line.
     */
    private function fieldMessage(ValidationException $error): string
    {
        $parts = array();

        foreach ($error->errors as $field => $messages) {
            $parts[] = $field . ': ' . implode(' ', $messages);
        }

        return array() === $parts ? $error->getMessage() : implode(' ', $parts);
    }
}
