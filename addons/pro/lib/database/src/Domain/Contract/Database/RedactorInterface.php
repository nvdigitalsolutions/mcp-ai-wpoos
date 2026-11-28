<?php

/**
 * Redactor the read executor needs.
 *
 * Proposal 167 phase P4. Masking is a shaping rule, so the kernel owns the
 * algorithm (`ColumnMasker`); a host redactor decides which columns are sensitive
 * via the scope and scrubs engine text before it reaches a log line.
 *
 * `scrub_detail()` exists because a driver message can quote a relation name or a
 * credential-bearing DSN; the executor puts the scrubbed form in the audit
 * record and never in the response.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Contract\Database;

use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;

interface RedactorInterface
{
    /**
     * Mask one shaped row.
     *
     * @param array<string, mixed> $row      Row, already shaped.
     * @param TableDescriptor      $table    Relation.
     * @param ScopeDescriptor      $scope    Scope.
     * @param string               $relation Relation key.
     * @return array{row: array<string, mixed>, masked: list<string>}
     */
    public function mask_row(array $row, TableDescriptor $table, ScopeDescriptor $scope, string $relation): array;

    /**
     * Scrub driver text for the audit log.
     */
    public function scrub_detail(string $detail): string;
}
