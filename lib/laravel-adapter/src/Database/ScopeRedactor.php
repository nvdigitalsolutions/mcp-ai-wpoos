<?php

/**
 * Scope-aware redactor for the Laravel host.
 *
 * Proposal 167 decision D7 and section 4.7. Masking is a shaping rule, so the
 * algorithm is the kernel's `ColumnMasker`; this class only decides what a
 * *host* must do around it — currently nothing beyond scrubbing engine text
 * before it reaches a log line.
 *
 * `scrub_detail()` exists because a driver message can quote a DSN, a host or a
 * relation name. The executor puts the scrubbed form in the audit record and
 * never in the response, so this is the second line of defence behind
 * `ErrorMapper`.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Database;

use Nvoos\Database\Domain\Contract\Database\RedactorInterface;
use Nvoos\Database\Domain\Service\Database\ColumnMasker;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;

final class ScopeRedactor implements RedactorInterface
{
    private readonly ColumnMasker $masker;

    public function __construct(?ColumnMasker $masker = null)
    {
        $this->masker = $masker ?? new ColumnMasker();
    }

    /**
     * {@inheritDoc}
     */
    public function mask_row(array $row, TableDescriptor $table, ScopeDescriptor $scope, string $relation): array
    {
        return $this->masker->maskRow($row, $table, $scope, $relation);
    }

    /**
     * {@inheritDoc}
     */
    public function scrub_detail(string $detail): string
    {
        $scrubbed = preg_replace(
            array(
                '/\b(password|pwd|secret|token)\s*=\s*[^;\s]+/i',
                '/\b(pgsql|mysql|sqlite):[^\s\'"]+/i',
                '/\b(host|hostaddr)\s*=\s*[^;\s]+/i',
            ),
            array(
                '$1=[redacted]',
                '[dsn]',
                '$1=[redacted]',
            ),
            $detail,
        );

        return substr((string) $scrubbed, 0, 300);
    }
}
