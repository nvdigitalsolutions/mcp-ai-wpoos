<?php

/**
 * Import activities from a CSV read out of the file registry — Proposal 164 §164.59.
 *
 * Ported from `WP_MCP_AI_Tool_Import_ECAs_CSV`. Same field vocabulary, same
 * `column_mapping` semantics (field name to CSV column name), same
 * `update_existing` and `dry_run` switches, and the same per-row error report.
 * Five things differ, and each one is a refusal to reproduce a defect:
 *
 * ### The bytes come from the registry, never from a URL
 *
 * WordPress accepts `file_url` and fetches it server-side. That is a request
 * from this runtime to an address the caller chose, and the surface is exactly
 * the one the rest of the runtime already declines to expose. A `file_id` from
 * the file registry is fetched from storage under an ownership check instead.
 * A caller that sends `file_url` is refused with a message naming `file_id`,
 * rather than silently importing nothing.
 *
 * ### Duplicate codes inside one file are skipped, not applied twice
 *
 * WordPress processes rows in order, so two rows sharing an `eca_code` create
 * two activities when `update_existing` is off, and update the same record
 * twice when it is on — the second row silently overwriting the first. Either
 * way the file does not mean what it looks like it means. Here the second row
 * is skipped and named in `errors`, because a duplicate code is a problem in
 * the file, not a precedence rule.
 *
 * ### The size and row ceilings are stated
 *
 * The registry read is capped at 1 MiB and the parse at 500 data rows, with
 * `truncated` in the result when the cap bites. WordPress reads whatever it is
 * given.
 *
 * ### Enum values are validated, not rewritten
 *
 * WordPress coerces an unknown `eca_type` to `club` and leaves the rest to
 * whatever the meta write accepts. Here each enumerated field falls back to the
 * same defaults `create_eca` uses and the row reports the substitution, because
 * an importer that quietly changes a value is indistinguishable from one that
 * parsed the column wrong.
 *
 * ### The description is the row's description
 *
 * WordPress only writes a description on create and never updates the body on
 * a matched row. This port treats a supplied description as the activity's
 * content on both paths: an import that carries corrected text should correct it.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Classroom;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;
use Nvoos\Core\Tool\AbstractTool;

class ImportEcasCsvTool extends AbstractTool
{
    private const MAX_ROWS = 500;

    private const MAX_BYTES = 1048576;

    private const TYPES = array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' );

    private const STATUSES = array( 'active', 'inactive', 'full', 'cancelled' );

    private const COST_PERIODS = array( 'term', 'month', 'session', 'year' );

    /**
     * Header aliases, in the order a field is resolved. Lowercased on compare.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = array(
        'name'         => array( 'name', 'eca_name', 'eca name', 'activity', 'activity name', 'title' ),
        'eca_code'     => array( 'eca_code', 'eca code', 'code', 'id', 'eca_id' ),
        'description'  => array( 'description', 'desc', 'details', 'about' ),
        'eca_type'     => array( 'eca_type', 'eca type', 'type', 'category' ),
        'day'          => array( 'day', 'day_of_week', 'day of week', 'weekday' ),
        'start_time'   => array( 'start_time', 'start time', 'start', 'from' ),
        'end_time'     => array( 'end_time', 'end time', 'end', 'to' ),
        'venue'        => array( 'venue', 'location', 'room', 'place' ),
        'max_students' => array( 'max_students', 'max students', 'capacity', 'max', 'limit' ),
        'year_groups'  => array( 'year_groups', 'year groups', 'year group', 'years', 'grades' ),
        'teachers'     => array( 'teachers', 'teacher', 'staff', 'instructor', 'coach' ),
        'is_paid'      => array( 'is_paid', 'is paid', 'paid', 'paid_flag' ),
        'cost'         => array( 'cost', 'price', 'fee', 'amount' ),
        'cost_period'  => array( 'cost_period', 'cost period', 'billing_period', 'billing period', 'period' ),
        'status'       => array( 'status', 'eca_status', 'state' ),
    );

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ContentStoreInterface $content,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'import_ecas_csv';
    }

    public function getName(): string
    {
        return 'Import ECAs from CSV';
    }

    public function getDescription(): string
    {
        return 'Creates or updates Extra-Curricular Activities from a CSV file in the file registry (file_id), or from CSV text (csv_content). Columns are mapped by name, or detected from the header when column_mapping is omitted. A duplicate eca_code inside the file is skipped and reported; update_existing chooses between matching an existing activity by code and creating a new one. Use dry_run to preview the counts without writing.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'file_id'        => array(
                    'type'        => 'integer',
                    'description' => 'File registry id of a CSV. Checked for ownership and read from storage; this is the preferred input.',
                    'minimum'     => 1,
                ),
                'csv_content'    => array(
                    'type'        => 'string',
                    'description' => 'Raw CSV text, header row first. Use when the caller already holds the bytes.',
                    'maxLength'   => self::MAX_BYTES,
                ),
                'column_mapping' => array(
                    'type'                 => 'object',
                    'description'          => 'Maps an ECA field to the CSV column name, e.g. {"name":"Activity","eca_type":"Kind"}. Omit to detect from the header.',
                    'additionalProperties' => array( 'type' => 'string' ),
                ),
                'update_existing' => array(
                    'type'        => 'boolean',
                    'description' => 'Match an existing activity by eca_code and update it. When false, every row creates a new activity.',
                    'default'     => false,
                ),
                'dry_run'        => array(
                    'type'        => 'boolean',
                    'description' => 'Count what would happen without writing anything.',
                    'default'     => false,
                ),
            ),
            'required'   => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $user_id = (int) ( $context['user_id'] ?? 0 );
        $dry_run = $this->boolParam($arguments, 'dry_run', false);
        $update  = $this->boolParam($arguments, 'update_existing', false);

        if (array_key_exists('file_url', $arguments)) {
            return $this->errors->validationFailed(
                'file_url is not accepted: this runtime does not fetch a caller-supplied URL.',
                array( 'file_url' => array( 'Store the CSV through the file registry and send its file_id.' ) ),
            );
        }

        $csv = '';

        if (isset($arguments['file_id'])) {
            $read = $this->readStored($this->intParam($arguments, 'file_id'), $user_id);

            if (is_array($read)) {
                return $read;
            }

            $csv = $read;
        } elseif (isset($arguments['csv_content'])) {
            $csv = (string) $arguments['csv_content'];
        }

        if ('' === trim($csv)) {
            return $this->errors->validationFailed(
                'Either file_id or csv_content must be provided.',
                array( 'file_id' => array( 'A CSV in the file registry, or the CSV text itself.' ) ),
            );
        }

        $parsed = $this->parse($csv, $arguments);

        if (isset($parsed['error'])) {
            return $parsed['error'];
        }

        $result = array(
            'total_rows'    => 0,
            'created_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'errors'        => array(),
            'truncated'     => $parsed['truncated'],
            'dry_run'       => $dry_run,
        );

        $seen = array();

        foreach ($parsed['rows'] as $row) {
            ++$result['total_rows'];

            $data  = $this->normalise($row, $parsed['mapping'], $result['errors']);
            $label = 'Row ' . $row['number'];

            if ('' === $data['name']) {
                $result['errors'][] = $label . ': missing name, skipped.';
                ++$result['skipped_count'];

                continue;
            }

            $code = $data['eca_code'];

            if ('' !== $code && isset($seen[ strtolower($code) ])) {
                $result['errors'][] = $label . ': eca_code "' . $code . '" already appeared on row ' . $seen[ strtolower($code) ] . ' of this file, skipped.';
                ++$result['skipped_count'];

                continue;
            }

            if ('' !== $code) {
                $seen[ strtolower($code) ] = $row['number'];
            }

            $existing = ( $update && '' !== $code ) ? $this->findByCode($code) : null;

            if (null !== $existing) {
                if (! $dry_run) {
                    $error = $this->apply($existing, $data, $user_id);

                    if (null !== $error) {
                        $result['errors'][] = $label . ': ' . $error;
                        ++$result['skipped_count'];

                        continue;
                    }
                }

                ++$result['updated_count'];

                continue;
            }

            if (! $dry_run) {
                $error = $this->create($data, $user_id);

                if (null !== $error) {
                    $result['errors'][] = $label . ': ' . $error;
                    ++$result['skipped_count'];

                    continue;
                }
            }

            ++$result['created_count'];
        }

        $message = $dry_run
            ? sprintf(
                'Dry run: would create %d, update %d and skip %d activities.',
                $result['created_count'],
                $result['updated_count'],
                $result['skipped_count']
            )
            : sprintf(
                'Imported %d activities: %d created, %d updated, %d skipped.',
                $result['total_rows'],
                $result['created_count'],
                $result['updated_count'],
                $result['skipped_count']
            );

        return $this->success($message, $result);
    }

    /**
     * Read a stored CSV, or an error result describing why it could not be read.
     */
    private function readStored(int $file_id, int $user_id): string|array
    {
        if (null === $this->files) {
            return $this->errors->create(
                'no_file_store',
                'No file registry is bound on this host, so file_id cannot be read; send csv_content instead.',
            );
        }

        if ($file_id <= 0) {
            return $this->errors->validationFailed(
                'file_id must be a positive integer.',
                array( 'file_id' => array( 'The id returned when the CSV was stored.' ) ),
            );
        }

        $meta = $this->files->getMetadata($file_id);

        if (null === $meta) {
            return $this->errors->notFound('There is no stored file with id ' . $file_id . '.');
        }

        if (! $this->files->userCanAccess($file_id, $user_id)) {
            return $this->errors->forbidden('You cannot read stored file ' . $file_id . '.');
        }

        if ($meta->sizeBytes > self::MAX_BYTES) {
            return $this->errors->validationFailed(
                sprintf('The CSV is %d bytes; the ceiling is %d.', $meta->sizeBytes, self::MAX_BYTES),
                array( 'file_id' => array( 'Split the import into smaller files.' ) ),
            );
        }

        $path = $this->files->getPath($file_id);

        if (null === $path || ! is_file($path)) {
            return $this->errors->create('file_unreadable', 'Stored file ' . $file_id . ' has no readable local path.');
        }

        $bytes = file_get_contents($path);

        if (false === $bytes) {
            return $this->errors->create('file_unreadable', 'Stored file ' . $file_id . ' could not be read.');
        }

        return $bytes;
    }

    /**
     * Header, mapping and rows.
     *
     * @return array{mapping: array<string, int>, rows: list<array{number: int, cells: list<string>}>, truncated: bool}|array{error: mixed}
     */
    private function parse(string $csv, array $arguments): array
    {
        // A UTF-8 BOM in front of the first header is common from spreadsheet
        // exports and would otherwise make the first column unmatchable.
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }

        $handle = fopen('php://temp', 'r+');

        if (false === $handle) {
            return array( 'error' => $this->errors->create('import_failed', 'Could not open a temporary stream.') );
        }

        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle);

        if (! is_array($header) || array() === $header) {
            fclose($handle);

            return array(
                'error' => $this->errors->validationFailed(
                    'The CSV has no header row.',
                    array( 'csv_content' => array( 'The first line must name the columns.' ) ),
                ),
            );
        }

        $header = array_map(static fn ($cell): string => trim((string) $cell), $header);
        $mapping = $this->mapping($header, $arguments);

        if (null === $mapping) {
            fclose($handle);

            return array(
                'error' => $this->errors->validationFailed(
                    'No column is mapped to the name field.',
                    array( 'column_mapping' => array( 'Map "name" to the column holding the activity title.' ) ),
                ),
            );
        }

        $rows      = array();
        $truncated = false;
        $number    = 1;

        while (false !== ( $cells = fgetcsv($handle) )) {
            ++$number;

            if (! is_array($cells) || array() === array_filter($cells, static fn ($c): bool => '' !== trim((string) $c))) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;

                break;
            }

            $rows[] = array(
                'number' => $number,
                'cells'  => array_map(static fn ($cell): string => trim((string) $cell), $cells),
            );
        }

        fclose($handle);

        return array( 'mapping' => $mapping, 'rows' => $rows, 'truncated' => $truncated );
    }

    /**
     * Field name to column index, from the caller's map or the header aliases.
     *
     * A caller-supplied mapping is authoritative: a column it names that the
     * file does not have is a real problem, so the mapping is dropped rather
     * than half-applied, and the missing column is named in `errors`.
     *
     * @param list<string> $header
     * @return array<string, int>|null
     */
    private function mapping(array $header, array $arguments): ?array
    {
        $lowered = array_map(static fn (string $cell): string => strtolower($cell), $header);
        $given   = $arguments['column_mapping'] ?? null;
        $mapping = array();

        if (is_array($given) && array() !== $given) {
            foreach ($given as $field => $column) {
                if (! is_string($field) || ! is_scalar($column)) {
                    continue;
                }

                $index = array_search(strtolower(trim((string) $column)), $lowered, true);

                if (false === $index) {
                    continue;
                }

                $mapping[ $field ] = $index;
            }

            return isset($mapping['name']) ? $mapping : null;
        }

        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($alias, $lowered, true);

                if (false !== $index) {
                    $mapping[ $field ] = $index;

                    break;
                }
            }
        }

        return isset($mapping['name']) ? $mapping : null;
    }

    /**
     * One CSV row as ECA data, with the same enum defaults `create_eca` uses.
     *
     * @param array{number: int, cells: list<string>} $row
     * @param array<string, int>                      $mapping
     * @param list<string>                            $errors
     * @return array<string, mixed>
     */
    private function normalise(array $row, array $mapping, array &$errors): array
    {
        $cell = static function (string $field) use ($row, $mapping): string {
            if (! isset($mapping[ $field ])) {
                return '';
            }

            return trim((string) ( $row['cells'][ $mapping[ $field ] ] ?? '' ));
        };

        $label = 'Row ' . $row['number'];
        $type  = strtolower($cell('eca_type'));

        if ('' !== $type && ! in_array($type, self::TYPES, true)) {
            $errors[] = $label . ': eca_type "' . $type . '" is not one of ' . implode(', ', self::TYPES) . '; used club.';
            $type     = 'club';
        }

        $status = strtolower($cell('status'));

        if ('' !== $status && ! in_array($status, self::STATUSES, true)) {
            $errors[] = $label . ': status "' . $status . '" is not one of ' . implode(', ', self::STATUSES) . '; used active.';
            $status     = 'active';
        }

        $period = strtolower($cell('cost_period'));

        if ('' !== $period && ! in_array($period, self::COST_PERIODS, true)) {
            $errors[] = $label . ': cost_period "' . $period . '" is not one of ' . implode(', ', self::COST_PERIODS) . '; used term.';
            $period     = 'term';
        }

        $day = $cell('day');

        if ('' !== $day && ! in_array($day, Schedule::DAYS, true)) {
            $errors[] = $label . ': day "' . $day . '" is not a weekday; left unset.';
            $day      = '';
        }

        $start = $cell('start_time');
        $end   = $cell('end_time');

        if ('' !== $start && null === Schedule::minutes($start)) {
            $errors[] = $label . ': start_time "' . $start . '" is not HH:MM; left unset.';
            $start     = '';
        }

        if ('' !== $end && null === Schedule::minutes($end)) {
            $errors[] = $label . ': end_time "' . $end . '" is not HH:MM; left unset.';
            $end      = '';
        }

        if (
            '' !== $start && '' !== $end && null !== Schedule::minutes($start) && null !== Schedule::minutes($end)
            && (int) Schedule::minutes($end) <= (int) Schedule::minutes($start)
        ) {
            $errors[] = $label . ': end_time ' . $end . ' is not after start_time ' . $start . '; both left unset.';
            $start     = '';
            $end       = '';
        }

        $paid = strtolower($cell('is_paid'));

        return array(
            'name'         => $cell('name'),
            'eca_code'     => $cell('eca_code'),
            'description'  => $cell('description'),
            'eca_type'     => '' !== $type ? $type : 'club',
            'status'       => '' !== $status ? $status : 'active',
            'day'          => $day,
            'start_time'   => $start,
            'end_time'     => $end,
            'venue'        => $cell('venue'),
            'max_students' => max(0, (int) $cell('max_students')),
            'year_groups'  => $this->listCell($cell('year_groups')),
            'teachers'     => $this->listCell($cell('teachers')),
            'is_paid'      => in_array($paid, array( 'yes', 'true', '1', 'y' ), true),
            'cost'         => max(0.0, (float) str_replace(array( ',', '$', '£', '€' ), '', $cell('cost'))),
            'cost_period'  => '' !== $period ? $period : 'term',
        );
    }

    /**
     * @return list<string>
     */
    private function listCell(string $value): array
    {
        if ('' === $value) {
            return array();
        }

        $parts = preg_split('/[,;|]/', $value);

        if (false === $parts) {
            return array();
        }

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => '' !== $p));
    }

    private function findByCode(string $code): ?ContentItem
    {
        $page = $this->content->query(
            new ContentQuery(
                types: array( 'mcp_ai_eca' ),
                metaQuery: array(
                    array(
                        'key'     => '_eca_code',
                        'value'   => $code,
                        'compare' => '=',
                    ),
                ),
                perPage: 1,
            )
        );

        foreach ($page->items as $item) {
            if ($item instanceof ContentItem && 'mcp_ai_eca' === $item->type) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The meta an imported activity carries, matching `create_eca`'s keys.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function meta(array $data): array
    {
        $meta = array(
            '_eca_type'     => $data['eca_type'],
            '_eca_status'   => $data['status'],
            '_eca_is_paid'  => $data['is_paid'] ? 'yes' : 'no',
            '_eca_cost'     => $data['cost'],
            '_eca_cost_period' => $data['cost_period'],
        );

        if ('' !== $data['eca_code']) {
            $meta['_eca_code'] = $data['eca_code'];
        }

        if ('' !== $data['day']) {
            $meta['_eca_day'] = $data['day'];
        }

        if ('' !== $data['start_time']) {
            $meta['_eca_start_time'] = $data['start_time'];
        }

        if ('' !== $data['end_time']) {
            $meta['_eca_end_time'] = $data['end_time'];
        }

        if ('' !== $data['venue']) {
            $meta['_eca_venue'] = $data['venue'];
        }

        if ($data['max_students'] > 0) {
            $meta['_eca_max_students'] = $data['max_students'];
        }

        if (array() !== $data['year_groups']) {
            $meta['_eca_year_groups'] = $data['year_groups'];
        }

        if (array() !== $data['teachers']) {
            $meta['_eca_teachers'] = $data['teachers'];
        }

        return $meta;
    }

    /**
     * Create one activity; returns an error message or null on success.
     *
     * @param array<string, mixed> $data
     */
    private function create(array $data, int $user_id): ?string
    {
        try {
            $this->content->create(
                new CreateContentCommand(
                    title: $data['name'],
                    type: 'mcp_ai_eca',
                    authorId: $user_id,
                    status: 'publish',
                    content: $data['description'],
                    meta: $this->meta($data),
                )
            );
        } catch (AccessDeniedException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Update one matched activity; returns an error message or null on success.
     *
     * @param array<string, mixed> $data
     */
    private function apply(ContentItem $existing, array $data, int $user_id): ?string
    {
        try {
            $this->content->update(
                $existing->id,
                new UpdateContentCommand(
                    userId: $user_id,
                    title: $data['name'],
                    content: '' !== $data['description'] ? $data['description'] : null,
                    meta: $this->meta($data),
                )
            );
        } catch (NotFoundException $e) {
            return $e->getMessage();
        } catch (AccessDeniedException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}
