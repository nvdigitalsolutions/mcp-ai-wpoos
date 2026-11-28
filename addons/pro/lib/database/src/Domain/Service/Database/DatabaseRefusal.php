<?php

/**
 * A refusal carrying its reason.
 *
 * Proposal 167. Thrown by every kernel service that denies a request, and caught
 * by the host adapter, which maps {@see getRefusal()} to its own wire shape
 * (`WP_Error` on WordPress, a typed exception on Laravel) and records the
 * server-side detail in the audit log.
 *
 * ## Two strings, two audiences
 *
 * `getMessage()` is the user-facing explanation and comes from
 * {@see RefusalCode::describe()}. `getLogDetail()` is for the audit record and
 * may contain an engine SQLSTATE or a relation name — it is **never** rendered
 * to the model or the user, because a driver or schema detail can disclose more
 * than the refusal did.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

use RuntimeException;
use Throwable;

final class DatabaseRefusal extends RuntimeException
{
    /**
     * @param RefusalCode $refusal   The reason.
     * @param string      $logDetail Detail for the audit log only; never rendered.
     * @param Throwable|null $previous Driver exception, if any.
     */
    public function __construct(
        private readonly RefusalCode $refusal,
        private readonly string $logDetail = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($refusal->describe(), 0, $previous);
    }

    /**
     * Construct without a log detail.
     */
    public static function of(RefusalCode $refusal, string $logDetail = ''): self
    {
        return new self($refusal, $logDetail);
    }

    /**
     * Wrap a driver failure, keeping it as `previous` for the log.
     */
    public static function wrap(RefusalCode $refusal, Throwable $previous, string $logDetail = ''): self
    {
        return new self($refusal, $logDetail, $previous);
    }

    public function getRefusal(): RefusalCode
    {
        return $this->refusal;
    }

    /**
     * The stable machine code, e.g. `db_scope_table_not_allowed`.
     */
    public function getRefusalCode(): string
    {
        return $this->refusal->value;
    }

    /**
     * Audit-log detail. Not for rendering.
     */
    public function getLogDetail(): string
    {
        return $this->logDetail;
    }
}
