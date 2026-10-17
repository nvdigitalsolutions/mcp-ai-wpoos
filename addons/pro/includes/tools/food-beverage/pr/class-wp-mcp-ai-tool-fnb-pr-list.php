<?php
/**
 * F&B tool: fnb_pr_list — list purchase requests.
 *
 * READ tier: the register of purchase-request records, optionally filtered
 * by lifecycle status or open-only.
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
 * List purchase requests.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Pr_List extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_pr_list';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Purchase Request: List', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List purchase requests (newest first) with status, total, supplier and approver. Filter by lifecycle status or open-only (draft / in_review / approved).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Reviewing the purchase-request register or finding a record ID before another fnb_pr_* action.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Reading the per-record audit history — use fnb_pr_audit.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fnb_pr_create', 'fnb_pr_audit' ),
			'notes'           => __( 'Open-only excludes released, superseded and obsolete records.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'status' => array(
					'type'        => 'string',
					'enum'        => array( 'draft', 'in_review', 'approved', 'released', 'superseded', 'obsolete' ),
					'description' => __( 'Optional lifecycle-status filter.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'  => array(
					'type'        => 'integer',
					'default'     => 50,
					'description' => __( 'Maximum records (default 50, cap 100).', 'mcp-ai-wpoos-pro' ),
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

		$status = isset( $arguments['status'] ) ? sanitize_key( (string) $arguments['status'] ) : '';
		$limit  = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;

		$records = WP_MCP_AI_Fnb_Pr_Service::list_prs(
			array(
				'status' => $status,
				'limit'  => $limit,
			)
		);

		return array(
			'success' => true,
			'status'  => '' !== $status ? $status : 'all',
			'count'   => count( $records ),
			'records' => $records,
		);
	}
}
