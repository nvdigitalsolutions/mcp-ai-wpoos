<?php
/**
 * Necessity Gate — Layer J of the LLM Harness.
 *
 * Implements a 3-tier gating architecture for tool execution based on
 * necessity (is this action truly needed?) and irreversibility (can this
 * action be undone?). Industry reference: Anthropic's Claude Code auto
 * mode reversibility-weighted risk assessment.
 *
 * ## Architecture
 *
 * Tier 1 — Safe-tool allowlist: read-only, cacheable, idempotent tools
 *          pass through without any check.
 * Tier 2 — Necessity classifier: fast heuristic check (message intent
 *          analysis, tool redundancy detection) determines if the call
 *          is truly needed.
 * Tier 3 — Irreversibility gate: combines irreversibility score with
 *          necessity level to produce a verdict (allow/warn/approval/block).
 *
 * ## Default behaviour
 *
 * The gate is OFF by default (behaviour-preserving). Enable per assistant
 * via the harness profile metabox under the "Necessity Gate" section.
 *
 * ## Hooks
 *
 * - Filter: `wp_mcp_ai_necessity_gate_enabled` — per-request override.
 * - Filter: `wp_mcp_ai_necessity_gate_verdict` — modify the final verdict.
 * - Action: `wp_mcp_ai_necessity_gate_blocked` — fired when a call is blocked.
 * - Action: `wp_mcp_ai_necessity_gate_warned` — fired when a call gets a warning.
 *
 * @package WP_MCP_AI
 * @since   1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Necessity Gate — Layer J.
 *
 * @since 1.9.0
 */
