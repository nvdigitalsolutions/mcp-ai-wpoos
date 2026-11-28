<?php

/**
 * A statement and its bound values, produced by the grammar.
 *
 * Proposal 167. The grammar never returns a string that contains a caller's
 * value: identifiers are quoted and resolved from the catalogue, and every value
 * becomes a named placeholder. This object is the only thing the executor will
 * execute, which is what makes "no concatenation" a structural property rather
 * than a code-review habit.
 *
 * Placeholders use the `:p0`, `:p1` form, which both PDO drivers accept, so the
 * grammar has one output shape for every engine.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

final readonly class CompiledStatement
{
    /**
     * @param string               $sql      SQL containing only framework-emitted syntax and `:pN` placeholders.
     * @param array<string, mixed> $bindings Placeholder (with colon) to value.
     */
    public function __construct(
        public string $sql,
        public array $bindings = array(),
    ) {
    }

    /**
     * A literal-free rendering for the audit log.
     *
     * By construction `sql` already contains no literals, so this returns it
     * unchanged. It exists to make that guarantee explicit at the call site: an
     * auditor reading the log should never have to reason about whether the
     * grammar could have inlined a value.
     */
    public function template(): string
    {
        return $this->sql;
    }

    /**
     * A stable fingerprint for deduplicating audit rows.
     */
    public function fingerprint(): string
    {
        return hash('sha256', $this->template());
    }
}
