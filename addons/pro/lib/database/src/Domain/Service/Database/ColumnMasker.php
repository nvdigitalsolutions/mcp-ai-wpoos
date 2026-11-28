<?php

/**
 * Column masking and exclusion.
 *
 * Proposal 167 sections 167.6.3 and 167.6.4 (tier `read_unmasked`). Masking is a
 * separate step from projection for one reason: exclusion is a *syntax* decision
 * (the column never appears in the SELECT) while masking is a *value* decision
 * (the column appears, with a placeholder). Keeping them apart means a mask can
 * never be defeated by asking for the column under an alias, and an exclusion can
 * never be defeated by asking for `*`.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;

final class ColumnMasker
{
    /** The placeholder shown in place of a masked value. */
    public const MASK = '[masked]';

    /**
     * Mask and project one row.
     *
     * @param array<string, mixed> $row Row keyed by column name.
     *
     * @return array{row: array<string, mixed>, masked: list<string>} Masked row and the `relation.column` labels that were masked.
     */
    public function maskRow(
        array $row,
        TableDescriptor $table,
        ScopeDescriptor $scope,
        string $relationKey,
    ): array {
        $maskedColumns = array();
        $excluded = $scope->excludedColumns($table);

        foreach ($row as $name => $value) {
            $normalised = strtolower((string) $name);

            if (in_array($normalised, $excluded, true)) {
                // Defensive: the projection should have removed it already. If a
                // host adapter ever builds a projection itself, the value still
                // must not escape.
                unset($row[$name]);
                continue;
            }

            if ($scope->isSensitive($table, $normalised)) {
                $row[$name] = self::MASK;
                $maskedColumns[] = $relationKey . '.' . $normalised;
            }
        }

        return array(
            'row' => $row,
            'masked' => array_values(array_unique($maskedColumns)),
        );
    }
}
