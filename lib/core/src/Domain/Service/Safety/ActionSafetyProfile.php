<?php

/**
 * Action safety profile — the necessity × irreversibility decision matrix.
 *
 * Two independent questions decide whether an agent may run a tool call:
 * how much damage the call can do if it is wrong (irreversibility), and how
 * much the task actually needs it (necessity). This class owns both scales and
 * the matrix that turns a pair of them into a verdict.
 *
 * ```
 *                    LOW IRREV           HIGH IRREV
 * HIGH NECESSITY     allow / warn        approval / block
 * LOW NECESSITY      skip                block
 * ```
 *
 * **No copy lives here.** Every verdict carries a stable `reason_code`; a
 * runtime maps that code to its own translated sentence. The English `reason`
 * is for logs and traces only. This keeps the matrix single-sourced while
 * leaving user-facing text where translation infrastructure exists.
 *
 * Ported from `includes/domain/class-wp-mcp-ai-action-safety-profile.php`.
 * The WordPress class keeps its translated label tables and delegates the
 * arithmetic and the matrix to this class.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Safety;

final class ActionSafetyProfile
{
    /** Read-only, no side effects. */
    public const IRREVERSIBILITY_NONE = 0.0;

    /** Creates drafts, can be trashed or restored. */
    public const IRREVERSIBILITY_LOW = 0.25;

    /** Publishes or modifies live data. */
    public const IRREVERSIBILITY_MODERATE = 0.5;

    /** Deletes data or sends external communication. */
    public const IRREVERSIBILITY_HIGH = 0.75;

    /** Wipes data, moves money, or takes legal actions. */
    public const IRREVERSIBILITY_PERMANENT = 1.0;

    /** The user asked for it; the task cannot complete without it. */
    public const NECESSITY_ESSENTIAL = 'essential';

    /** Aids the task but is not strictly required. */
    public const NECESSITY_HELPFUL = 'helpful';

    /** Could add value but is likely overkill. */
    public const NECESSITY_OPTIONAL = 'optional';

    /** Redundant, overeager, or not aligned with intent. */
    public const NECESSITY_UNNECESSARY = 'unnecessary';

    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_WARN = 'warn';
    public const VERDICT_APPROVAL_REQUIRED = 'approval_required';
    public const VERDICT_SKIP = 'skip';
    public const VERDICT_BLOCK = 'block';

    public const REASON_UNNECESSARY_IRREVERSIBLE = 'unnecessary_irreversible';
    public const REASON_UNNECESSARY_SKIPPED = 'unnecessary_skipped';
    public const REASON_OPTIONAL_LOW_RISK = 'optional_low_risk';
    public const REASON_OPTIONAL_HIGH_RISK = 'optional_high_risk';
    public const REASON_OPTIONAL_NEEDS_APPROVAL = 'optional_needs_approval';
    public const REASON_HELPFUL_WITHIN_BOUNDS = 'helpful_within_bounds';
    public const REASON_HELPFUL_PERMANENT = 'helpful_permanent';
    public const REASON_HELPFUL_NEEDS_APPROVAL = 'helpful_needs_approval';
    public const REASON_ESSENTIAL_NEEDS_APPROVAL = 'essential_needs_approval';
    public const REASON_ESSENTIAL_HIGH_RISK = 'essential_high_risk';
    public const REASON_ESSENTIAL_SAFE = 'essential_safe';

    /**
     * How much an unmet need adds to the risk score.
     *
     * An unknown level is weighted as `helpful`, matching the port.
     *
     * @var array<string, float>
     */
    private const NECESSITY_WEIGHTS = array(
        self::NECESSITY_ESSENTIAL => 0.0,
        self::NECESSITY_HELPFUL => 0.5,
        self::NECESSITY_OPTIONAL => 0.75,
        self::NECESSITY_UNNECESSARY => 1.0,
    );

    /**
     * Combined risk score, 0.0 (safe and essential) to 2.0 (permanent and
     * unnecessary).
     */
    public static function computeRiskScore(float $irreversibility, string $necessity): float
    {
        $irreversibility = \max(0.0, \min(1.0, $irreversibility));

        return $irreversibility + ( self::NECESSITY_WEIGHTS[ $necessity ] ?? 0.5 );
    }

    /**
     * Decide the verdict for a tool call.
     *
     * @return array{
     *   verdict: string,
     *   risk_score: float,
     *   reason_code: string,
     *   reason: string,
     *   requires_approval: bool
     * } `reason` is English for logs; runtimes should render `reason_code`.
     */
    public static function determineVerdict(float $irreversibility, string $necessity): array
    {
        $irreversibility = \max(0.0, \min(1.0, $irreversibility));
        $riskScore = self::computeRiskScore($irreversibility, $necessity);

        // Unnecessary work is skipped when it is reversible and blocked when
        // it is not. This is the tier that stops overeager calls.
        if (self::NECESSITY_UNNECESSARY === $necessity) {
            if ($irreversibility >= self::IRREVERSIBILITY_HIGH) {
                return self::verdict(
                    self::VERDICT_BLOCK,
                    $riskScore,
                    self::REASON_UNNECESSARY_IRREVERSIBLE,
                    'Action is unnecessary and irreversible — blocked.',
                    true
                );
            }

            return self::verdict(
                self::VERDICT_SKIP,
                $riskScore,
                self::REASON_UNNECESSARY_SKIPPED,
                'Action is unnecessary — skipped to save tokens and avoid overeager behavior.',
                false
            );
        }

        if (self::NECESSITY_OPTIONAL === $necessity) {
            if ($irreversibility <= self::IRREVERSIBILITY_LOW) {
                return self::verdict(
                    self::VERDICT_WARN,
                    $riskScore,
                    self::REASON_OPTIONAL_LOW_RISK,
                    'Action is optional but low-risk — allowed with warning.',
                    false
                );
            }

            if ($irreversibility >= self::IRREVERSIBILITY_HIGH) {
                return self::verdict(
                    self::VERDICT_BLOCK,
                    $riskScore,
                    self::REASON_OPTIONAL_HIGH_RISK,
                    'Action is optional and high-risk — blocked.',
                    true
                );
            }

            return self::verdict(
                self::VERDICT_APPROVAL_REQUIRED,
                $riskScore,
                self::REASON_OPTIONAL_NEEDS_APPROVAL,
                'Action is optional with moderate risk — requires human approval.',
                true
            );
        }

        if (self::NECESSITY_HELPFUL === $necessity) {
            if ($irreversibility <= self::IRREVERSIBILITY_MODERATE) {
                // Exactly MODERATE warns; below it allows. The boundary is
                // deliberate — moderate irreversibility is the last rung
                // before a human has to look at it.
                $verdict = $irreversibility >= self::IRREVERSIBILITY_MODERATE
                    ? self::VERDICT_WARN
                    : self::VERDICT_ALLOW;

                return self::verdict(
                    $verdict,
                    $riskScore,
                    self::REASON_HELPFUL_WITHIN_BOUNDS,
                    'Action is helpful and within acceptable risk bounds.',
                    false
                );
            }

            if ($irreversibility >= self::IRREVERSIBILITY_PERMANENT) {
                return self::verdict(
                    self::VERDICT_BLOCK,
                    $riskScore,
                    self::REASON_HELPFUL_PERMANENT,
                    'Action is helpful but permanently irreversible — blocked.',
                    true
                );
            }

            return self::verdict(
                self::VERDICT_APPROVAL_REQUIRED,
                $riskScore,
                self::REASON_HELPFUL_NEEDS_APPROVAL,
                'Action is helpful but high-risk — requires human approval.',
                true
            );
        }

        // Essential: allowed up to high irreversibility, human-in-the-loop
        // only when the action cannot be undone at all.
        if ($irreversibility >= self::IRREVERSIBILITY_PERMANENT) {
            return self::verdict(
                self::VERDICT_APPROVAL_REQUIRED,
                $riskScore,
                self::REASON_ESSENTIAL_NEEDS_APPROVAL,
                'Action is essential but permanently irreversible — requires human approval.',
                true
            );
        }

        if ($irreversibility >= self::IRREVERSIBILITY_HIGH) {
            return self::verdict(
                self::VERDICT_WARN,
                $riskScore,
                self::REASON_ESSENTIAL_HIGH_RISK,
                'Action is essential but high-risk — allowed with warning.',
                false
            );
        }

        return self::verdict(
            self::VERDICT_ALLOW,
            $riskScore,
            self::REASON_ESSENTIAL_SAFE,
            'Action is essential and within safe bounds — allowed.',
            false
        );
    }

    /**
     * Default irreversibility for a tool that declares capability flags only.
     *
     * An explicit score on the tool wins; this is the fallback, so it errs
     * high when the flags are ambiguous. Flags are normalised first, so an
     * underscore spelling is scored rather than silently ignored, and an
     * outbound transport counts as high only when the call can write through it.
     *
     * @param array<int, string> $flags Capability flags.
     */
    public static function deriveIrreversibilityFromFlags(array $flags): float
    {
        $flags = CapabilityFlagVocabulary::normalise($flags);

        if (\in_array('irreversible', $flags, true)) {
            return self::IRREVERSIBILITY_PERMANENT;
        }

        $dangerous = array('financial-impact', 'data-destruction', 'access-control-change', 'mass-email');

        foreach ($dangerous as $flag) {
            if (\in_array($flag, $flags, true)) {
                return self::IRREVERSIBILITY_HIGH;
            }
        }

        if (CapabilityFlagVocabulary::hasOutboundMutation($flags)) {
            return self::IRREVERSIBILITY_HIGH;
        }

        if (\in_array('write', $flags, true) && \in_array('state-changing', $flags, true)) {
            return \in_array('reversible', $flags, true)
                ? self::IRREVERSIBILITY_LOW
                : self::IRREVERSIBILITY_MODERATE;
        }

        if (
            \in_array('read-only', $flags, true)
            && ! CapabilityFlagVocabulary::hasAny($flags, array('write', 'state-changing'))
        ) {
            return self::IRREVERSIBILITY_NONE;
        }

        // Undeclared is not the same as safe.
        return self::IRREVERSIBILITY_MODERATE;
    }

    public static function isValidIrreversibility(float $score): bool
    {
        return \in_array(
            $score,
            array(
                self::IRREVERSIBILITY_NONE,
                self::IRREVERSIBILITY_LOW,
                self::IRREVERSIBILITY_MODERATE,
                self::IRREVERSIBILITY_HIGH,
                self::IRREVERSIBILITY_PERMANENT,
            ),
            true
        );
    }

    public static function isValidNecessity(string $level): bool
    {
        return \in_array(
            $level,
            array(
                self::NECESSITY_ESSENTIAL,
                self::NECESSITY_HELPFUL,
                self::NECESSITY_OPTIONAL,
                self::NECESSITY_UNNECESSARY,
            ),
            true
        );
    }

    /**
     * @return array{verdict: string, risk_score: float, reason_code: string, reason: string, requires_approval: bool}
     */
    private static function verdict(
        string $verdict,
        float $riskScore,
        string $reasonCode,
        string $reason,
        bool $requiresApproval
    ): array {
        return array(
            'verdict' => $verdict,
            'risk_score' => $riskScore,
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'requires_approval' => $requiresApproval,
        );
    }
}
