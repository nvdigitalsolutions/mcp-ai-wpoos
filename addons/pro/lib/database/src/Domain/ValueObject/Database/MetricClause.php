<?php

/**
 * One aggregate in `db_aggregate`.
 *
 * Proposal 167 section 167.6.6. A metric names an operation from the closed
 * vocabulary, an optional column, and an output alias. Aliases are quoted by the
 * grammar, so an alias can contain spaces without becoming an injection vector.
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
use Nvoos\Database\Domain\Service\Database\Metric;
use Nvoos\Database\Domain\Service\Database\RefusalCode;

final readonly class MetricClause
{
    public function __construct(
        public Metric $metric,
        public string $alias,
        public ?string $column = null,
    ) {
        if ($metric->requiresColumn() && (null === $column || '' === trim($column))) {
            throw DatabaseRefusal::of(
                RefusalCode::UNSUPPORTED_METRIC,
                'Metric ' . $metric->value . ' requires a column.'
            );
        }

        if ('' === trim($alias)) {
            throw DatabaseRefusal::of(RefusalCode::UNSUPPORTED_METRIC, 'Metric has an empty alias.');
        }
    }

    /**
     * @param array<string, mixed> $metric Raw metric term.
     */
    public static function fromArray(array $metric): self
    {
        $resolved = Metric::tryFrom(strtolower(trim((string) ($metric['op'] ?? ''))));

        if (null === $resolved) {
            throw DatabaseRefusal::of(RefusalCode::UNSUPPORTED_METRIC, 'Unknown metric.');
        }

        $alias = (string) ($metric['as'] ?? $resolved->value . '_' . (string) ($metric['column'] ?? 'all'));

        return new self(
            $resolved,
            $alias,
            isset($metric['column']) && '' !== (string) $metric['column'] ? (string) $metric['column'] : null,
        );
    }
}
