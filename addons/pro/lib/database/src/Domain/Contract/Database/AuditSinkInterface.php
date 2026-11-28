<?php

/**
 * Audit sink the read executor needs.
 *
 * Proposal 167 phase P4. Every executed call and every refusal is recorded with
 * the statement *template* and a fingerprint, never a bound value — so the trail
 * is safe to show an operator and safe to ship to a log aggregator. The sink is
 * an interface because "where the trail lives" is a host decision: a bounded
 * option on WordPress, a PSR-3 channel or a table on Laravel.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Contract\Database;

interface AuditSinkInterface
{
    /**
     * A fresh audit id, unique for this host.
     */
    public function next_id(): string;

    /**
     * Append a record.
     *
     * Never throws: an audit failure must not turn a successful read into an
     * error, and must not mask a refusal that is already propagating.
     *
     * Returns the record id, which a host may already have assigned; callers
     * that need one call {@see self::next_id()} first and may ignore this.
     *
     * @param array<string, mixed> $record Record.
     */
    public function append(array $record): string;
}