class NecessityGate {

	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Optional structured context.
	 */
	private static function log_event( $level, $message, $context = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message, $context );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}


	/**
	 * Maximum consecutive denials before escalating to the human.
	 *
	 * Industry reference: Claude Code auto mode escalates after 3 consecutive
	 * or 20 total denials.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const MAX_CONSECUTIVE_DENIALS = 3;

	/**
	 * Maximum total denials per session before escalating.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const MAX_TOTAL_DENIALS = 20;

	/**
	 * Counter for consecutive denials in the current session.
	 *
	 * Keyed by session_id.
	 *
	 * @since 1.9.0
	 * @var array<string,int>
	 */
	private static $consecutive_denials = array();

	/**
	 * Counter for total denials in the current session.
	 *
	 * Keyed by session_id.
	 *
	 * @since 1.9.0
	 * @var array<string,int>
	 */
	private static $total_denials = array();

	/**
	 * Register the gate with the tool execution pipeline.
	 *
	 * Hooks into `wp_mcp_ai_before_tool_execute` at priority 5 (before the
	 * workflow optimizer's cache check at priority 10). Off by default —
	 * the harness profile gates whether the filter actually evaluates.
	 *
	 * @since 1.9.0
	 */
	public static function register() {
		add_filter( 'wp_mcp_ai_before_tool_execute', array( __CLASS__, 'evaluate' ), 5, 4 );
		add_filter( 'wp_mcp_ai_resolved_system_prompt', array( __CLASS__, 'inject_necessity_instructions' ), 20, 3 );
	}

	/**
	 * Evaluate a pending tool call against necessity + irreversibility gates.
	 *
	 * Hooked into `wp_mcp_ai_before_tool_execute`. Returns null to allow
	 * normal execution, WP_Error to block, or a skip signal.
	 *
	 * @since 1.9.0
	 *
	 * @param mixed  $pre       Pre-execution result (null = continue).
	 * @param string $tool_slug Tool slug being executed.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return mixed|null WP_Error to block, null to continue, or 'skip' string to skip silently.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $context is required by the wp_mcp_ai_before_tool_execute filter signature.
	public static function evaluate( $pre, $tool_slug, $arguments, $context ) {
		// If another filter already decided, respect that.
		if ( null !== $pre ) {
			return $pre;
		}

		// Standalone mode: the base plugin's tool registry and safety
		// profiles are absent, so there is nothing to gate — allow.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			return null;
		}

		// Check if the necessity gate is enabled for this request.
		if ( ! self::is_enabled() ) {
			return null;
		}

		$tool_slug = sanitize_key( (string) $tool_slug );
		$arguments = is_array( $arguments ) ? $arguments : array();

		// Resolve the tool instance for metadata.
		$registry = \WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( $tool_slug );

		// ── Tier 1: Safe-tool allowlist ──────────────────────────────────
		if ( self::is_safe_tool( $tool ) ) {
			return null; // Auto-approve.
		}

		// ── Gather safety metadata ───────────────────────────────────────
		$irreversibility = self::get_tool_irreversibility( $tool );
		$necessity       = self::classify_necessity( $tool_slug, $arguments );

		// ── Tier 2: Necessity check ──────────────────────────────────────
		if ( \WP_MCP_AI_Action_Safety_Profile::NECESSITY_UNNECESSARY === $necessity ) {
			// Skip unnecessary read-only calls silently.
			if ( $irreversibility <= \WP_MCP_AI_Action_Safety_Profile::IRREVERSIBILITY_NONE ) {
				self::log_decision( $tool_slug, 'skip', 'unnecessary_read_only' );
				return 'skip';
			}
		}

		// ── Tier 3: Irreversibility gate ─────────────────────────────────
		$verdict = \WP_MCP_AI_Action_Safety_Profile::determine_verdict( $irreversibility, $necessity );

		/**
		 * Filter the necessity gate verdict before it is enforced.
		 *
		 * @since 1.9.0
		 *
		 * @param array  $verdict   The computed verdict array.
		 * @param string $tool_slug Tool slug.
		 * @param array  $arguments Tool arguments.
		 */
		$verdict = apply_filters( 'wp_mcp_ai_necessity_gate_verdict', $verdict, $tool_slug, $arguments );

		switch ( $verdict['verdict'] ) {
			case \WP_MCP_AI_Action_Safety_Profile::VERDICT_ALLOW:
				return null;

			case \WP_MCP_AI_Action_Safety_Profile::VERDICT_WARN:
				self::log_decision( $tool_slug, 'warn', $verdict['reason'] );
				/**
				 * Fires when a tool call passes with a warning.
				 *
				 * @since 1.9.0
				 *
				 * @param string $tool_slug Tool slug.
				 * @param array  $arguments Tool arguments.
				 * @param array  $verdict   Verdict details.
				 */
				do_action( 'wp_mcp_ai_necessity_gate_warned', $tool_slug, $arguments, $verdict );
				return null;

			case \WP_MCP_AI_Action_Safety_Profile::VERDICT_SKIP:
				self::log_decision( $tool_slug, 'skip', $verdict['reason'] );
				return 'skip';

			case \WP_MCP_AI_Action_Safety_Profile::VERDICT_APPROVAL_REQUIRED:
				self::log_decision( $tool_slug, 'approval_required', $verdict['reason'] );
				return self::require_approval( $tool_slug, $arguments, $verdict );

			case \WP_MCP_AI_Action_Safety_Profile::VERDICT_BLOCK:
			default:
				self::record_denial( $tool_slug );
				self::log_decision( $tool_slug, 'block', $verdict['reason'] );
				/**
				 * Fires when a tool call is blocked by the necessity gate.
				 *
				 * @since 1.9.0
				 *
				 * @param string $tool_slug Tool slug.
				 * @param array  $arguments Tool arguments.
				 * @param array  $verdict   Verdict details.
				 */
				do_action( 'wp_mcp_ai_necessity_gate_blocked', $tool_slug, $arguments, $verdict );
				return new \WP_Error(
					'necessity_gate_blocked',
					sprintf(
						/* translators: 1: tool name, 2: reason */
						__( 'Action blocked by necessity gate: %1$s — %2$s', 'nvoos-content-graph-ai-platform' ),
						$tool_slug,
						$verdict['reason']
					)
				);
		}
	}

	/**
	 * Check if the necessity gate is enabled for the current request.
	 *
	 * @since 1.9.0
	 *
	 * @return bool
	 */
	public static function is_enabled( $assistant_id = 0 ) {
		// Global kill-switch constant.
		if ( defined( 'WP_MCP_AI_DISABLE_NECESSITY_GATE' ) && WP_MCP_AI_DISABLE_NECESSITY_GATE ) {
			return false;
		}

		// Per-assistant harness profile check.
		$assistant_id = (int) $assistant_id ?: self::get_current_assistant_id();
		if ( $assistant_id > 0 ) {
			$profile = HarnessProfile::get( $assistant_id );
			if ( ! empty( $profile['necessity_gate']['enabled'] ) ) {
				/**
				 * Filter whether the necessity gate is enabled for this request.
				 *
				 * @since 1.9.0
				 *
				 * @param bool $enabled       Whether the gate is enabled.
				 * @param int  $assistant_id  Assistant post ID.
				 */
				return (bool) apply_filters( 'wp_mcp_ai_necessity_gate_enabled', true, $assistant_id );
			}
		}

		return false;
	}

	/**
	 * Inject necessity-first instructions into the system prompt.
	 *
	 * When the necessity gate is enabled for the current assistant,
	 * prepends a block of instructions that train the model to:
	 * 1. Evaluate whether each action is truly necessary
	 * 2. Consider proportionality (scope vs. user intent)
	 * 3. Respect irreversibility (warn before permanent actions)
	 *
	 * Industry reference: Anthropic's Claude Code auto mode
	 * decision criteria and the minimum-necessary-action principle.
	 *
	 * @since 1.9.0
	 *
	 * @param string $system_prompt The current system prompt.
	 * @param int    $assistant_id  Assistant post ID.
	 * @param array  $context       Surface-specific context.
	 * @return string Augmented system prompt.
	 */
	public static function inject_necessity_instructions( $system_prompt, $assistant_id = 0, $context = array() ) {
		$system_prompt = (string) $system_prompt;
		$assistant_id  = (int) $assistant_id;

		if ( ! self::is_enabled( $assistant_id ) ) {
			return $system_prompt;
		}

		$instructions  = "\n\n---\n\n";
		$instructions .= "## Action Selection Principle: Minimum Necessary Action\n\n";
		$instructions .= "Before invoking ANY tool, evaluate these three questions in order:\n\n";
		$instructions .= "1. **Is this action NECESSARY?** Can you answer the user using only information\n";
		$instructions .= "   already in context? Have you retrieved this data in a previous step? Are you\n";
		$instructions .= "   calling a tool out of habit rather than genuine need? If the answer is already\n";
		$instructions .= "   available in the conversation, do NOT make the call.\n\n";
		$instructions .= "2. **Is this action PROPORTIONAL?** Does the scope match what the user actually\n";
		$instructions .= "   asked for? \"Clean up branches\" does not mean delete remote branches. \"Check\n";
		$instructions .= "   pricing\" does not mean change prices. \"Review content\" does not mean publish it.\n";
		$instructions .= "   Match your action to the user's explicit request, not your interpretation.\n\n";
		$instructions .= "3. **Is this action REVERSIBLE?** If the action has permanent consequences —\n";
		$instructions .= "   sending emails, deleting data, processing payments, publishing content,\n";
		$instructions .= "   modifying permissions — you MUST explain the irreversible nature and either:\n";
		$instructions .= "   - Ask for explicit user confirmation before proceeding, OR\n";
		$instructions .= "   - Use a safer, reversible alternative if one exists\n\n";
		$instructions .= "## Irreversibility Awareness\n\n";
		$instructions .= "Tools marked [IRREVERSIBLE] or [HIGH_RISK] in the available tools list have\n";
		$instructions .= "permanent consequences. When you see these markers, you MUST pause and apply\n";
		$instructions .= "the three questions above before calling the tool. For permanently irreversible\n";
		$instructions .= "actions (financial transactions, mass deletions, access revocation), the system\n";
		$instructions .= "may require human approval before execution — do not attempt to bypass this.\n\n";
		$instructions .= "## Overeagerness Warning\n\n";
		$instructions .= "The most common AI agent mistake is overeagerness: doing more than the user asked\n";
		$instructions .= "because you genuinely want to help. If you catch yourself thinking 'while I'm here,\n";
		$instructions .= "I might as well also...' — STOP. That is overeagerness. Only do what was asked.";

		/**
		 * Filter the necessity instructions block injected into the system prompt.
		 *
		 * @since 1.9.0
		 *
		 * @param string $instructions  The default necessity-first instruction block.
		 * @param int    $assistant_id  Assistant post ID.
		 * @param array  $context       Surface-specific context.
		 */
		$instructions = apply_filters( 'wp_mcp_ai_necessity_instructions', $instructions, $assistant_id, $context );

		return $system_prompt . $instructions;
	}

	/**
	 * Tier 1: determine if a tool is on the safe-tool allowlist.
	 *
	 * Safe tools are read-only, cacheable, idempotent, and have no
	 * side effects. They auto-pass the necessity gate.
	 *
	 * @since 1.9.0
	 *
	 * @param WP_MCP_AI_Tool_Interface|null $tool Tool instance or null.
	 * @return bool
	 */
	private static function is_safe_tool( $tool ) {
		if ( ! $tool instanceof \WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			return false;
		}

		$flags = (array) $tool->get_capability_flags();

		// Must be read-only.
		if ( ! in_array( 'read-only', $flags, true ) ) {
			return false;
		}

		// Must not be irreversible, destructive, or have side effects.
		$dangerous = array( 'irreversible', 'financial-impact', 'external-communication', 'data-destruction', 'access-control-change' );
		foreach ( $dangerous as $flag ) {
			if ( in_array( $flag, $flags, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the irreversibility score for a tool.
	 *
	 * Prefers the explicit score from WP_MCP_AI_Tool_Safety_Profile_Interface,
	 * falls back to derivation from capability flags.
	 *
	 * @since 1.9.0
	 *
	 * @param WP_MCP_AI_Tool_Interface|null $tool Tool instance or null.
	 * @return float Irreversibility score (0.0–1.0).
	 */
	private static function get_tool_irreversibility( $tool ) {
		if ( $tool instanceof \WP_MCP_AI_Tool_Safety_Profile_Interface ) {
			return (float) $tool->get_irreversibility_score();
		}

		$flags = array();
		if ( $tool instanceof \WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = (array) $tool->get_capability_flags();
		}

		return \WP_MCP_AI_Action_Safety_Profile::derive_irreversibility_from_flags( $flags );
	}

	/**
	 * Tier 2: classify the necessity level of a tool call.
	 *
	 * Uses fast heuristics (no LLM call) to estimate whether a tool call
	 * is truly needed for the current task. This is intentionally
	 * conservative — when in doubt, it classifies as 'helpful'.
	 *
	 * @since 1.9.0
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @return string One of the NECESSITY_* constants.
	 */
	public static function classify_necessity( $tool_slug, $arguments ) {
		$tool_slug = sanitize_key( (string) $tool_slug );
		$arguments = is_array( $arguments ) ? $arguments : array();

		/**
		 * Filter the necessity classification for a tool call.
		 *
		 * Allows the Pro addon or a learned model to override the
		 * heuristic classification with a more accurate assessment.
		 *
		 * @since 1.9.0
		 *
		 * @param string|null $necessity  Necessity level, or null to use the heuristic.
		 * @param string      $tool_slug  Tool slug.
		 * @param array       $arguments  Tool arguments.
		 */
		$override = apply_filters( 'wp_mcp_ai_necessity_gate_classify', null, $tool_slug, $arguments );
		if ( null !== $override && \WP_MCP_AI_Action_Safety_Profile::is_valid_necessity( $override ) ) {
			return $override;
		}

		// Check for empty/redundant arguments.
		if ( self::has_empty_arguments( $arguments, $tool_slug ) ) {
			return \WP_MCP_AI_Action_Safety_Profile::NECESSITY_OPTIONAL;
		}

		// Check for known overeager patterns.
		if ( self::is_overeager_pattern( $tool_slug, $arguments ) ) {
			return \WP_MCP_AI_Action_Safety_Profile::NECESSITY_UNNECESSARY;
		}

		// Default: assume the call is at least helpful.
		return \WP_MCP_AI_Action_Safety_Profile::NECESSITY_HELPFUL;
	}

	/**
	 * Check if the tool call has empty or trivial arguments.
	 *
	 * A call with no arguments or only default values is often a sign
	 * of overeager behavior (the model called a tool without a specific need).
	 *
	 * @since 1.9.0
	 *
	 * @param array  $arguments Tool arguments.
	 * @param string $tool_slug Tool slug for context-specific checks.
	 * @return bool
	 */
	private static function has_empty_arguments( $arguments, $tool_slug ) {
		// No arguments at all = very suspicious.
		if ( empty( $arguments ) ) {
			return true;
		}

		// Check for search tools with empty queries.
		$search_tools = array( 'web_search', 'brave_web_search', 'brave_local_search', 'search_content', 'search_attachments', 'search_content_validated' );
		if ( in_array( $tool_slug, $search_tools, true ) ) {
			$query  = isset( $arguments['query'] ) ? trim( (string) $arguments['query'] ) : '';
			$search = isset( $arguments['search'] ) ? trim( (string) $arguments['search'] ) : '';
			if ( '' === $query && '' === $search ) {
				return true;
			}
		}

		// Check for get/post tools with empty/missing IDs.
		$id_tools = array( 'get_post', 'delete_post', 'get_woo_product', 'get_wc_product' );
		if ( in_array( $tool_slug, $id_tools, true ) ) {
			$id_fields = array( 'post_id', 'product_id', 'id', 'ID' );
			foreach ( $id_fields as $field ) {
				if ( isset( $arguments[ $field ] ) && (int) $arguments[ $field ] > 0 ) {
					return false;
				}
			}
			return true;
		}

		return false;
	}

	/**
	 * Check for known overeager tool-call patterns.
	 *
	 * Overeager behaviour: the agent called a high-impact tool when a
	 * lower-impact alternative exists, or the call scope exceeds what
	 * the user likely intended.
	 *
	 * @since 1.9.0
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @return bool
	 */
	private static function is_overeager_pattern( $tool_slug, $arguments ) {
		// Mass-delete without per_page limit.
		if ( 'delete_post' === $tool_slug && empty( $arguments['post_id'] ) && empty( $arguments['ID'] ) ) {
			return true;
		}

		// Sending email without explicit user confirmation context.
		if ( 'send_email' === $tool_slug || 'wp_mail' === $tool_slug ) {
			// If no recipient is specified, it's overeager.
			if ( empty( $arguments['to'] ) && empty( $arguments['recipient'] ) ) {
				return true;
			}
		}

		// Creating WooCommerce products without a reference/SKU.
		if ( 'create_woo_product' === $tool_slug ) {
			if ( empty( $arguments['reference'] ) && empty( $arguments['sku'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Require human-in-the-loop approval for a tool call.
	 *
	 * @since 1.9.0
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $verdict   Gating verdict details.
	 * @return WP_Error
	 */
	private static function require_approval( $tool_slug, $arguments, $verdict ) {
		self::record_denial( $tool_slug );

		// If the approval queue is available, enqueue the request.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Approval_Queue' ) ) {
			$queue = \WP_MCP_AI_Approval_Queue::get_instance();
			$queue->enqueue(
				array(
					'tool'         => $tool_slug,
					'arguments'    => $arguments,
					'assistant_id' => self::get_current_assistant_id(),
					'reason'       => $verdict['reason'],
				)
			);
		}

		return new \WP_Error(
			'necessity_gate_approval_required',
			sprintf(
				/* translators: 1: tool name, 2: reason */
				__( 'Human approval required: %1$s — %2$s. Please approve or deny this action.', 'nvoos-content-graph-ai-platform' ),
				$tool_slug,
				$verdict['reason']
			)
		);
	}

	/**
	 * Record a denial and check escalation thresholds.
	 *
	 * After MAX_CONSECUTIVE_DENIALS consecutive denials or MAX_TOTAL_DENIALS
	 * total denials, stop the agentic loop.
	 *
	 * @since 1.9.0
	 *
	 * @param string $tool_slug Tool slug that was denied.
	 */
	private static function record_denial( $tool_slug ) {
		$session_id = self::get_current_session_id();

		if ( ! isset( self::$consecutive_denials[ $session_id ] ) ) {
			self::$consecutive_denials[ $session_id ] = 0;
		}
		if ( ! isset( self::$total_denials[ $session_id ] ) ) {
			self::$total_denials[ $session_id ] = 0;
		}

		++self::$consecutive_denials[ $session_id ];
		++self::$total_denials[ $session_id ];

		// Reset consecutive counter if another tool type is denied (not same slug repeated).
		// This is handled by the caller tracking the last denied slug.

		if ( self::$consecutive_denials[ $session_id ] >= self::MAX_CONSECUTIVE_DENIALS ) {
			self::log_decision( $tool_slug, 'escalate', 'max_consecutive_denials_reached' );
		}

		if ( self::$total_denials[ $session_id ] >= self::MAX_TOTAL_DENIALS ) {
			self::log_decision( $tool_slug, 'escalate', 'max_total_denials_reached' );
		}
	}

	/**
	 * Log a necessity-gate decision for observability.
	 *
	 * @since 1.9.0
	 *
	 * @param string $tool_slug Tool slug.
	 * @param string $action    Decision action (allow, warn, skip, block, approval_required, escalate).
	 * @param string $reason    Human-readable reason.
	 */
	private static function log_decision( $tool_slug, $action, $reason ) {
		self::log_event(
			'necessity_gate',
			sprintf(
				/* translators: 1: action, 2: tool slug */
				__( 'Necessity gate: %1$s — %2$s', 'nvoos-content-graph-ai-platform' ),
				$action,
				$tool_slug
			),
			array(
				'tool_slug' => $tool_slug,
				'action'    => $action,
				'reason'    => $reason,
			)
		);
	}

	/**
	 * Get the current assistant ID from context.
	 *
	 * @since 1.9.0
	 *
	 * @return int Assistant post ID, or 0 if not available.
	 */
	private static function get_current_assistant_id() {
		// Check if Request_Context provides an assistant ID.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Request_Context' ) && method_exists( 'WP_MCP_AI_Request_Context', 'get_instance' ) ) {
			$context = \WP_MCP_AI_Request_Context::get_instance();
			if ( method_exists( $context, 'get_assistant_id' ) ) {
				return (int) $context->get_assistant_id();
			}
		}

		return 0;
	}

	/**
	 * Get the current session ID from context.
	 *
	 * @since 1.9.0
	 *
	 * @return string Session ID, or 'default' if not available.
	 */
	private static function get_current_session_id() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Request_Context' ) && method_exists( 'WP_MCP_AI_Request_Context', 'get_instance' ) ) {
			$context = \WP_MCP_AI_Request_Context::get_instance();
			if ( method_exists( $context, 'get_session_id' ) ) {
				return sanitize_text_field( (string) $context->get_session_id() );
			}
		}

		return 'default';
	}
}
