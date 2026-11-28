<?php

/**
 * Driver errors to refusal codes.
 *
 * Proposal 167 section 167.4.5 and implementation-plan section 4.5. The mapping
 * is data, not logic, for two reasons: a test can assert every row, and adding a
 * code does not require editing an executor. This is also the only place a
 * driver's SQLSTATE or error number is read — the message is never read at all,
 * because a driver message can echo the statement or the row values.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use PDOException;

final class ErrorMapper
{
    /**
     * PostgreSQL SQLSTATE to refusal.
     *
     * @var array<string, RefusalCode>
     */
    private const PGSQL = array(
        '25006' => RefusalCode::READ_ONLY_VIOLATION,
        '42501' => RefusalCode::PRIVILEGE_DENIED,
        '57014' => RefusalCode::TIMEOUT,
        '55P03' => RefusalCode::LOCK_TIMEOUT,
        '42P01' => RefusalCode::RELATION_NOT_FOUND,
        '42703' => RefusalCode::COLUMN_NOT_FOUND,
        '28P01' => RefusalCode::AUTH_FAILED,
        '3D000' => RefusalCode::UNKNOWN_DATABASE,
        '53300' => RefusalCode::CONNECTION_LIMIT,
        '08000' => RefusalCode::CONNECTION_UNAVAILABLE,
        '08001' => RefusalCode::CONNECTION_UNAVAILABLE,
        '08003' => RefusalCode::CONNECTION_UNAVAILABLE,
        '08004' => RefusalCode::CONNECTION_UNAVAILABLE,
        '08006' => RefusalCode::CONNECTION_UNAVAILABLE,
        '42601' => RefusalCode::STATEMENT_NOT_ADMITTED,
        '40001' => RefusalCode::TIMEOUT,
    );

    /**
     * MySQL/MariaDB driver error number to refusal.
     *
     * @var array<int, RefusalCode>
     */
    private const MYSQL = array(
        1040 => RefusalCode::CONNECTION_LIMIT,
        1044 => RefusalCode::PRIVILEGE_DENIED,
        1045 => RefusalCode::AUTH_FAILED,
        1049 => RefusalCode::UNKNOWN_DATABASE,
        1054 => RefusalCode::COLUMN_NOT_FOUND,
        1142 => RefusalCode::PRIVILEGE_DENIED,
        1143 => RefusalCode::PRIVILEGE_DENIED,
        1146 => RefusalCode::RELATION_NOT_FOUND,
        1205 => RefusalCode::LOCK_TIMEOUT,
        2002 => RefusalCode::CONNECTION_UNAVAILABLE,
        2006 => RefusalCode::CONNECTION_UNAVAILABLE,
        2013 => RefusalCode::CONNECTION_UNAVAILABLE,
        3024 => RefusalCode::TIMEOUT,
    );

    /**
     * Map a PostgreSQL SQLSTATE.
     */
    public function fromPgsql(string $sqlstate): RefusalCode
    {
        return self::PGSQL[strtoupper(trim($sqlstate))] ?? RefusalCode::CONNECTION_UNAVAILABLE;
    }

    /**
     * Map a MySQL/MariaDB driver error number.
     */
    public function fromMysql(int|string $code): RefusalCode
    {
        $number = (int) $code;

        return self::MYSQL[$number] ?? RefusalCode::CONNECTION_UNAVAILABLE;
    }

    /**
     * Map a thrown driver exception for a given engine.
     *
     * PDO exposes the SQLSTATE as `getCode()` and the driver code in
     * `errorInfo[1]`. PostgreSQL is mapped on SQLSTATE because it is the stable
     * contract; MySQL on the driver number because its SQLSTATE classes are too
     * coarse to distinguish a missing column from a missing table.
     *
     * @param string $engine One of the {@see ConnectionDescriptor} engine constants.
     */
    public function fromThrowable(\Throwable $error, string $engine): RefusalCode
    {
        if (! $error instanceof PDOException) {
            return RefusalCode::CONNECTION_UNAVAILABLE;
        }

        $info = is_array($error->errorInfo) ? $error->errorInfo : array();
        $sqlstate = isset($info[0]) ? (string) $info[0] : (string) $error->getCode();
        $driverCode = isset($info[1]) ? (int) $info[1] : 0;

        if (ConnectionDescriptor::PGSQL === $engine) {
            return $this->fromPgsql($sqlstate);
        }

        if (0 !== $driverCode) {
            return $this->fromMysql($driverCode);
        }

        return $this->fromMysql($sqlstate);
    }

    /**
     * The SQLSTATE or driver number, for the audit log only.
     *
     * Never rendered to a caller: it can identify a relation that the caller was
     * not allowed to know existed.
     */
    public function auditDetail(\Throwable $error): string
    {
        if (! $error instanceof PDOException) {
            return $error::class;
        }

        $info = is_array($error->errorInfo) ? $error->errorInfo : array();

        return trim(sprintf(
            'sqlstate=%s driver=%s',
            (string) ($info[0] ?? $error->getCode()),
            (string) ($info[1] ?? '')
        ));
    }
}
