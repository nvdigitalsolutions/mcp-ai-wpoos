<?php

/**
 * Capability-flag vocabulary — one spelling per concept.
 *
 * Three surfaces read tool capability flags, and each had grown its own list:
 * `ReadOnlyToolGuard` decides what a read-only profile may run, `NecessityGate`
 * decides whether a call needs a second thought, and `ActionSafetyProfile`
 * derives irreversibility from the same flags. They disagreed with each other
 * and with the corpus.
 *
 * The WordPress corpus spells `state-changing` in 275 tool classes and
 * `state_changing` in 6; it spells `external-communication` in 15 and uses
 * `external-api` (387) or `network-dependent` (208) for the neighbouring idea.
 * A consumer that reads only one spelling silently ignores the other — which is
 * how the read-only profile came to admit state-changing tools: it gated on
 * `state_changing`, and almost nobody writes that.
 *
 * This class is the single normaliser. Consumers call `normalise()` first, then
 * match canonically.
 *
 * ## Outbound is not automatically irreversible
 *
 * 186 corpus tools declare `external-api` together with `read-only`: a lookup
 * against a remote system. Treating every outbound flag as dangerous would drop
 * them off the safe-tool fast path and send every dataset query to a human.
 * `hasOutboundMutation()` therefore asks the narrower question the name implies
 * — does this call write *outward*? — and answers true for an explicit
 * `external-communication`, or for `external-api`/`network-dependent` paired
 * with `write` or `state-changing`.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Safety;

/**
 * Single source of truth for capability-flag spelling.
 *
 * @since 2.1.0
 */
final class CapabilityFlagVocabulary
{
    /**
     * Flags that disqualify a tool while a read-only profile is active.
     *
     * Mirrors WordPress `WP_MCP_AI_Chat_Profile::READ_ONLY_GATED_FLAGS`, which
     * is the list the chat profile actually enforces. The port had dropped six
     * of these eight and gated on spellings no tool writes.
     *
     * @var list<string>
     */
    public const READ_ONLY_GATED = array(
        'destructive',
        'data-destruction',
        'irreversible',
        'state-changing',
        'write',
        'financial-impact',
        'access-control-change',
        'mass-email',
    );

    /**
     * Flags for a pure, deterministic, in-process computation.
     *
     * A formatter, an encoder, a validator: same input, same output, no I/O.
     * Declared here rather than repeated in every such tool so the four words
     * cannot drift apart.
     *
     * @var list<string>
     */
    public const PURE_LOCAL = array( 'cacheable', 'idempotent', 'local-only', 'read-only' );

    /**
     * Flags for a pure local computation whose output varies per call.
     *
     * A nonce, an id, a diagram handle. Still read-only and still local, but
     * caching or reusing the result would be wrong.
     *
     * @var list<string>
     */
    public const PURE_LOCAL_NON_DETERMINISTIC = array( 'local-only', 'non-deterministic', 'read-only' );

    /**
     * Flags for a read against a local store.
     *
     * Cache, settings, jobs and content lookups. Idempotent and side-effect
     * free, but **not** `cacheable`: the store can change between two identical
     * calls, so caching the answer would serve a stale one.
     *
     * @var list<string>
     */
    public const LOCAL_READ = array( 'idempotent', 'local-only', 'read-only' );

    /**
     * Flags for a write the caller can undo.
     *
     * Cache entries are derived and queue entries can be cancelled, so setting,
     * evicting or scheduling them is reversible. Distinct from
     * {@see LOCAL_WRITE}, which persists information.
     *
     * @var list<string>
     */
    public const LOCAL_WRITE_REVERSIBLE = array( 'local-only', 'reversible', 'state-changing', 'write' );

    /**
     * Flags for a local write that persists information.
     *
     * A content update, an upload, an import, a setting change, an event
     * dispatch. Re-settable in principle, but the previous value is gone — so
     * deliberately **not** `reversible`, which keeps the derived irreversibility
     * at moderate rather than low.
     *
     * @var list<string>
     */
    public const LOCAL_WRITE = array( 'local-only', 'state-changing', 'write' );

    /**
     * Flags for a local write that destroys durable data.
     *
     * @var list<string>
     */
    public const LOCAL_DESTRUCTIVE = array(
        'data-destruction',
        'irreversible',
        'local-only',
        'state-changing',
        'write',
    );

    /**
     * Flags for a call that reaches a third-party API with a stored credential.
     *
     * Outbound but not a mutation: the response is returned, not persisted.
     *
     * @var list<string>
     */
    public const REMOTE_CREDENTIALED = array(
        'external-api',
        'network-dependent',
        'requires-credentials',
    );

    /**
     * Alternate spellings, mapped to the canonical word.
     *
     * @var array<string, string>
     */
    private const ALIASES = array(
        'state_changing'       => 'state-changing',
        'external_side_effect' => 'external-communication',
        'external_api'         => 'external-api',
        'network_dependent'    => 'network-dependent',
    );

    /**
     * Flags that mean data or effect leaves the estate.
     *
     * @var list<string>
     */
    private const OUTBOUND = array(
        'external-communication',
        'external-api',
        'network-dependent',
    );

    /**
     * Flags that mean the call changes something it must first reach.
     *
     * @var list<string>
     */
    private const MUTATING = array( 'write', 'state-changing' );

    /**
     * Lowercase, trim, alias and de-duplicate a flag list.
     *
     * Order is preserved so a denial message names the flags in the order the
     * tool declared them.
     *
     * @param array<int, string> $flags Raw flags from a tool.
     *
     * @return list<string> Canonical flags.
     */
    public static function normalise(array $flags): array
    {
        $normalised = array();

        foreach ($flags as $flag) {
            if (! \is_string($flag)) {
                continue;
            }

            $flag = \strtolower(\trim($flag));

            if ('' === $flag) {
                continue;
            }

            $normalised[] = self::ALIASES[ $flag ] ?? $flag;
        }

        return \array_values(\array_unique($normalised));
    }

    /**
     * Does the list declare one flag?
     *
     * @param array<int, string> $flags Raw flags.
     */
    public static function has(array $flags, string $flag): bool
    {
        return \in_array(self::ALIASES[ $flag ] ?? \strtolower($flag), self::normalise($flags), true);
    }

    /**
     * Does the list declare any of these flags?
     *
     * @param array<int, string> $flags      Raw flags.
     * @param array<int, string> $candidates Flags to look for.
     */
    public static function hasAny(array $flags, array $candidates): bool
    {
        $normalised = self::normalise($flags);

        foreach ($candidates as $candidate) {
            if (\in_array(self::ALIASES[ $candidate ] ?? \strtolower($candidate), $normalised, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this call write outward?
     *
     * True for an explicit `external-communication`, and for an outbound
     * transport paired with a mutation. A read-only remote lookup is not a
     * mutation, so it stays on the safe-tool path.
     *
     * @param array<int, string> $flags Raw flags.
     */
    public static function hasOutboundMutation(array $flags): bool
    {
        $normalised = self::normalise($flags);

        if (\in_array('external-communication', $normalised, true)) {
            return true;
        }

        $outbound = \array_intersect($normalised, self::OUTBOUND);
        $mutating = \array_intersect($normalised, self::MUTATING);

        return array() !== $outbound && array() !== $mutating;
    }

    /**
     * Flags that disqualify a tool while a read-only profile is active.
     *
     * A method rather than only a constant so a caller can pass a config value
     * through the same normalisation.
     *
     * @return list<string>
     */
    public static function readOnlyGated(): array
    {
        return self::READ_ONLY_GATED;
    }
}
