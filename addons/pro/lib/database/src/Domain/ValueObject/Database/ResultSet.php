<?php

/**
 * What a scoped read returns.
 *
 * Proposal 167 section 167.6.7. The envelope is part of the contract: a caller
 * learns not just the rows but whether they were truncated, why, which columns
 * were masked, and where the next page starts. That turns "the answer looked
 * short" from a mystery into a value the model can act on.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

final readonly class ResultSet
{
    public const TRUNCATED_ROWS = 'max_rows';
    public const TRUNCATED_BYTES = 'max_bytes';
    public const TRUNCATED_CELL = 'cell_cap';
    public const TRUNCATED_DEADLINE = 'deadline';

    /**
     * @param list<string>                            $columns Column names, in projection order.
     * @param list<array<string, mixed>>              $rows    Shaped rows.
     * @param int                                     $rowCount Rows returned.
     * @param bool                                    $truncated Whether a cap was hit.
     * @param string|null                             $truncatedReason One of the TRUNCATED_* constants.
     * @param string|null                             $nextCursor Opaque keyset cursor, when more rows exist.
     * @param list<string>                            $masked Columns that were masked.
     * @param int                                     $elapsedMs Statement time.
     * @param string                                  $auditId Audit record id.
     */
    public function __construct(
        public array $columns = array(),
        public array $rows = array(),
        public int $rowCount = 0,
        public bool $truncated = false,
        public ?string $truncatedReason = null,
        public ?string $nextCursor = null,
        public array $masked = array(),
        public int $elapsedMs = 0,
        public string $auditId = '',
    ) {
    }

    /**
     * An empty result carrying only the projection.
     *
     * @param list<string> $columns
     */
    public static function empty(array $columns = array()): self
    {
        return new self($columns);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'columns' => $this->columns,
            'rows' => $this->rows,
            'row_count' => $this->rowCount,
            'truncated' => $this->truncated,
            'truncated_reason' => $this->truncatedReason,
            'next_cursor' => $this->nextCursor,
            'masked' => $this->masked,
            'elapsed_ms' => $this->elapsedMs,
            'audit_id' => $this->auditId,
        );
    }
}
