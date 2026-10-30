<?php
/**
 * F&B tool: fnb_pr_audit — read the purchase-request audit trail.
 *
 * READ tier: the CEO-facing immutable trail of every purchase-request
 * transition (before/after state, actor, hashes, reason).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../class-wp-mcp-ai-fnb-tool-base.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-pr-service.php';

/**
 * Read the purchase-request audit trail.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Pr_Audit extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_pr_audit';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Purchase Request: Audit', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read the immutable audit trail of purchase requests — every creation, submission, approval, release, supersession and cancellation with actor, before/after state and hashes. Pass post_id for one record, omit for the whole PR register.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Answering who did what to a purchase request, and when — the CEO control story (open question 8).', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Listing current records — use fnb_pr_list.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fnb_pr_list', 'fnb_audit_log' ),
			'notes'           => __( 'Audit rows are append-only; transitions write them automatically.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'maximum'     => WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER,
					'description' => __( 'Optional purchase-request record ID; omit to list all PR audit rows.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'   => array(
					'type'        => 'integer',
					'default'     => 50,
					'description' => __( 'Maximum entries (default 50, cap 200).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'cacheable' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard();
		if ( $error ) {
			return $error;
		}

		if ( ! WP_MCP_AI_Fnb_Pr_Service::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_fnb_pr_no_qms', __( 'The QMS engine is not available — enable the Document Generation toolkit with QMS compliance.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$limit   = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;

		$entries = WP_MCP_AI_Fnb_Pr_Service::read_audit( $post_id, $limit );

		return array(
			'success' => true,
			'post_id' => $post_id > 0 ? $post_id : 0,
			'count'   => count( $entries ),
			'entries' => $entries,
		);
	}
}
