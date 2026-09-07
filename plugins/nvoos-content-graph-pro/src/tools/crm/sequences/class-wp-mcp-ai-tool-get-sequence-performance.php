<?php
/**
 * Get Sequence Performance Tool (ecosystem port — Wave F2, CRM sequences
 * batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/sequences/class-wp-mcp-ai-tool-get-sequence-performance.php`
 * for the standalone `nvoos-content-graph-pro` addon. Kept
 * byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since 2.3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get Sequence Performance tool.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Get_Sequence_Performance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'CRM Toolkit required.', 'nvoos-content-graph-pro' );
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
		return __( 'Get Sequence Performance', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns enrollment counts, completion rates, and step-level metrics for a sequence.', 'nvoos-content-graph-pro' );
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
			return new WP_Error( 'not_found', __( 'Sequence not found.', 'nvoos-content-graph-pro' ) );
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
			'message'            => __( 'Performance snapshot retrieved.', 'nvoos-content-graph-pro' ),
		);
	}
}
