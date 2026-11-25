<?php

/**
 * The controlled-document state machine.
 *
 * Proposal 164 §164.37. ISO 9001 §7.5.2 requires documented information to be
 * controlled for "review and approval … for suitability and adequacy", and §7.5.3
 * for version, availability and the prevention of unintended use. Read together
 * they describe a lifecycle, and this class is that lifecycle written down once
 * rather than enforced differently in ten tools.
 *
 * ## The states
 *
 * ```
 * draft ──▶ review ──▶ approved ──▶ released ──▶ superseded ──▶ obsolete
 *   ▲          │                                        │
 *   └──────────┘  (rejected)                            ▼
 *                                                     obsolete
 * ```
 *
 * `draft → review` is submission. `review → draft` is rejection, and the audit
 * trail keeps the reason. `approved → released` is the point at which a document
 * becomes usable, which is why release is a separate step from approval: an
 * approved document that has not been issued is not current.
 *
 * `obsolete` is terminal. A document that must come back is a new revision, not a
 * reopened one — resurrecting a withdrawn revision would let a superseded
 * document re-enter use, which is precisely what §7.5.3 forbids.
 *
 * ## Why the table is closed
 *
 * A transition not listed here is refused. That is the whole point: an
 * allow-by-default machine would let a caller move a document from `obsolete` to
 * `released` in one call, and the audit trail would faithfully record a state that
 * should never have existed.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Qms;

final class DocumentLifecycle
{
    public const DRAFT = 'draft';
    public const REVIEW = 'review';
    public const APPROVED = 'approved';
    public const RELEASED = 'released';
    public const SUPERSEDED = 'superseded';
    public const OBSOLETE = 'obsolete';

    /**
     * Every state a controlled document can hold.
     *
     * @var list<string>
     */
    public const STATES = array(
        self::DRAFT,
        self::REVIEW,
        self::APPROVED,
        self::RELEASED,
        self::SUPERSEDED,
        self::OBSOLETE,
    );

    /**
     * The transitions the machine allows: target => permitted source states.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = array(
        self::REVIEW => array(self::DRAFT),
        self::DRAFT => array(self::REVIEW),
        self::APPROVED => array(self::REVIEW),
        self::RELEASED => array(self::APPROVED),
        self::SUPERSEDED => array(self::RELEASED),
        self::OBSOLETE => array(self::APPROVED, self::RELEASED, self::SUPERSEDED),
    );

    /**
     * The meanings a signature may carry (21 CFR Part 11 §11.50).
     *
     * §11.50 requires a signature to show *why* it was applied, not only who
     * applied it. This is the closed list of reasons this runtime records.
     *
     * @var list<string>
     */
    public const MEANINGS = array(
        'authored',
        'reviewed',
        'approved',
        'released',
        'witnessed',
        'withdrawn',
    );

    /**
     * Whether a document in `$from` may move to `$to`.
     */
    public static function canTransition(string $from, string $to): bool
    {
        return \in_array(\strtolower($from), self::sourcesFor($to), true);
    }

    /**
     * The states a document may move to `$to` from.
     *
     * @return list<string>
     */
    public static function sourcesFor(string $to): array
    {
        return self::TRANSITIONS[\strtolower($to)] ?? array();
    }

    /**
     * The states a document in `$from` may move to.
     *
     * @return list<string>
     */
    public static function targetsFrom(string $from): array
    {
        $from = \strtolower($from);
        $targets = array();

        foreach (self::TRANSITIONS as $to => $sources) {
            if (\in_array($from, $sources, true)) {
                $targets[] = $to;
            }
        }

        return $targets;
    }

    /**
     * Whether a state name is one this machine knows.
     */
    public static function isState(string $state): bool
    {
        return \in_array(\strtolower($state), self::STATES, true);
    }

    /**
     * Whether a state is terminal — no further transition exists.
     */
    public static function isTerminal(string $state): bool
    {
        return array() === self::targetsFrom($state);
    }

    /**
     * Whether a signature may carry this meaning.
     */
    public static function isMeaning(string $meaning): bool
    {
        return \in_array(\strtolower($meaning), self::MEANINGS, true);
    }

    /**
     * Whether a document in `$state` counts as current for §7.5.3 purposes.
     *
     * Only a released document is current. An approved document has not been
     * issued, and a superseded one has been replaced.
     */
    public static function isCurrent(string $state): bool
    {
        return self::RELEASED === \strtolower($state);
    }
}
