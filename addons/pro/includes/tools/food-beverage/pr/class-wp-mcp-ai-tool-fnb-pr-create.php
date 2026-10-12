<?php
/**
 * F&B tool: fnb_pr_create — create a purchase request (QMS draft).
 *
 * Produces the R-04 purchase-request layout as a QMS controlled-document
 * record of kind `purchase-request` in `draft` status. DRAFT tier (the demo
 * stops at R-04 with Status: Draft).
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
 * Create a purchase-request draft.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Pr_Create extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_pr_create';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Purchase Request: Create', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create a purchase request (R-04 layout) as a QMS controlled-document draft. Line totals and the request total are recomputed server-side from quantity × unit price — never trusted from the model. Requires supplier_id and at least one line (ingredient_id, pack, qty, unit_price).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Drafting a purchase request that needs approval before anything reaches a supplier (spec: Place supplier order — Off, needs approval).', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Ordering directly, sending anything to a supplier, or advancing a lifecycle; use fnb_pr_submit/approve/release for the next states.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fnb_pr_submit', 'fnb_pr_list', 'fnb_pr_audit', 'fnb_read_table' ),
			'notes'           => __( 'The draft always carries Status: Draft. Include budget_line now to avoid an approval-time block.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'supplier_id' => array(
					'type'        => 'string',
					'description' => __( 'Supplier ID from spec table 4.0 (required at draft, Appendix A4).', 'mcp-ai-wpoos-pro' ),
				),
				'lines'       => array(
					'type'        => 'string',
					'description' => __( 'JSON array of line objects: {"ingredient_id","ingredient","pack","qty","unit_price"}. Totals are recomputed server-side.', 'mcp-ai-wpoos-pro' ),
				),
				'order_by'    => array(
					'type'        => 'string',
					'description' => __( 'Order-by date (Y-m-d) — must respect the supplier cut-off and delivery days (A2-05).', 'mcp-ai-wpoos-pro' ),
				),
				'needed_by'   => array(
					'type'        => 'string',
					'description' => __( 'Needed-by date (Y-m-d).', 'mcp-ai-wpoos-pro' ),
				),
				'budget_line' => array(
					'type'        => 'string',
					'description' => __( 'Budget line from spec table 15.0 — checked at approval time (M-20).', 'mcp-ai-wpoos-pro' ),
				),
				'notes'       => array(
					'type'        => 'string',
					'description' => __( 'Free-text notes stored on the record.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'supplier_id', 'lines' ),
		);
	}

	/**
	 * ACT-tier: only QMS managers may create purchase requests.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'manage_qms';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
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

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$pr = WP_MCP_AI_Fnb_Pr_Service::create_draft( $arguments, $user_id );
		if ( is_wp_error( $pr ) ) {
			return $pr;
		}

		return array(
			'success'     => true,
			'post_id'     => (int) $pr['id'],
			'document_id' => (string) $pr['document_id'],
			'status'      => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_DRAFT,
			'total'       => (float) $pr['total'],
			'record'      => $pr,
			'note'        => __( 'Draft created. Nothing is sent to the supplier — submit for review when ready (fnb_pr_submit).', 'mcp-ai-wpoos-pro' ),
		);
	}
}
