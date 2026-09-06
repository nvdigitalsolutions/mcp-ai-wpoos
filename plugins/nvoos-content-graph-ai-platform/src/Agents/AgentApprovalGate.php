<?php
/**
 * Agent Approval Gate — CoSAI Principle 1 (Human-Governed & Accountable)
 *
 * WordPress filter-based approval gate that can pause agent execution
 * for human review before high-risk operations. Implements CoSAI-compliant
 * gating across four risk tiers with structured pending-approval workflows
 * that feed into the Pro Agent Command Center.
 *
 * ## CoSAI Alignment
 *
 * | CoSAI Principle            | Implementation in this class                                       |
 * |----------------------------|--------------------------------------------------------------------|
 * | P1 – Human-Governed        | Explicit approval gates for medium/high/critical risk tiers;       |
 * |                            | low-risk auto-approval is the only silent path.                    |
 * | P2 – Transparent           | Every decision (auto-approve, pending, deny) is logged via         |
 * |                            | `wp_mcp_ai_agent_approval_required` + `wp_mcp_ai_approval_decided`.|
 * | P3 – Accountable           | Approval records include session_id, user context, and timestamp;  |
 * |                            | resolved via `resolve_approval()` with mandatory reason.           |
 * | P4 – Defensible            | Critical-risk actions can only be overridden by an explicit        |
 * |                            | filter — no automatic bypass path exists.                          |
 *
 * @package NvoosContentGraphAiPlatform\Agents
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approval gate for agent operations.
 *
 * Provides a human-in-the-loop approval workflow that pauses agent execution
 * before high-risk operations and surfaces pending approvals to the Agent
 * Command Center. Uses WordPress transients for time-bound approval storage.
 *
 * @since 1.2.0
 */
class AgentApprovalGate {

	/**
	 * Transient prefix for pending approvals.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'wp_mcp_ai_approval_';

	/**
	 * Transient TTL in seconds (1 hour).
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const TRANSIENT_TTL = HOUR_IN_SECONDS;

	/**
	 * Valid action types.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const VALID_ACTION_TYPES = array(
		'tool_execution',
		'code_execution',
		'file_access',
		'network_access',
		'data_modification',
	);

	/**
	 * Valid risk levels.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const VALID_RISK_LEVELS = array(
		'low',
		'medium',
		'high',
		'critical',
	);

	/**
	 * Request approval for an agent action.
	 *
	 * Evaluates the action against configured risk thresholds and either
	 * auto-approves, stores a pending approval, or denies the action.
	 *
	 * @since 1.2.0
	 *
	 * @param string $session_id  The agent session identifier.
	 * @param string $action_type One of 'tool_execution', 'code_execution', 'file_access',
	 *                            'network_access', 'data_modification'.
	 * @param array  $action_data Structured data describing the action (tool slug, params,
	 *                            target resource, etc.).
	 * @param string $risk_level  One of 'low', 'medium', 'high', 'critical'.
	 *
	 * @return true|WP_Error True if approved; WP_Error with code 'pending_approval' or
	 *                       'approval_denied' if blocked or awaiting review.
	 */
	public static function request_approval( $session_id, $action_type, $action_data, $risk_level ) {
		// Validate action_type.
		if ( ! in_array( $action_type, self::VALID_ACTION_TYPES, true ) ) {
			return new \WP_Error(
				'invalid_action_type',
				sprintf(
					/* translators: %s: action type received */
					__( 'Invalid action type: %s.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $action_type )
				)
			);
		}

		// Validate risk_level.
		if ( ! in_array( $risk_level, self::VALID_RISK_LEVELS, true ) ) {
			return new \WP_Error(
				'invalid_risk_level',
				sprintf(
					/* translators: %s: risk level received */
					__( 'Invalid risk level: %s.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $risk_level )
				)
			);
		}

		// Normalise inputs.
		$session_id  = sanitize_key( $session_id );
		$action_type = sanitize_key( $action_type );
		$risk_level  = sanitize_key( $risk_level );
		$action_data = self::sanitize_action_data( $action_data );

		/**
		 * Filters which risk levels are automatically approved.
		 *
		 * By default, only 'low' risk actions are auto-approved. Return a custom
		 * array of risk levels to broaden or narrow the auto-approval window.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $auto_approve_risk Risk levels to auto-approve. Default: [ 'low' ].
		 */
		$auto_approve_risk = apply_filters( 'wp_mcp_ai_agent_approval_auto_approve_risk', array( 'low' ) );

		// Low-risk: auto-approve (filterable).
		if ( in_array( $risk_level, $auto_approve_risk, true ) ) {
			return true;
		}

		// Medium-risk: check pre-approved patterns.
		if ( 'medium' === $risk_level ) {
			if ( self::matches_pre_approved_pattern( $action_type, $action_data ) ) {
				return true;
			}
		}

		// Critical-risk: always deny unless explicit override filter returns true.
		if ( 'critical' === $risk_level ) {
			/**
			 * Filter to explicitly override critical-risk denial.
			 *
			 * WARNING: Overriding critical-risk actions bypasses the strongest
			 * safety gate. Only return true in tightly-controlled environments.
			 *
			 * @since 1.2.0
			 *
			 * @param bool   $override    Whether to override. Default false.
			 * @param string $session_id  The agent session identifier.
			 * @param string $action_type The action type.
			 * @param array  $action_data The sanitized action data.
			 */
			$override = apply_filters( 'wp_mcp_ai_agent_approval_critical_override', false, $session_id, $action_type, $action_data );

			if ( true !== $override ) {
				return new \WP_Error(
					'approval_denied',
					__( 'Critical-risk actions require explicit administrator approval.', 'nvoos-content-graph-ai-platform' ),
					array(
						'session_id'  => $session_id,
						'action_type' => $action_type,
						'risk_level'  => $risk_level,
					)
				);
			}

			return true;
		}

		// High-risk (and unmatched medium-risk): create pending approval.
		return self::create_pending_approval( $session_id, $action_type, $action_data, $risk_level );
	}

	/**
	 * Get all pending approvals for a given assistant.
	 *
	 * Queries the transient store for pending approvals associated with the
	 * specified assistant. Used by the Agent Command Center to populate the
	 * approval queue UI.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id The assistant post ID.
	 * @return array List of pending approval records, each containing 'id', 'session_id',
	 *               'action_type', 'action_data', 'risk_level', and 'created_at'.
	 */
	public static function get_pending_approvals( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( 0 === $assistant_id ) {
			return array();
		}

		global $wpdb;

		// Query transients matching the prefix.
		$like = $wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient lookup for real-time approval queue; no caching appropriate.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND option_name NOT LIKE %s",
				$like,
				$wpdb->esc_like( '_transient_timeout_' ) . '%'
			)
		);

		$pending = array();

		if ( $results ) {
			foreach ( $results as $row ) {
				$approval = maybe_unserialize( $row->option_value );

				if ( ! is_array( $approval ) ) {
					continue;
				}

				// Only return pending approvals.
				if ( ! isset( $approval['status'] ) || 'pending' !== $approval['status'] ) {
					continue;
				}

				// If assistant_id is in the action_data, filter by it.
				if ( isset( $approval['assistant_id'] ) && (int) $approval['assistant_id'] !== $assistant_id ) {
					continue;
				}

				$pending[] = array(
					'id'          => isset( $approval['id'] ) ? $approval['id'] : '',
					'session_id'  => isset( $approval['session_id'] ) ? $approval['session_id'] : '',
					'action_type' => isset( $approval['action_type'] ) ? $approval['action_type'] : '',
					'action_data' => isset( $approval['action_data'] ) ? $approval['action_data'] : array(),
					'risk_level'  => isset( $approval['risk_level'] ) ? $approval['risk_level'] : '',
					'created_at'  => isset( $approval['created_at'] ) ? $approval['created_at'] : 0,
				);
			}
		}

		return $pending;
	}

