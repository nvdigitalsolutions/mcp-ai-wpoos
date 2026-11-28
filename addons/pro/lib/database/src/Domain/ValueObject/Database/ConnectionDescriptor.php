<?php

/**
 * A database endpoint, as an operator configured it.
 *
 * Proposal 167 section 167.6.2. Data only: the descriptor carries a **credential
 * handle**, never a secret, so nothing in the portable layer ever touches key
 * material. The host adapter resolves the handle, opens the connection and drops
 * the resolved value.
 *
 * Validation is strict and reports through {@see DatabaseRefusal}, not
 * `TypeError` or a bare null: a corrupted stored record must produce a refusal
 * the operator can read, and must never fall back to a default host.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;

final readonly class ConnectionDescriptor
{
    public const PGSQL = 'pgsql';
    public const MYSQL = 'mysql';
    public const MARIADB = 'mariadb';
    public const SQLITE = 'sqlite';

    /** @var list<string> */
    private const ENGINES = array( self::PGSQL, self::MYSQL, self::MARIADB, self::SQLITE );

    /** @var list<string> */
    private const SSL_MODES = array( 'disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full' );

    /**
     * @param string             $id            Stable slug; the only thing the model sees.
     * @param string             $label         Operator-facing name.
     * @param string             $engine        One of the ENGINE constants.
     * @param string             $host          Hostname or IP (unused for sqlite).
     * @param int                $port          TCP port (unused for sqlite).
     * @param string             $database      Database name, or a file path for sqlite.
     * @param string             $sslmode       One of the SSL_MODES constants.
     * @param string             $credential    Credential handle, or a file path for sqlite.
     * @param string             $role          Role asserted at probe time.
     * @param string|null        $readReplica   Optional replica DSN handle.
     * @param bool               $allowSiteDb   Explicit override for the site-database refusal.
     * @param string|null        $sslRootCert   CA bundle path when `sslmode` is verify-ca/full.
     * @param ProbeResult|null   $probe         Last health check.
     * @param string             $kind          Provider kind: `database` or `remote_site`.
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $engine,
        public string $host = '',
        public int $port = 0,
        public string $database = '',
        public string $sslmode = 'require',
        public string $credential = '',
        public string $role = '',
        public ?string $readReplica = null,
        public bool $allowSiteDb = false,
        public ?string $sslRootCert = null,
        public ?ProbeResult $probe = null,
        public string $kind = 'database',
    ) {
        		// A provider-namespaced id (`db:warehouse`, `rs:orders-db`) is one segment
        		// with an optional provider prefix. The colon is the namespace separator the
        		// connection resolver uses, so it is permitted here rather than encoded
        		// away; it is inert inside a scope record and readable in an audit row.
        		if (! preg_match('/^[a-z0-9][a-z0-9_:-]{0,63}$/', $id)) {
        			throw DatabaseRefusal::of(RefusalCode::CONNECTION_UNAVAILABLE, 'Invalid connection id.');
        		}

        if (! in_array($engine, self::ENGINES, true)) {
            throw DatabaseRefusal::of(RefusalCode::CONNECTION_UNAVAILABLE, 'Unknown engine: ' . $engine);
        }

        if (self::SQLITE !== $engine) {
            if ('' === trim($host)) {
                throw DatabaseRefusal::of(RefusalCode::CONNECTION_UNAVAILABLE, 'Missing host.');
            }

            if ($port < 1 || $port > 65535) {
                throw DatabaseRefusal::of(RefusalCode::CONNECTION_UNAVAILABLE, 'Invalid port.');
            }

            if ('' === trim($database)) {
                throw DatabaseRefusal::of(RefusalCode::CONNECTION_UNAVAILABLE, 'Missing database name.');
            }
        }

        if (! in_array($sslmode, self::SSL_MODES, true)) {
            throw DatabaseRefusal::of(RefusalCode::CONNECTION_UNAVAILABLE, 'Unknown sslmode.');
        }

        if (in_array($sslmode, array( 'verify-ca', 'verify-full' ), true) && null === $sslRootCert) {
            // Fail at registration, not at connect: an unusable SSL configuration
            // should be attributable to the record an operator edited.
            throw DatabaseRefusal::of(
                RefusalCode::CONNECTION_UNAVAILABLE,
                'sslmode ' . $sslmode . ' requires a CA bundle path.'
            );
        }
    }

    /**
     * Hydrate from a stored record.
     *
     * Unknown keys are ignored; a missing required key is a refusal.
     *
     * @param array<string, mixed> $record Stored connection array.
     */
    public static function fromArray(array $record): self
    {
        $probe = null;

        if (isset($record['probe']) && is_array($record['probe'])) {
            $probe = new ProbeResult(
                reachable: ! empty($record['probe']['reachable']),
                serverVersion: isset($record['probe']['server_version']) ? (string) $record['probe']['server_version'] : '',
                role: (string) ( $record['role'] ?? $record['probe']['role'] ?? '' ),
                transactionReadOnly: ! empty($record['probe']['transaction_read_only']),
                canWrite: ! empty($record['probe']['can_write']),
                canCreateSchema: ! empty($record['probe']['can_create_schema']),
                bypassesRls: ! empty($record['probe']['bypasses_rls']),
                checkedAt: isset($record['probe']['checked_at']) ? (int) $record['probe']['checked_at'] : 0,
                error: isset($record['probe']['error']) ? (string) $record['probe']['error'] : null,
            );
        }

        return new self(
            id: strtolower(trim((string) ($record['id'] ?? ''))),
            label: (string) ($record['label'] ?? $record['name'] ?? ($record['id'] ?? '')),
            engine: strtolower(trim((string) ($record['engine'] ?? ''))),
            host: (string) ($record['host'] ?? ''),
            port: (int) ($record['port'] ?? 0),
            database: (string) ($record['database'] ?? ''),
            sslmode: strtolower(trim((string) ($record['sslmode'] ?? 'require'))),
            credential: (string) ($record['credential'] ?? $record['password'] ?? ''),
            role: (string) ($record['role'] ?? ''),
            readReplica: isset($record['read_replica']) && '' !== (string) $record['read_replica']
                ? (string) $record['read_replica']
                : null,
            allowSiteDb: ! empty($record['allow_site_db']),
            sslRootCert: isset($record['sslrootcert']) && '' !== (string) $record['sslrootcert']
                ? (string) $record['sslrootcert']
                : null,
            probe: $probe,
            kind: isset($record['connection_type']) && 'database' === $record['connection_type']
                ? 'remote_site'
                : (string) ($record['kind'] ?? 'database'),
        );
    }

    public function isPostgres(): bool
    {
        return self::PGSQL === $this->engine;
    }

    public function isMysqlFamily(): bool
    {
        return self::MYSQL === $this->engine || self::MARIADB === $this->engine;
    }

    /**
     * Whether the endpoint is servable at all.
     */
    public function isServable(): bool
    {
        return null !== $this->probe && $this->probe->isServable();
    }

    /**
     * A redacted summary for `db_list_connections` and the console list.
     *
     * Deliberately omits `credential`, `read_replica` and `sslrootcert`.
     *
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return array(
            'id' => $this->id,
            'label' => $this->label,
            'engine' => $this->engine,
            'kind' => $this->kind,
            'queryable' => $this->isServable(),
            'probe' => $this->probe?->toArray(),
        );
    }
}
