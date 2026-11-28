<?php

/**
 * The capability ladder a scope can grant.
 *
 * Proposal 167 section 167.6.4. Tiers are **not** transitive, and that is the
 * point:
 *
 *  - `apply_write` does not imply `propose_write`
 *  - `raw_sql` does not imply `export`
 *  - `admin` is never assignable to an assistant
 *
 * A scope that wants a higher tier must name it. The reason is the 2026
 * tool-chaining failure mode: an agent inherits its orchestrator's scope by
 * default, and transitivity turns one over-broad grant into a write primitive.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

enum GrantTier: string
{
    /** Catalogue and plan introspection; never executes a scoped statement. */
    case METADATA = 'metadata';

    /** Structured reads: select, aggregate, search. */
    case READ = 'read';

    /** See columns the scope marks `sensitive` in clear. */
    case READ_UNMASKED = 'read_unmasked';

    /** `EXPLAIN ANALYZE`, which executes the plan. */
    case EXPLAIN_ANALYZE = 'explain_analyze';

    /** Asynchronous export to the file registry. */
    case EXPORT = 'export';

    /** `db_query` — operator-authored SQL, single statement. */
    case RAW_SQL = 'raw_sql';

    /** Build a reviewable change proposal; does not execute. */
    case PROPOSE_WRITE = 'propose_write';

    /** Execute an approved proposal. */
    case APPLY_WRITE = 'apply_write';

    /** Connection and scope administration. Never granted to an assistant. */
    case ADMIN = 'admin';

    /**
     * Parse a stored tier list, dropping anything unrecognised.
     *
     * Unknown values are dropped rather than throwing: a tier removed from this
     * enum should narrow a scope, never widen it or break an existing record.
     *
     * @param array<int, mixed> $values Raw tier strings.
     *
     * @return list<self>
     */
    public static function listFrom(array $values): array
    {
        $tiers = array();

        foreach ($values as $value) {
            if (! \is_string($value)) {
                continue;
            }

            $tier = self::tryFrom(\strtolower(\trim($value)));

            if (null !== $tier && ! \in_array($tier, $tiers, true)) {
                $tiers[] = $tier;
            }
        }

        return $tiers;
    }

    /**
     * Whether this tier is safe to grant to an assistant.
     *
     * `admin` is not. The rest are, subject to the operator's judgement and the
     * scope's limits.
     */
    public function isAssistantGrantable(): bool
    {
        return self::ADMIN !== $this;
    }
}
