<?php

/**
 * Necessity gate — refuse tool calls the task does not actually need.
 *
 * The read-only guard answers "is this tool allowed at all". This answers a
 * different question: given that the agent may call this tool, should *this*
 * call happen? An agent that deletes a post with no id, or sends mail with no
 * recipient, is not doing something forbidden — it is doing something
 * overeager, and the cheapest fix is to stop it before it costs a provider
 * round trip.
 *
 * Three tiers, in order:
 *
 *   1. **Safe tools** are allowed without further thought: read-only, and
 *      declaring none of the dangerous flags.
 *   2. **Necessity** is classified by fast heuristics — no model call. It is
 *      deliberately conservative and defaults to `helpful`, because guessing
 *      `unnecessary` costs a legitimate action.
 *   3. **The verdict** comes from `ActionSafetyProfile`'s necessity ×
 *      irreversibility matrix.
 *
 * The result is structured, never an error object: this class decides, the
 * runtime renders and logs. That is what lets one matrix serve WordPress
 * (`WP_Error`, `__()`, the approval queue) and a console without those.
 *
 * Ported from `includes/harness/class-wp-mcp-ai-necessity-gate.php`.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Safety;

final class NecessityGate
{
    /** The tool is read-only and declares nothing dangerous. */
    public const REASON_SAFE_TOOL = 'safe_tool';

    /** An unnecessary call that cannot do damage — skipped silently. */
    public const REASON_UNNECESSARY_READ_ONLY = 'unnecessary_read_only';

    /** Search tools whose only useful input is a query. */
    private const SEARCH_TOOLS = array(
        'web_search',
        'brave_web_search',
        'brave_local_search',
        'search_content',
        'search_attachments',
        'search_content_validated',
    );

    /** Tools that are meaningless without a target id. */
    private const ID_TOOLS = array('get_post', 'delete_post', 'get_woo_product', 'get_wc_product');

    /** Fields that satisfy an id-based tool, in the port's order. */
    private const ID_FIELDS = array('post_id', 'product_id', 'id', 'ID');

    /**
     * Tier 1 — a tool that cannot do harm, so the gate has nothing to add.
     *
     * @param array<int, string> $flags Capability flags.
     */
    public static function isSafeTool(array $flags): bool
    {
        $flags = \array_map('strtolower', $flags);

        if (! \in_array('read-only', $flags, true)) {
            return false;
        }

        $dangerous = array(
            'irreversible',
            'financial-impact',
            'external-communication',
            'data-destruction',
            'access-control-change',
        );

        foreach ($dangerous as $flag) {
            if (\in_array($flag, $flags, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Tier 2 — classify how much the task needs this call.
     *
     * @param callable(string, array<string, mixed>): (string|null) |null $classifier
     *        Optional override, replacing WordPress's
     *        `wp_mcp_ai_necessity_gate_classify` filter. Returning a valid
     *        level wins; anything else falls through to the heuristics.
     * @return string One of the `ActionSafetyProfile::NECESSITY_*` levels.
     */
    public static function classifyNecessity(string $slug, array $arguments, ?callable $classifier = null): string
    {
        if (null !== $classifier) {
            $override = $classifier($slug, $arguments);

            if (\is_string($override) && ActionSafetyProfile::isValidNecessity($override)) {
                return $override;
            }
        }

        // A destructive call with no target is worse than merely empty: it is
        // UNNECESSARY, one rung below the generic OPTIONAL for empty input.
        if (self::isOvereagerPattern($slug, $arguments)) {
            return ActionSafetyProfile::NECESSITY_UNNECESSARY;
        }

        if (self::hasEmptyArguments($arguments, $slug)) {
            return ActionSafetyProfile::NECESSITY_OPTIONAL;
        }

        return ActionSafetyProfile::NECESSITY_HELPFUL;
    }

    /**
     * Decide whether a call should run.
     *
     * @param array<int, string>         $flags      Tool capability flags.
     * @param array<string, mixed>       $arguments  Parsed tool arguments.
     * @param float|null                 $irreversibility Explicit score from the tool, if it declares one.
     * @param callable(string, array<string, mixed>): (string|null) |null $classifier Optional necessity override.
     * @return array{
     *   verdict: string,
     *   reason_code: string,
     *   reason: string,
     *   risk_score: float,
     *   irreversibility: float,
     *   necessity: string|null,
     *   requires_approval: bool
     * } `necessity` is null when tier 1 short-circuits before classifying.
     */
    public static function decide(
        array $flags,
        string $slug,
        array $arguments,
        ?float $irreversibility = null,
        ?callable $classifier = null
    ): array {
        if (self::isSafeTool($flags)) {
            return array(
                'verdict' => ActionSafetyProfile::VERDICT_ALLOW,
                'reason_code' => self::REASON_SAFE_TOOL,
                'reason' => 'Read-only tool with no dangerous capability flags — allowed.',
                'risk_score' => 0.0,
                'irreversibility' => ActionSafetyProfile::IRREVERSIBILITY_NONE,
                'necessity' => null,
                'requires_approval' => false,
            );
        }

        $irreversibility ??= ActionSafetyProfile::deriveIrreversibilityFromFlags($flags);
        $necessity = self::classifyNecessity($slug, $arguments, $classifier);

        // Unnecessary *and* harmless: skip silently rather than route the
        // matrix, which would only produce the same answer more verbosely.
        if (
            ActionSafetyProfile::NECESSITY_UNNECESSARY === $necessity
            && $irreversibility <= ActionSafetyProfile::IRREVERSIBILITY_NONE
        ) {
            return array(
                'verdict' => ActionSafetyProfile::VERDICT_SKIP,
                'reason_code' => self::REASON_UNNECESSARY_READ_ONLY,
                'reason' => 'Action is unnecessary and read-only — skipped.',
                'risk_score' => ActionSafetyProfile::computeRiskScore($irreversibility, $necessity),
                'irreversibility' => $irreversibility,
                'necessity' => $necessity,
                'requires_approval' => false,
            );
        }

        $verdict = ActionSafetyProfile::determineVerdict($irreversibility, $necessity);

        return array(
            'verdict' => $verdict['verdict'],
            'reason_code' => $verdict['reason_code'],
            'reason' => $verdict['reason'],
            'risk_score' => $verdict['risk_score'],
            'irreversibility' => $irreversibility,
            'necessity' => $necessity,
            'requires_approval' => $verdict['requires_approval'],
        );
    }

    /**
     * A call with no arguments, or none that could satisfy the tool.
     *
     * @param array<string, mixed> $arguments
     */
    private static function hasEmptyArguments(array $arguments, string $slug): bool
    {
        if (array() === $arguments) {
            return true;
        }

        if (\in_array($slug, self::SEARCH_TOOLS, true)) {
            $query = isset($arguments['query']) ? \trim((string) $arguments['query']) : '';
            $search = isset($arguments['search']) ? \trim((string) $arguments['search']) : '';

            if ('' === $query && '' === $search) {
                return true;
            }
        }

        if (\in_array($slug, self::ID_TOOLS, true)) {
            foreach (self::ID_FIELDS as $field) {
                if (isset($arguments[ $field ]) && (int) $arguments[ $field ] > 0) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Known overeager shapes: a high-impact call missing the one input that
     * would make it meaningful.
     *
     * @param array<string, mixed> $arguments
     */
    private static function isOvereagerPattern(string $slug, array $arguments): bool
    {
        if ('delete_post' === $slug && empty($arguments['post_id']) && empty($arguments['ID'])) {
            return true;
        }

        if (\in_array($slug, array('send_email', 'wp_mail'), true)) {
            if (empty($arguments['to']) && empty($arguments['recipient'])) {
                return true;
            }
        }

        if ('create_woo_product' === $slug) {
            if (empty($arguments['reference']) && empty($arguments['sku'])) {
                return true;
            }
        }

        return false;
    }
}