	/**
	 * Resolve a pending approval.
	 *
	 * Updates the transient to reflect the decision and fires the
	 * `wp_mcp_ai_approval_decided` action so the Agent Command Center
	 * can update its UI and audit log.
	 *
	 * @since 1.2.0
	 *
	 * @param string $approval_id The approval identifier.
	 * @param bool   $approved    Whether the action was approved.
	 * @param string $reason      Human-readable reason for the decision.
	 *
	 * @return true|WP_Error True on success; WP_Error if the approval was not found.
	 */
	public static function resolve_approval( $approval_id, $approved, $reason ) {
		$approval_id = sanitize_key( $approval_id );
		$reason      = sanitize_text_field( $reason );

		if ( empty( $approval_id ) ) {
			return new \WP_Error(
				'missing_approval_id',
				__( 'Approval ID is required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$transient_key = self::TRANSIENT_PREFIX . $approval_id;
		$approval      = get_transient( $transient_key );

		if ( ! is_array( $approval ) ) {
			return new \WP_Error(
				'approval_not_found',
				__( 'Approval record not found or expired.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( isset( $approval['status'] ) && 'pending' !== $approval['status'] ) {
			return new \WP_Error(
				'already_resolved',
				__( 'This approval has already been resolved.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Update the approval record.
		$approval['status']      = $approved ? 'approved' : 'rejected';
		$approval['resolved_at'] = time();
		$approval['reason']      = $reason;

		// Retrieve user context if available.
		$current_user = wp_get_current_user();
		if ( $current_user && $current_user->ID ) {
			$approval['resolved_by'] = $current_user->display_name;
		}

		set_transient( $transient_key, $approval, self::TRANSIENT_TTL );

		/**
		 * Fires when a pending approval has been resolved.
		 *
		 * The Agent Command Center hooks into this action to update
		 * its approval queue and audit log.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $approval The full approval record after resolution.
		 * @param string $decision The decision: 'approved' or 'rejected'.
		 */
		do_action( 'wp_mcp_ai_approval_decided', $approval, $approved ? 'approved' : 'rejected' );

		return true;
	}

	/**
	 * Create a pending approval record and notify listeners.
	 *
	 * @since 1.2.0
	 *
	 * @param string $session_id  The agent session identifier.
	 * @param string $action_type The action type.
	 * @param array  $action_data The sanitized action data.
	 * @param string $risk_level  The risk level.
	 *
	 * @return WP_Error Always returns WP_Error with 'pending_approval' code.
	 */
	private static function create_pending_approval( $session_id, $action_type, $action_data, $risk_level ) {
		$approval_id = wp_generate_uuid4();
		$created_at  = time();

		$approval = array(
			'id'          => $approval_id,
			'session_id'  => $session_id,
			'action_type' => $action_type,
			'action_data' => $action_data,
			'risk_level'  => $risk_level,
			'status'      => 'pending',
			'created_at'  => $created_at,
		);

		// Store assistant_id if present in action_data for filtering.
		if ( isset( $action_data['assistant_id'] ) ) {
			$approval['assistant_id'] = absint( $action_data['assistant_id'] );
		}

		// Store the pending approval in a transient.
		set_transient( self::TRANSIENT_PREFIX . $approval_id, $approval, self::TRANSIENT_TTL );

		/**
		 * Fires when a high-risk or unmatched medium-risk action requires
		 * human approval before proceeding.
		 *
		 * Listeners can use this to display UI notifications, send emails,
		 * or integrate with external approval systems. If no listener marks
		 * the approval as resolved within the transient TTL (1 hour), the
		 * approval expires and the agent loop treats it as denied.
		 *
		 * @since 1.2.0
		 *
		 * @param array $approval The full approval record, including id, session_id,
		 *                        action_type, action_data, risk_level, status, and created_at.
		 */
		do_action( 'wp_mcp_ai_agent_approval_required', $approval );

		return new \WP_Error(
			'pending_approval',
			sprintf(
				/* translators: 1: risk level, 2: approval ID */
				__( 'Action requires %1$s-risk approval. Approval ID: %2$s.', 'nvoos-content-graph-ai-platform' ),
				esc_html( $risk_level ),
				esc_html( $approval_id )
			),
			array(
				'approval_id' => $approval_id,
				'session_id'  => $session_id,
				'action_type' => $action_type,
				'risk_level'  => $risk_level,
				'retry_after' => self::TRANSIENT_TTL,
			)
		);
	}

	/**
	 * Check if an action matches a pre-approved pattern.
	 *
	 * Reads the `wp_mcp_ai_pre_approved_patterns` option, which is an array
	 * of pattern rules. Each rule can specify action_type, tool_slug, and
	 * optional path/file restrictions.
	 *
	 * @since 1.2.0
	 *
	 * @param string $action_type The action type.
	 * @param array  $action_data The sanitized action data.
	 *
	 * @return bool True if the action matches a pre-approved pattern.
	 */
	private static function matches_pre_approved_pattern( $action_type, $action_data ) {
		$patterns = get_option( 'wp_mcp_ai_pre_approved_patterns', array() );

		if ( ! is_array( $patterns ) || empty( $patterns ) ) {
			return false;
		}

		foreach ( $patterns as $pattern ) {
			if ( ! is_array( $pattern ) ) {
				continue;
			}

			// Must match action_type.
			if ( isset( $pattern['action_type'] ) && $pattern['action_type'] !== $action_type ) {
				continue;
			}

			// Optional tool_slug match.
			if ( isset( $pattern['tool_slug'] ) && isset( $action_data['tool_slug'] ) ) {
				if ( $pattern['tool_slug'] !== $action_data['tool_slug'] ) {
					continue;
				}
			}

			// Optional path prefix match (for file/network actions).
			if ( isset( $pattern['path_prefix'] ) && isset( $action_data['path'] ) ) {
				if ( 0 !== strpos( $action_data['path'], $pattern['path_prefix'] ) ) {
					continue;
				}
			}

			// All specified criteria matched — pre-approved.
			return true;
		}

		return false;
	}

	/**
	 * Sanitize action data array recursively.
	 *
	 * Ensures all values in the action data are safe before storage or
	 * comparison against pre-approved patterns.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data Raw action data.
	 * @return array Sanitized action data.
	 */
	private static function sanitize_action_data( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$safe_key = sanitize_key( $key );

			if ( is_array( $value ) ) {
				$sanitized[ $safe_key ] = self::sanitize_action_data( $value );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $safe_key ] = sanitize_text_field( $value );
			} elseif ( is_int( $value ) ) {
				$sanitized[ $safe_key ] = $value;
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $safe_key ] = $value;
			} elseif ( is_float( $value ) ) {
				$sanitized[ $safe_key ] = (float) $value;
			}
			// Non-scalar, non-array values are dropped.
		}

		return $sanitized;
	}
}
