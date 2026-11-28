<?php

/**
 * Audit sink that hands records to a PSR-3 logger.
 *
 * Proposal 167 phase P3. Records carry the statement *template* and a
 * fingerprint, never a bound value, so the trail is safe to ship to a log
 * aggregator. `append()` must never throw: an audit failure cannot be allowed to
 * turn a successful read into an error, or to mask a refusal already
 * propagating.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Database;

use Closure;
use Nvoos\Database\Domain\Contract\Database\AuditSinkInterface;
use Throwable;

final class LoggerAuditSink implements AuditSinkInterface
{
    /**
     * @param Closure(array<string, mixed>):void|null $writer Receives each record.
     */
    public function __construct(
        private readonly ?Closure $writer = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function next_id(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * {@inheritDoc}
     */
    public function append(array $record): string
    {
        if (null === $this->writer) {
            return (string) ($record['id'] ?? '');
        }

        try {
            ($this->writer)($record);
        } catch (Throwable $error) {
            // Deliberately swallowed: see the class docblock.
            unset($error);
        }

        return (string) ($record['id'] ?? '');
    }
}
