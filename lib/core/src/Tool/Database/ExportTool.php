<?php

/**
 * `db_export` — a bounded CSV/JSON export to the host file store.
 *
 * Proposal 167 phase P4 (ported from P7). WordPress parity: same slug, same
 * schema, same envelope. The differences are the two decisions a host owns:
 *
 *  - **Where the file goes.** The WordPress tool writes to the uploads directory;
 *    here it goes through `FileStoreInterface`, which the Laravel adapter binds
 *    to a disk. When that store also implements `FileVisibilityStoreInterface`,
 *    the artefact is stored **private** — a better outcome than a guessable
 *    uploads URL, and the reason the export is worth porting rather than
 *    duplicating.
 *  - **What `file` / `url` mean.** `file` is the absolute path, `url` is the
 *    public URL or null for a private artefact. `stored` says which happened.
 *
 * The risk profile is why export is its own tier: the data leaves the database
 * and lands in a file. The mitigations are all visible here — the `export` tier is
 * required, the row cap is the lower of the requested limit and the scope cap,
 * masking still applies (the export is a select, not a side door), the filename
 * gets a random suffix, and a SHA-256 checksum is returned so the caller can
 * verify what it received.
 *
 * ## Async
 *
 * `async: true` queues {@see DatabaseExportHandler} through the queue port and
 * returns a job id instead of the rows. Three properties matter and are tested:
 * the tier is asserted before anything is queued (an assistant without it must
 * not create a job that will fail unattended); the worker re-enters this tool, so
 * the grant in force *then* applies; and the queue depth is capped per principal,
 * because an export holds a payload and a file until it finishes.
 *
 * The queue port is optional so the published `nvoos/core` package keeps its
 * constructor contract: a host that has not bound a queue refuses async with a
 * 501 rather than pretending.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Database\Domain\Contract\DatabaseQueryInterface;
use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;
use Throwable;

final class ExportTool extends DatabaseTool
{
    /**
     * Maximum rows an export may return.
     */
    private const MAX_ROWS = 10000;

    /**
     * Group the async job is queued under.
     */
    public const QUEUE_GROUP = 'database';

    public function __construct(
        ErrorFactoryInterface $errors,
        DatabaseQueryInterface $databases,
        private readonly FileStoreInterface $files,
        private readonly ?QueueClientInterface $queue = null,
        private readonly int $maxConcurrentExports = 3,
    ) {
        parent::__construct($errors, $databases);
    }

    public function getSlug(): string
    {
        return 'db_export';
    }

    public function getName(): string
    {
        return 'Export Database Rows';
    }

    public function getDescription(): string
    {
        return 'Export a scoped select to a CSV or JSON file in the host file store. Requires the export capability on the scope; masking still applies and the row cap is enforced.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array_merge(
                $this->connectionProperties(),
                array(
                    'relation' => $this->relationSchema(),
                    'columns'  => array(
                        'type'     => 'array',
                        'maxItems' => 40,
                        'items'    => array('type' => 'string'),
                    ),
                    'filters'  => $this->filterSchema(),
                    'order'    => $this->orderSchema(),
                    'limit'    => array(
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'maximum'     => self::MAX_ROWS,
                        'description' => 'Rows to export. The scope cap wins when it is lower.',
                    ),
                    'format'   => array(
                        'type'    => 'string',
                        'enum'    => array('csv', 'json'),
                        'default' => 'csv',
                    ),
                    'async'    => array(
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'Return a job id instead of waiting for the rows. Pass that job_id back to this tool to read the export once it finishes.',
                    ),
                    'job_id'   => array(
                        'type'        => 'string',
                        'description' => 'Read a queued or finished export instead of starting one. Mutually exclusive with relation.',
                    ),
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        return $this->guard(function () use ($arguments, $context): mixed {
            $jobId = isset($arguments['job_id']) ? trim((string) $arguments['job_id']) : '';

            if ('' !== $jobId) {
                return $this->status($jobId, $arguments, $context);
            }

            if ('' === trim((string) ($arguments['relation'] ?? $arguments['table'] ?? ''))) {
                return $this->errors->validationFailed(
                    'Name a relation to export, or pass job_id to read a queued export.',
                    array('relation' => array('A relation is required when job_id is not supplied.')),
                );
            }

            $connection = $this->connection($arguments, $context);
            $scope_id   = $this->scope($arguments);

            $scope = $this->scopeAt($connection, $scope_id, $this->principal($context));

            if (! $scope->hasTier(GrantTier::EXPORT)) {
                throw DatabaseRefusal::of(
                    RefusalCode::TIER_REQUIRED,
                    'Scope ' . $scope->id . ' lacks tier ' . GrantTier::EXPORT->value . '.',
                );
            }

            if (! empty($arguments['async'])) {
                return $this->enqueue($arguments, $context, $connection, $this->scopeRelation($arguments));
            }

            $query = $this->selectQuery($arguments);
            $limit = isset($arguments['limit']) ? min(self::MAX_ROWS, max(1, (int) $arguments['limit'])) : 1000;

            $query = new SelectQuery(
                relation: $query->relation,
                columns: $query->columns,
                filters: $query->filters,
                order: $query->order,
                limit: $limit,
            );

            $result = $this->databases->select($connection, $scope->id, $query);

            $format = 'json' === strtolower((string) ($arguments['format'] ?? 'csv')) ? 'json' : 'csv';
            $payload = 'json' === $format
                ? (string) json_encode($result->rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : $this->toCsv($result->columns, $result->rows);

            $filename = sprintf(
                'database-export-%s-%s-%s.%s',
                (string) preg_replace('/[^a-z0-9_-]+/', '-', strtolower($connection)),
                gmdate('Ymd-His'),
                bin2hex(random_bytes(4)),
                $format,
            );

            $stored = $this->store(
                $payload,
                $filename,
                'json' === $format ? 'application/json' : 'text/csv',
                isset($context['user_id']) ? (int) $context['user_id'] : 0,
            );

            return array(
                'success'    => true,
                'connection' => $connection,
                'relation'   => $query->relation,
                'format'     => $format,
                'rows'       => $result->rowCount,
                'truncated'  => $result->truncated,
                'masked'     => $result->masked,
                'audit_id'   => $result->auditId,
                'file'       => $stored->localPath,
                'url'        => $stored->publicUrl,
                'bytes'      => strlen($payload),
                'checksum'   => 'sha256:' . hash('sha256', $payload),
                'stored'     => true,
            );
        });
    }

    /**
     * Queue the export and return its handle.
     *
     * @param array<string, mixed> $arguments  Tool arguments.
     * @param array<string, mixed> $context    Execution context.
     * @param string               $connection Connection id.
     * @param string               $relation   Relation, for the response only.
     * @return array<string, mixed>|mixed
     * @throws DatabaseRefusal When the principal already has the maximum queued.
     */
    private function enqueue(array $arguments, array $context, string $connection, string $relation): mixed
    {
        if (null === $this->queue) {
            return $this->errors->create(
                'wp_mcp_ai_db_async_unavailable',
                'Background exports are not available on this runtime. Call the tool without async to export inline.',
                array('status' => 501),
            );
        }

        if ($this->pendingExports() >= max(1, $this->maxConcurrentExports)) {
            throw DatabaseRefusal::of(
                RefusalCode::EXPORT_LIMIT,
                'This principal already has the maximum number of exports queued.',
            );
        }

        // The worker must never re-enter the async branch: it runs the same
        // arguments, and `async` is what made them a job in the first place.
        unset($arguments['async']);

        $jobId = $this->queue->enqueue(
            DatabaseExportHandler::class,
            array(
                'arguments' => $arguments,
                'context'   => array(
                    'user_id'      => (int) ($context['user_id'] ?? 0),
                    'assistant_id' => (int) ($context['assistant_id'] ?? 0),
                ),
            ),
            array('group' => self::QUEUE_GROUP),
        );

        return array(
            'success'        => true,
            'async'          => true,
            'job_id'         => $jobId,
            'status'         => 'queued',
            'poll_with'      => 'db_export',
            'poll_arguments' => array('job_id' => $jobId),
            'connection'     => $connection,
            'relation'       => $relation,
            'format'         => 'json' === strtolower((string) ($arguments['format'] ?? 'csv')) ? 'json' : 'csv',
            'note'           => 'The rows are read by a worker under the grant in force when it runs. Pass job_id back to this tool for the file, checksum and audit id.',
        );
    }

    /**
     * Read a queued or finished export.
     *
     * The poll surface is this tool rather than a second slug, matching the
     * WordPress host and the way `db_select` returns a cursor. The export tier is
     * asserted first, so a revoked grant also revokes the artefact; the job id is
     * an unguessable queue UUID, and this host's job port records no owner, which
     * is the one asymmetry with WordPress (documented, and bounded by the tier).
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     * @return array<string, mixed>|mixed
     */
    private function status(string $jobId, array $arguments, array $context): mixed
    {
        $connection = $this->connection($arguments, $context);
        $scope      = $this->scopeAt($connection, $this->scope($arguments), $this->principal($context));

        if (! $scope->hasTier(GrantTier::EXPORT)) {
            throw DatabaseRefusal::of(
                RefusalCode::TIER_REQUIRED,
                'Scope ' . $scope->id . ' lacks tier ' . GrantTier::EXPORT->value . '.',
            );
        }

        if (null === $this->queue) {
            return $this->errors->create(
                'wp_mcp_ai_db_async_unavailable',
                'Background exports are not available on this runtime.',
                array('status' => 501),
            );
        }

        $status = $this->queue->getStatus($jobId);

        return array(
            'success'  => true,
            'async'    => true,
            'job_id'   => $status->jobId,
            'status'   => $status->status,
            'result'   => $status->result,
            'error'    => $status->error,
            'attempts' => $status->attempts,
        );
    }

    /**
     * Queued or running exports for the same handler.
     *
     * The status store is the only place that knows, and `listJobs` is part of
     * the published port. A store that cannot list returns an empty set, which
     * leaves the cap inert rather than blocking every export.
     */
    private function pendingExports(): int
    {
        if (null === $this->queue) {
            return 0;
        }

        $pending = 0;

        foreach ($this->queue->listJobs(array('handler' => DatabaseExportHandler::class), 100) as $status) {
            if (! $status->isTerminal()) {
                ++$pending;
            }
        }

        return $pending;
    }

    /**
     * The relation argument as sanitised by the shared helpers.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function scopeRelation(array $arguments): string
    {
        return strtolower(trim((string) ($arguments['relation'] ?? $arguments['table'] ?? '')));
    }

    /**
     * Write the payload to a temporary file and hand it to the store.
     *
     * The store contract is upload-shaped — it takes a local path — so the
     * payload is materialised once, stored, and the temporary copy removed. A
     * private artefact is preferred whenever the store supports visibility: an
     * export can contain masked-but-still-commercial rows, and a guessable URL
     * is the wrong default for it.
     *
     * @throws DatabaseRefusal When the file cannot be written.
     */
    private function store(string $payload, string $filename, string $mime, int $userId): StoredFile
    {
        $path = tempnam(sys_get_temp_dir(), 'nvoos-db-export-');

        if (false === $path || false === file_put_contents($path, $payload)) {
            throw DatabaseRefusal::of(RefusalCode::RESULT_TOO_LARGE, 'Export file could not be written.');
        }

        try {
            if (
                $this->files instanceof FileVisibilityStoreInterface
                && $this->files->supportsVisibility(FileVisibilityStoreInterface::VISIBILITY_PRIVATE)
            ) {
                return $this->files->storeWithVisibility(
                    $path,
                    $filename,
                    $mime,
                    $userId,
                    FileVisibilityStoreInterface::VISIBILITY_PRIVATE,
                );
            }

            return $this->files->store($path, $filename, $mime, $userId);
        } catch (DatabaseRefusal $refusal) {
            throw $refusal;
        } catch (Throwable $error) {
            // A missing files table, a full disk or a rejected MIME type all land
            // here. The WordPress path maps the same class of failure to
            // RESULT_TOO_LARGE, and the message stays host-neutral.
            throw DatabaseRefusal::wrap(RefusalCode::RESULT_TOO_LARGE, $error, 'export-store');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Render rows as CSV.
     *
     * Identical to the WordPress renderer, including the formula guard: a leading
     * `=`, `+`, `-` or `@` makes Excel execute the cell, and an export is exactly
     * where attacker-controlled text arrives.
     *
     * @param list<string>               $columns Column names.
     * @param list<array<string, mixed>> $rows    Rows.
     */
    private function toCsv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if (false === $handle) {
            return '';
        }

        if (array() !== $columns) {
            fputcsv($handle, $columns, ',', '"', '\\');
        }

        foreach ($rows as $row) {
            $line = array();

            foreach ($columns as $column) {
                $value = $row[$column] ?? '';

                if (is_string($value) && '' !== $value && in_array($value[0], array('=', '+', '-', '@'), true)) {
                    $value = "'" . $value;
                }

                $line[] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }

            fputcsv($handle, $line, ',', '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
