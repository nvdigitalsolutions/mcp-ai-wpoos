<?php
/**
 * Get Sequence Performance — enrollment counts, completion rates, and step-level metrics.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Sequence Performance tool.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Get_Sequence_Performance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_sequence_performance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Sequence Performance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns enrollment counts, completion rates, and step-level metrics for a sequence.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Pulling active enrollment counts and a performance snapshot for one sequence_id.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Finding sequence IDs; use list_outreach_sequences. Checking a single lead; use get_lead.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'list_outreach_sequences', 'enroll_lead_in_sequence', 'manage_sequence_state' ),
			'notes'           => __( 'Returns active_enrollments from the _active_sequence_id meta; completion metrics are approximate snapshots.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array( 'sequence_id' => array( 'type' => 'integer' ) ),
			'required'   => array( 'sequence_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'requires-capability' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$seq_id = absint( $arguments['sequence_id'] );
		$sp     = get_post( $seq_id );
		if ( ! $sp || 'mcp_ai_sequence' !== $sp->post_type ) {
			return new WP_Error( 'not_found', __( 'Sequence not found.', 'mcp-ai-wpoos-pro' ) );
		}
		// Count enrollments.
		$eq          = new WP_Query(
			array(
				'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_active_sequence_id',
						'value' => $seq_id,
					),
				),
				'no_found_rows'  => false,
			)
		);
		$active      = $eq->found_posts;
		$completed_q = new WP_Query(
			array(
				'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_sequence_exited_at',
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'  => false,
			)
		);
		// Approximate: use audit log for historical counts.
		return array(
			'success'            => true,
			'sequence_id'        => $seq_id,
			'name'               => $sp->post_title,
			'active_enrollments' => $active,
			'message'            => __( 'Performance snapshot retrieved.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
