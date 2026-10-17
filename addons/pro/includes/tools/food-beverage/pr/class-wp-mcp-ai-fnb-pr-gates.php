<?php
/**
 * Food & Beverage Management Toolkit — Purchase-Request QMS Gates.
 *
 * Layers the F&B purchase-request preconditions (Appendix A5 of Proposal
 * 062) onto the QMS state machine through the engine's extension points —
 * no parallel workflow code:
 *
 * - `wp_mcp_ai_qms_state_preconditions` — blocking checks for the
 *   in_review / approved / released transitions of `purchase-request`
 *   records (lines valid, cut-off timing, duplicate-open scan, budget
 *   line, segregation of duties, content-hash freeze, co-sign threshold).
 * - `wp_mcp_ai_qms_require_release_signature` — relaxed for purchase
 *   requests: the assistant-driven lifecycle enforces its own hash gate
 *   and optional finance co-sign instead of a password e-signature.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-pr-service.php';

/**
 * F&B purchase-request precondition hooks.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Pr_Gates {

	/**
	 * Register the QMS filter hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_mcp_ai_qms_state_preconditions', array( __CLASS__, 'filter_state_preconditions' ), 10, 3 );
		add_filter( 'wp_mcp_ai_qms_require_release_signature', array( __CLASS__, 'relax_release_signature' ), 10, 2 );
	}

	/**
	 * Apply the F&B preconditions to purchase-request transitions.
	 *
	 * @param true|WP_Error $gate     Current gate result.
	 * @param int           $post_id  Record ID.
	 * @param string        $to_state Target state.
	 * @return true|WP_Error
	 */
	public static function filter_state_preconditions( $gate, $post_id, $to_state ) {
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		if ( ! WP_MCP_AI_Fnb_Pr_Service::is_available() ) {
			return $gate;
		}
		if ( ! WP_MCP_AI_Fnb_Pr_Service::is_pr_record( $post_id ) ) {
			return $gate;
		}

		$actor_id = get_current_user_id();

		switch ( $to_state ) {
			case WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW:
				$check = WP_MCP_AI_Fnb_Pr_Service::check_submit_preconditions( $post_id );
				break;
			case WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_APPROVED:
				$check = WP_MCP_AI_Fnb_Pr_Service::check_approve_preconditions( $post_id, $actor_id );
				break;
			case WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED:
				$check = WP_MCP_AI_Fnb_Pr_Service::check_release_preconditions( $post_id, $actor_id );
				break;
			default:
				return $gate;
		}

		return $check['gate'];
	}

	/**
	 * Purchase requests do not require a password e-signature at release.
	 *
	 * The F&B release gate enforces the content-hash freeze and the
	 * finance co-sign threshold instead; a password re-prompt cannot flow
	 * through assistant tool calls.
	 *
	 * @param bool $require_signature Whether the generic engine would require a signature.
	 * @param int  $post_id           Record ID.
	 * @return bool
	 */
	public static function relax_release_signature( $require_signature, $post_id ) {
		if ( ! $require_signature ) {
			return $require_signature;
		}
		if ( ! WP_MCP_AI_Fnb_Pr_Service::is_available() ) {
			return $require;
		}

		return ! WP_MCP_AI_Fnb_Pr_Service::is_pr_record( $post_id );
	}
}
