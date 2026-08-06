<?php
/**
 * Action Safety Profile — irreversibility scores and necessity levels.
 *
 * Domain-layer value object that encodes the safety characteristics of a
 * tool action: how irreversible it is, what minimum necessity threshold
 * applies, and how these combine into a gating decision.
 *
 * Pure PHP — no WordPress or infrastructure dependencies.
 *
 * Industry references:
 * - Anthropic's reversibility-weighted risk assessment (Claude Code auto mode)
 * - PolicyLayer MCP risk classification (read/write/execute/destructive+financial)
 * - Anthropic research: only 0.8% of agent actions are irreversible
 *
 * @package WP_MCP_AI
 * @since   1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action Safety Profile.
 *
 * Encodes irreversibility and necessity metadata for tool actions.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Action_Safety_Profile {

	/**
	 * Fully reversible — read-only, no side effects.
	 *
	 * Examples: get_post, search_content, list_users
	 *
	 * @since 1.9.0
	 * @var float
	 */
	const IRREVERSIBILITY_NONE = 0.0;

	/**
	 * Low irreversibility — creates drafts, can be trashed/restored.
	 *
	 * Examples: create_post (draft), update_post_meta, add_option
	 *
	 * @since 1.9.0
	 * @var float
	 */
	const IRREVERSIBILITY_LOW = 0.25;

	/**
	 * Moderate irreversibility — publishes, modifies live data.
	 *
	 * Examples: publish_post, update_published_page, set_transient
	 *
	 * @since 1.9.0
	 * @var float
	 */
	const IRREVERSIBILITY_MODERATE = 0.5;

	/**
	 * High irreversibility — deletes, sends external communication.
	 *
	 * Examples: delete_post, send_email, create_woo_product
	 *
	 * @since 1.9.0
	 * @var float
	 */
	const IRREVERSIBILITY_HIGH = 0.75;

	/**
	 * Permanently irreversible — wipes data, processes payments, legal actions.
	 *
	 * Examples: force_delete_post, process_refund, revoke_user_access
	 *
	 * @since 1.9.0
	 * @var float
	 */
	const IRREVERSIBILITY_PERMANENT = 1.0;

	/**
	 * Essential — user explicitly requested this action.
	 *
	 * The task cannot be completed without this tool call.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const NECESSITY_ESSENTIAL = 'essential';

	/**
	 * Helpful — aids the task but a partial answer could be given without it.
	 *
	 * The tool call improves quality but the task is not blocked without it.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const NECESSITY_HELPFUL = 'helpful';

	/**
	 * Optional — could add value but likely overkill for the current task.
	 *
	 * Skipping this call would yield an acceptable answer.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const NECESSITY_OPTIONAL = 'optional';

	/**
	 * Unnecessary — redundant, overeager, or not aligned with user intent.
	 *
	 * Calling this tool is a sign of overeager behavior.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const NECESSITY_UNNECESSARY = 'unnecessary';

	/**
	 * Gating verdict: allow execution without restriction.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const VERDICT_ALLOW = 'allow';

	/**
	 * Gating verdict: allow execution but log a warning.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const VERDICT_WARN = 'warn';

	/**
	 * Gating verdict: require human-in-the-loop approval before execution.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const VERDICT_APPROVAL_REQUIRED = 'approval_required';

	/**
	 * Gating verdict: skip this tool call entirely (not necessary).
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const VERDICT_SKIP = 'skip';

	/**
	 * Gating verdict: block execution (too dangerous for the necessity level).
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const VERDICT_BLOCK = 'block';

	/**
	 * Get all irreversibility level constants with their labels.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, array{score: float, label: string, description: string}>
	 */
	public static function get_irreversibility_levels() {
		return array(
			'none'      => array(
				'score'       => self::IRREVERSIBILITY_NONE,
				'label'       => __( 'None', 'mcp-ai-wpoos' ),
				'description' => __( 'Read-only, no side effects. Completely safe.', 'mcp-ai-wpoos' ),
			),
			'low'       => array(
				'score'       => self::IRREVERSIBILITY_LOW,
				'label'       => __( 'Low', 'mcp-ai-wpoos' ),
				'description' => __( 'Creates drafts, can be trashed or restored.', 'mcp-ai-wpoos' ),
			),
			'moderate'  => array(
				'score'       => self::IRREVERSIBILITY_MODERATE,
				'label'       => __( 'Moderate', 'mcp-ai-wpoos' ),
				'description' => __( 'Publishes or modifies live data.', 'mcp-ai-wpoos' ),
			),
			'high'      => array(
				'score'       => self::IRREVERSIBILITY_HIGH,
				'label'       => __( 'High', 'mcp-ai-wpoos' ),
				'description' => __( 'Deletes data or sends external communication.', 'mcp-ai-wpoos' ),
			),
			'permanent' => array(
				'score'       => self::IRREVERSIBILITY_PERMANENT,
				'label'       => __( 'Permanent', 'mcp-ai-wpoos' ),
				'description' => __( 'Permanently wipes data, processes payments, or takes legal actions.', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Get all necessity level constants with their labels.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function get_necessity_levels() {
		return array(
			self::NECESSITY_ESSENTIAL   => array(
				'label'       => __( 'Essential', 'mcp-ai-wpoos' ),
				'description' => __( 'User explicitly requested; task cannot be completed without it.', 'mcp-ai-wpoos' ),
			),
			self::NECESSITY_HELPFUL     => array(
				'label'       => __( 'Helpful', 'mcp-ai-wpoos' ),
				'description' => __( 'Aids the task but not strictly required for a useful answer.', 'mcp-ai-wpoos' ),
			),
			self::NECESSITY_OPTIONAL    => array(
				'label'       => __( 'Optional', 'mcp-ai-wpoos' ),
				'description' => __( 'Could add value but likely overkill for the current task.', 'mcp-ai-wpoos' ),
			),
			self::NECESSITY_UNNECESSARY => array(
				'label'       => __( 'Unnecessary', 'mcp-ai-wpoos' ),
				'description' => __( 'Redundant, overeager, or not aligned with user intent.', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Get all gating verdict constants with their labels.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, string>
	 */
	public static function get_verdicts() {
		return array(
			self::VERDICT_ALLOW             => __( 'Allow', 'mcp-ai-wpoos' ),
			self::VERDICT_WARN              => __( 'Warn', 'mcp-ai-wpoos' ),
			self::VERDICT_APPROVAL_REQUIRED => __( 'Approval Required', 'mcp-ai-wpoos' ),
			self::VERDICT_SKIP              => __( 'Skip', 'mcp-ai-wpoos' ),
			self::VERDICT_BLOCK             => __( 'Block', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Compute a combined risk score from irreversibility and necessity.
	 *
	 * Higher scores indicate more dangerous / less justified actions.
	 * The score ranges from 0.0 (safe + essential) to 2.0 (irreversible + unnecessary).
	 *
	 * @since 1.9.0
	 *
	 * @param float  $irreversibility Irreversibility score (0.0–1.0).
	 * @param string $necessity       Necessity level slug.
	 * @return float Combined risk score (0.0–2.0).
	 */
	public static function compute_risk_score( $irreversibility, $necessity ) {
		$irreversibility = max( 0.0, min( 1.0, (float) $irreversibility ) );

		$necessity_weights = array(
			self::NECESSITY_ESSENTIAL   => 0.0,
			self::NECESSITY_HELPFUL     => 0.5,
			self::NECESSITY_OPTIONAL    => 0.75,
			self::NECESSITY_UNNECESSARY => 1.0,
		);

		$necessity_weight = isset( $necessity_weights[ $necessity ] )
			? $necessity_weights[ $necessity ]
			: 0.5; // Unknown necessity defaults to "helpful".

		return $irreversibility + $necessity_weight;
	}

	/**
	 * Determine the gating verdict for a tool call.
	 *
	 * Uses the necessity × irreversibility decision matrix:
	 *
	 *                    LOW IRREV         HIGH IRREV
	 * HIGH NECESSITY     allow             warn / approval
	 * LOW NECESSITY      skip              block
	 *
	 * @since 1.9.0
	 *
	 * @param float  $irreversibility Irreversibility score (0.0–1.0).
	 * @param string $necessity       Necessity level slug.
	 * @return array{verdict: string, risk_score: float, reason: string, requires_approval: bool}
	 */
	public static function determine_verdict( $irreversibility, $necessity ) {
		$irreversibility = max( 0.0, min( 1.0, (float) $irreversibility ) );
		$risk_score      = self::compute_risk_score( $irreversibility, $necessity );

		// Unnecessary actions are always skipped or blocked.
		if ( self::NECESSITY_UNNECESSARY === $necessity ) {
			if ( $irreversibility >= self::IRREVERSIBILITY_HIGH ) {
				return array(
					'verdict'           => self::VERDICT_BLOCK,
					'risk_score'        => $risk_score,
					'reason'            => __( 'Action is unnecessary and irreversible — blocked.', 'mcp-ai-wpoos' ),
					'requires_approval' => true,
				);
			}
			return array(
				'verdict'           => self::VERDICT_SKIP,
				'risk_score'        => $risk_score,
				'reason'            => __( 'Action is unnecessary — skipped to save tokens and avoid overeager behavior.', 'mcp-ai-wpoos' ),
				'requires_approval' => false,
			);
		}

		// Optional actions are skipped unless low-irreversibility.
		if ( self::NECESSITY_OPTIONAL === $necessity ) {
			if ( $irreversibility <= self::IRREVERSIBILITY_LOW ) {
				return array(
					'verdict'           => self::VERDICT_WARN,
					'risk_score'        => $risk_score,
					'reason'            => __( 'Action is optional but low-risk — allowed with warning.', 'mcp-ai-wpoos' ),
					'requires_approval' => false,
				);
			}
			if ( $irreversibility >= self::IRREVERSIBILITY_HIGH ) {
				return array(
					'verdict'           => self::VERDICT_BLOCK,
					'risk_score'        => $risk_score,
					'reason'            => __( 'Action is optional and high-risk — blocked.', 'mcp-ai-wpoos' ),
					'requires_approval' => true,
				);
			}
			return array(
				'verdict'           => self::VERDICT_APPROVAL_REQUIRED,
				'risk_score'        => $risk_score,
				'reason'            => __( 'Action is optional with moderate risk — requires human approval.', 'mcp-ai-wpoos' ),
				'requires_approval' => true,
			);
		}

		// Helpful actions: allowed up to moderate irreversibility, then require approval.
		if ( self::NECESSITY_HELPFUL === $necessity ) {
			if ( $irreversibility <= self::IRREVERSIBILITY_MODERATE ) {
				$verdict = $irreversibility >= self::IRREVERSIBILITY_MODERATE
					? self::VERDICT_WARN
					: self::VERDICT_ALLOW;
				return array(
					'verdict'           => $verdict,
					'risk_score'        => $risk_score,
					'reason'            => __( 'Action is helpful and within acceptable risk bounds.', 'mcp-ai-wpoos' ),
					'requires_approval' => false,
				);
			}
			if ( $irreversibility >= self::IRREVERSIBILITY_PERMANENT ) {
				return array(
					'verdict'           => self::VERDICT_BLOCK,
					'risk_score'        => $risk_score,
					'reason'            => __( 'Action is helpful but permanently irreversible — blocked.', 'mcp-ai-wpoos' ),
					'requires_approval' => true,
				);
			}
			return array(
				'verdict'           => self::VERDICT_APPROVAL_REQUIRED,
				'risk_score'        => $risk_score,
				'reason'            => __( 'Action is helpful but high-risk — requires human approval.', 'mcp-ai-wpoos' ),
				'requires_approval' => true,
			);
		}

		// Essential actions: allowed up to high irreversibility, HITL for permanent.
		if ( $irreversibility >= self::IRREVERSIBILITY_PERMANENT ) {
			return array(
				'verdict'           => self::VERDICT_APPROVAL_REQUIRED,
				'risk_score'        => $risk_score,
				'reason'            => __( 'Action is essential but permanently irreversible — requires human approval.', 'mcp-ai-wpoos' ),
				'requires_approval' => true,
			);
		}
		if ( $irreversibility >= self::IRREVERSIBILITY_HIGH ) {
			return array(
				'verdict'           => self::VERDICT_WARN,
				'risk_score'        => $risk_score,
				'reason'            => __( 'Action is essential but high-risk — allowed with warning.', 'mcp-ai-wpoos' ),
				'requires_approval' => false,
			);
		}

		return array(
			'verdict'           => self::VERDICT_ALLOW,
			'risk_score'        => $risk_score,
			'reason'            => __( 'Action is essential and within safe bounds — allowed.', 'mcp-ai-wpoos' ),
			'requires_approval' => false,
		);
	}

	/**
	 * Derive a default irreversibility score from tool capability flags.
	 *
	 * Used when a tool does not explicitly declare its irreversibility score.
	 *
	 * @since 1.9.0
	 *
	 * @param array $flags Capability flags from the tool.
	 * @return float Irreversibility score (0.0–1.0).
	 */
	public static function derive_irreversibility_from_flags( array $flags ) {
		// Explicit irreversible flag takes precedence.
		if ( in_array( 'irreversible', $flags, true ) ) {
			return self::IRREVERSIBILITY_PERMANENT;
		}
		if ( in_array( 'financial-impact', $flags, true ) ) {
			return self::IRREVERSIBILITY_HIGH;
		}
		if ( in_array( 'external-communication', $flags, true ) ) {
			return self::IRREVERSIBILITY_HIGH;
		}
		if ( in_array( 'data-destruction', $flags, true ) ) {
			return self::IRREVERSIBILITY_HIGH;
		}
		if ( in_array( 'access-control-change', $flags, true ) ) {
			return self::IRREVERSIBILITY_HIGH;
		}

		// Destructive write operations.
		if ( in_array( 'write', $flags, true ) && in_array( 'state-changing', $flags, true ) ) {
			// If the tool says it's reversible, score lower.
			if ( in_array( 'reversible', $flags, true ) ) {
				return self::IRREVERSIBILITY_LOW;
			}
			return self::IRREVERSIBILITY_MODERATE;
		}

		// Read-only tools are fully reversible.
		if ( in_array( 'read-only', $flags, true ) ) {
			return self::IRREVERSIBILITY_NONE;
		}

		// Default: moderate. When we can't determine, err on the side of caution.
		return self::IRREVERSIBILITY_MODERATE;
	}

	/**
	 * Validate an irreversibility score.
	 *
	 * @since 1.9.0
	 *
	 * @param float $score Score to validate.
	 * @return bool True if the score is a valid irreversibility level.
	 */
	public static function is_valid_irreversibility( $score ) {
		$valid = array(
			self::IRREVERSIBILITY_NONE,
			self::IRREVERSIBILITY_LOW,
			self::IRREVERSIBILITY_MODERATE,
			self::IRREVERSIBILITY_HIGH,
			self::IRREVERSIBILITY_PERMANENT,
		);
		return in_array( (float) $score, $valid, true );
	}

	/**
	 * Validate a necessity level slug.
	 *
	 * @since 1.9.0
	 *
	 * @param string $level Necessity level to validate.
	 * @return bool True if valid.
	 */
	public static function is_valid_necessity( $level ) {
		$valid = array(
			self::NECESSITY_ESSENTIAL,
			self::NECESSITY_HELPFUL,
			self::NECESSITY_OPTIONAL,
			self::NECESSITY_UNNECESSARY,
		);
		return in_array( $level, $valid, true );
	}
}
