<?php

/**
 * Append-only audit trail in the application database.
 *
 * Proposal 167 phase P3/P4. The WordPress log is a bounded ring in an option —
 * 500 records, because an option row is not a database. This is the ledger the
 * Laravel host gets instead: one row per call, nothing overwritten, nothing
 * deleted by the library.
 *
 * ## Deliberately framework-free
 *
 * The class takes a `Closure(): PDO` rather than an `Illuminate\Database\Connection`.
 * Two reasons: the query surface is four statements, so an ORM buys nothing; and
 * a plain `PDO` lets the suite exercise the real writer and reader against the
 * same PostgreSQL fixture the rest of the toolkit uses, instead of testing a
 * Laravel-shaped mock. The service provider supplies the connection.
 *
 * ## What is stored
 *
 * The statement **template** and a fingerprint, never a bound value — the same
 * rule as the WordPress log. `detail` is already scrubbed by the redactor before
 * it reaches `append()`.
 *
 * ## Retention
 *
 * `retained()` returns 0: there is no record ceiling, and the library does not
 * prune. An audit trail that deletes itself is not an audit trail. An operator
 * who needs a bound table prunes by `ts` on their own schedule; the migration
 * indexes that column for exactly that.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Database;

use Closure;
use InvalidArgumentException;
use Nvoos\Database\Domain\Contract\Database\AuditReaderInterface;
use Nvoos\Database\Domain\Contract\Database\AuditSinkInterface;
use PDO;
use Throwable;

final class TableAuditLog implements AuditSinkInterface, AuditReaderInterface
{
    /**
     * Default table name.
     */
    public const TABLE = 'oos_database_audit';

    /**
     * Columns returned by {@see self::tail()}, in the WordPress order.
     *
     * `seq` is deliberately absent: it exists to make "newest first" exact when
     * two records share a second, and it is not part of the record shape a client
     * reads.
     *
     * @var list<string>
     */
    private const COLUMNS = array(
        'id',
        'ts',
        'connection',
        'scope',
        'scope_version',
        'principal',
        'action',
        'tool',
        'relation',
        'refusal_code',
        'sql_template',
        'fingerprint',
        'row_count',
        'elapsed_ms',
        'truncated',
        'detail',
    );

    private readonly string $table;

    /**
     * @param Closure():PDO                  $pdo     Opens the application connection.
     * @param string                         $table   Table name.
     * @param Closure(Throwable):void|null   $onError Called when a write fails; the
     *                                                sink itself never throws.
     *
     * @throws InvalidArgumentException When the table name is not a safe identifier.
     */
    public function __construct(
        private readonly Closure $pdo,
        string $table = self::TABLE,
        private readonly ?Closure $onError = null,
    ) {
        $table = strtolower(trim($table));

        // The name is interpolated as an identifier — there is no placeholder for
        // one — so it is validated rather than escaped.
        if (1 !== preg_match('/^[a-z0-9_]{1,64}$/', $table)) {
            throw new InvalidArgumentException('Unsafe audit table name.');
        }

        $this->table = $table;
    }

    /**
     * {@inheritDoc}
     */
    public function next_id(): string
    {
        $time = base_convert((string) time(), 10, 36);

        return 'aud_' . $time . '_' . bin2hex(random_bytes(6));
    }

    /**
     * {@inheritDoc}
     *
     * Never throws: an audit failure must not turn a successful read into an
     * error, and must not mask a refusal that is already propagating.
     */
    public function append(array $record): string
    {
        $id = (string) ($record['id'] ?? $this->next_id());

        $row = array_merge(
            array(
                'id'            => $id,
                'ts'            => time(),
                'connection'    => '',
                'scope'         => '',
                'scope_version' => 0,
                'principal'     => '',
                'action'        => '',
                'tool'          => '',
                'relation'      => '',
                'refusal_code'  => null,
                'sql_template'  => '',
                'fingerprint'   => '',
                'row_count'     => 0,
                'elapsed_ms'    => 0,
                'truncated'     => '',
                'detail'        => '',
            ),
            $record,
        );

        $row['id'] = $id;

        try {
            $columns      = array_keys($row);
            $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

            $statement = $this->pdo()->prepare(
                sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $this->table,
                    implode(', ', $columns),
                    implode(', ', $placeholders),
                ),
            );

            if (false !== $statement) {
                $statement->execute($row);
                $statement->closeCursor();
            }
        } catch (Throwable $error) {
            if (null !== $this->onError) {
                ($this->onError)($error);
            }
        }

        return $id;
    }

    /**
     * {@inheritDoc}
     *
     * Returns an empty list rather than throwing when the read fails — including
     * when the table does not exist because the host never ran `artisan migrate`.
     * That is the sink contract (an audit failure must not turn a good call into
     * an error), and the cost is stated where an operator will look: `db_audit_tail`
     * cannot distinguish "no records" from "no ledger".
     */
    public function tail(int $limit = 50, array $filters = array()): array
    {
        $limit = max(1, min(200, $limit));
        $where = array();
        $binds = array();

        foreach (array('connection', 'scope', 'action', 'tool', 'refusal_code') as $key) {
            if (! isset($filters[$key]) || '' === trim((string) $filters[$key])) {
                continue;
            }

            $where[]        = $key . ' = :' . $key;
            $binds[':' . $key] = (string) $filters[$key];
        }

        $sql = sprintf(
            'SELECT %s FROM %s%s ORDER BY seq DESC LIMIT %d',
            implode(', ', self::COLUMNS),
            $this->table,
            array() === $where ? '' : ' WHERE ' . implode(' AND ', $where),
            $limit,
        );

        try {
            $statement = $this->pdo()->prepare($sql);

            if (false === $statement) {
                return array();
            }

            $statement->execute($binds);

            $records = array();

            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $records[] = $this->cast($row);
            }

            $statement->closeCursor();

            return $records;
        } catch (Throwable $error) {
            if (null !== $this->onError) {
                ($this->onError)($error);
            }

            return array();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function summary(int $window_seconds = 3600): array
    {
        $since = time() - max(1, $window_seconds);

        try {
            $statement = $this->pdo()->prepare(
                sprintf(
                    'SELECT refusal_code, COUNT(*) AS n FROM %s WHERE ts >= :since GROUP BY refusal_code',
                    $this->table,
                ),
            );

            if (false === $statement) {
                return array('total' => 0);
            }

            $statement->execute(array(':since' => $since));

            $counts = array('total' => 0);

            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $count = (int) ($row['n'] ?? 0);
                $code  = $row['refusal_code'] ?? null;

                $counts['total'] += $count;

                if (is_string($code) && '' !== $code) {
                    $counts[$code] = $count;
                }
            }

            $statement->closeCursor();

            return $counts;
        } catch (Throwable $error) {
            if (null !== $this->onError) {
                ($this->onError)($error);
            }

            return array('total' => 0);
        }
    }

    /**
     * {@inheritDoc}
     *
     * No record ceiling: this is a ledger, pruned by the operator by `ts`.
     */
    public function retained(): int
    {
        return 0;
    }

    /**
     * Normalise driver stringification back to the record shape.
     *
     * `PDO` returns integers as strings on most drivers, and the WordPress log
     * returns integers. A client reading both hosts should not have to care.
     *
     * @param array<string, mixed> $row Raw row.
     * @return array<string, mixed>
     */
    private function cast(array $row): array
    {
        foreach (array('ts', 'scope_version', 'row_count', 'elapsed_ms') as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        if (array_key_exists('refusal_code', $row) && (null === $row['refusal_code'] || '' === $row['refusal_code'])) {
            $row['refusal_code'] = null;
        }

        return $row;
    }

    private function pdo(): PDO
    {
        /** @var PDO $pdo */
        $pdo = ($this->pdo)();

        return $pdo;
    }
}
