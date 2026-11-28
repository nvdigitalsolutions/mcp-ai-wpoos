<?php

/**
 * A query plan, and nothing else.
 *
 * Proposal 167 sections 167.6.4 and 167.6.6. `EXPLAIN` without `ANALYZE` does not
 * execute the query, so it belongs to the `metadata` tier. `EXPLAIN ANALYZE`
 * **does** execute it — it runs the plan and discards the rows — which is why it
 * is a separate tier (`explain_analyze`) rather than a boolean on the same tool.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

final readonly class PlanDescriptor
{
    /**
     * @param array<string, mixed> $plan        Decoded engine plan (JSON format).
     * @param bool                 $analyzed    Whether the plan was actually executed.
     * @param string               $engine      One of the engine constants.
     * @param int                  $elapsedMs   Planning (or execution) time.
     */
    public function __construct(
        public array $plan,
        public bool $analyzed = false,
        public string $engine = ConnectionDescriptor::PGSQL,
        public int $elapsedMs = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'plan' => $this->plan,
            'analyzed' => $this->analyzed,
            'engine' => $this->engine,
            'elapsed_ms' => $this->elapsedMs,
        );
    }
}
