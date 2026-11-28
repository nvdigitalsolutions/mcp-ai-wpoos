<?php

/**
 * The closed set of reasons a database call can be refused.
 *
 * Proposal 167. Every refusal in the toolkit — lexical, scope, engine or
 * protocol — resolves to one of these cases, so a host renders a stable code
 * instead of a driver message, and a test can assert the reason rather than the
 * wording.
 *
 * A backed enum rather than a class of constants: `match` over it is
 * exhaustive, so adding a case is a compile-time reminder at every consumer
 * instead of a silently unhandled string.
 *
 * ## What is deliberately absent
 *
 * There is no case for "the database said no in a way we did not anticipate".
 * Unknown engine failures map to {@see self::CONNECTION_UNAVAILABLE} with the
 * original SQLSTATE written to the server-side log only — the caller never
 * receives driver text, because a driver message can echo the statement or the
 * row values.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

enum RefusalCode: string
{
    /** No connection catalogue exists on this site. */
    case NOT_CONFIGURED = 'db_not_configured';

    /** The id is not in the catalogue visible to this principal. */
    case UNKNOWN_CONNECTION = 'db_unknown_connection';

    /** Probe missing or failed. */
    case CONNECTION_UNAVAILABLE = 'db_connection_unavailable';

    /** The resolved connection kind is not a database. */
    case NOT_A_DATABASE = 'db_connection_not_a_database';

    /** No scope grants this principal that connection. */
    case SCOPE_NOT_ALLOWED = 'db_scope_not_allowed';

    /** Relation outside the scope allowlist. */
    case TABLE_NOT_ALLOWED = 'db_scope_table_not_allowed';

    /** Column outside the scope projection. */
    case COLUMN_NOT_ALLOWED = 'db_scope_column_not_allowed';

    /** The mandatory row filter cannot be applied to this relation. */
    case FILTER_UNMAPPABLE = 'db_scope_filter_unmappable';

    /** The tool needs a tier the scope does not hold. */
    case TIER_REQUIRED = 'db_tier_required';

    /** Admission (L2) refused the statement before execution. */
    case STATEMENT_NOT_ADMITTED = 'db_statement_not_admitted';

    /** The engine cannot express the requested metric. */
    case UNSUPPORTED_METRIC = 'db_unsupported_metric';

    /** A row or byte cap was reached before the result completed. */
    case LIMIT_EXCEEDED = 'db_limit_exceeded';

    /**
     * Too many async exports are queued for this principal.
     *
     * A queue-depth cap, not a data cap: an asynchronous export holds rows in a
     * job payload and a file on disk until it finishes, so an unbounded number
     * of them is a disk and worker problem. Checked at enqueue time only.
     */
    case EXPORT_LIMIT = 'db_export_limit';

    /** statement_timeout, or the PHP-side deadline, fired. */
    case TIMEOUT = 'db_timeout';

    /** Shaping could not fit the payload budget. */
    case RESULT_TOO_LARGE = 'db_result_too_large';

    /** apply_write without an approval ticket. */
    case APPROVAL_REQUIRED = 'db_approval_required';

    /** Affected-row count changed between proposal and apply. */
    case WRITE_AFFECTED_MISMATCH = 'db_write_affected_mismatch';

    /** The target resolves to the WordPress or Laravel application database. */
    case SITE_DATABASE_REFUSED = 'db_site_database_refused';

    /** pdo_pgsql / pdo_mysql is not loaded. */
    case EXTENSION_MISSING = 'db_extension_missing';

    /** The database refused the grant (42501, 1142, 1143, 1044). */
    case PRIVILEGE_DENIED = 'db_privilege_denied';

    /** Missing relation (42P01, 1146). */
    case RELATION_NOT_FOUND = 'db_relation_not_found';

    /** Missing column (42703, 1054). */
    case COLUMN_NOT_FOUND = 'db_column_not_found';

    /** Credentials rejected (28P01, 1045). */
    case AUTH_FAILED = 'db_auth_failed';

    /** Database does not exist (3D000, 1049). */
    case UNKNOWN_DATABASE = 'db_unknown_database';

    /** Too many connections (53300, 1040). */
    case CONNECTION_LIMIT = 'db_connection_limit';

    /** Lock wait exceeded (55P03, 1205). */
    case LOCK_TIMEOUT = 'db_lock_timeout';

    /**
     * A write reached a read-only transaction (25006).
     *
     * If this case is ever observed, both L1 (grants) and L2 (admission) failed
     * and the incident is a bug, not a user error.
     */
    case READ_ONLY_VIOLATION = 'db_read_only_violation';

    /**
     * A short, non-sensitive explanation suitable for a tool response.
     *
     * Deliberately does not include engine text. Hosts may translate this
     * string; they must not append the driver's message.
     */
    public function describe(): string
    {
        return match ($this) {
            self::NOT_CONFIGURED => 'No database connection is configured on this site.',
            self::UNKNOWN_CONNECTION => 'That connection id is not available to this assistant.',
            self::CONNECTION_UNAVAILABLE => 'The connection is not currently reachable, or has not passed its health check.',
            self::NOT_A_DATABASE => 'That connection is not a database connection.',
            self::SCOPE_NOT_ALLOWED => 'No scope grants this assistant access to that connection.',
            self::TABLE_NOT_ALLOWED => 'That table is outside the scope this assistant may read.',
            self::COLUMN_NOT_ALLOWED => 'That column is outside the scope this assistant may read.',
            self::FILTER_UNMAPPABLE => 'The required row filter cannot be applied to that table, so the query was refused rather than widened.',
            self::TIER_REQUIRED => 'This operation requires a capability the scope does not grant.',
            self::STATEMENT_NOT_ADMITTED => 'The statement was refused before execution.',
            self::UNSUPPORTED_METRIC => 'The selected database cannot compute that metric.',
            self::LIMIT_EXCEEDED => 'The result exceeded the configured limit; narrow the request or page with the cursor.',
            self::EXPORT_LIMIT => 'Too many exports are already queued for this assistant; wait for one to finish or cancel it.',
            self::TIMEOUT => 'The query exceeded its time limit.',
            self::RESULT_TOO_LARGE => 'The result could not be returned within the payload budget.',
            self::APPROVAL_REQUIRED => 'A human must approve this change before it can be applied.',
            self::WRITE_AFFECTED_MISMATCH => 'The number of affected rows changed since the change was proposed.',
            self::SITE_DATABASE_REFUSED => 'The site or application database cannot be used as a scoped connection.',
            self::EXTENSION_MISSING => 'The required PHP database extension is not installed on this server.',
            self::PRIVILEGE_DENIED => 'The database role does not hold the privilege required for that operation.',
            self::RELATION_NOT_FOUND => 'That table does not exist on the selected connection.',
            self::COLUMN_NOT_FOUND => 'That column does not exist on the selected table.',
            self::AUTH_FAILED => 'The stored credential was rejected by the database.',
            self::UNKNOWN_DATABASE => 'That database does not exist on the selected host.',
            self::CONNECTION_LIMIT => 'The database refused the connection because its connection limit was reached.',
            self::LOCK_TIMEOUT => 'The query could not acquire its lock within the limit.',
            self::READ_ONLY_VIOLATION => 'A write was attempted on a read-only transaction.',
        };
    }

    /**
     * Whether the condition may succeed if retried unchanged.
     *
     * Only lock and connection conditions are retryable; everything else is a
     * property of the request or the configuration.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::LOCK_TIMEOUT, self::CONNECTION_LIMIT, self::CONNECTION_UNAVAILABLE, self::EXPORT_LIMIT => true,
            default => false,
        };
    }
}
