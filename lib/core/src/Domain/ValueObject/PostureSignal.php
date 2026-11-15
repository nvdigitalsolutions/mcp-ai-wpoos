<?php

/**
 * One evaluated posture signal.
 *
 * Wraps a catalogue definition with the runtime's result. `applicable` is the
 * load-bearing field: the scorer only counts applicable signals, so a
 * WordPress-only control never lowers a Laravel score (and vice versa).
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

use Nvoos\Core\Domain\Security\SecuritySignalDefinition;

final class PostureSignal
{
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    /**
     * @param SecuritySignalDefinition $definition Catalogue entry.
     * @param bool                     $passed     Whether the control passes.
     * @param bool                     $applicable Whether the control exists on this runtime.
     * @param string                   $detail     Human explanation shown in the console.
     * @param string                   $label      Runtime-supplied (translated) label; '' uses the catalogue's.
     */
    public function __construct(
        public readonly SecuritySignalDefinition $definition,
        public readonly bool $passed,
        public readonly bool $applicable,
        public readonly string $detail,
        public readonly string $label = '',
    ) {
    }

    public function label(): string
    {
        return '' !== $this->label ? $this->label : $this->definition->label;
    }

    public function weight(): int
    {
        return $this->definition->weight;
    }

    public function status(): string
    {
        if (! $this->applicable) {
            return self::STATUS_NOT_APPLICABLE;
        }

        return $this->passed ? self::STATUS_PASS : self::STATUS_FAIL;
    }

    /**
     * The console's signal shape.
     *
     * `passed` stays false for a not-applicable control so older clients that
     * ignore `applicable` never render it as a passing card.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'id'         => $this->definition->id,
            'label'      => $this->label(),
            'weight'     => $this->definition->weight,
            'passed'     => $this->passed,
            'applicable' => $this->applicable,
            'status'     => $this->status(),
            'detail'     => $this->detail,
            'subtab'     => $this->definition->subtab,
            'anchor'     => $this->definition->anchor,
            'reference'  => $this->definition->reference,
        );
    }
}
