<?php
/**
 * Tool for getting a single ECA's details.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get details of a single Extra-Curricular Activity.
 */
class WP_MCP_AI_Tool_Get_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get ECA', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Gets detailed information about a specific Extra-Curricular Activity including schedule, venue, capacity, enrolled students, and teacher assignments.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id' => array(
					'type'        => 'integer',
					'description' => __( 'ECA ID to retrieve (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'eca_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view ECAs.', 'mcp-ai-wpoos-pro' ) );
		}

		$eca_id = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;

		if ( ! $eca_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'ECA ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$eca = get_post( $eca_id );

		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_eca', __( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get ECA metadata.
		$eca_data = array(
			'id'             => $eca_id,
			'name'           => $eca->post_title,
			'description'    => $eca->post_content,
			'eca_code'       => get_post_meta( $eca_id, '_eca_code', true ),
			'eca_type'       => get_post_meta( $eca_id, '_eca_type', true ),
			'day'            => get_post_meta( $eca_id, '_eca_day', true ),
			'start_time'     => get_post_meta( $eca_id, '_eca_start_time', true ),
			'end_time'       => get_post_meta( $eca_id, '_eca_end_time', true ),
			'venue'          => get_post_meta( $eca_id, '_eca_venue', true ),
			'year_groups'    => get_post_meta( $eca_id, '_eca_year_groups', true ),
			'max_students'   => get_post_meta( $eca_id, '_eca_max_students', true ),
			'teachers'       => get_post_meta( $eca_id, '_eca_teachers', true ),
			'cost'           => get_post_meta( $eca_id, '_eca_cost', true ),
			'currency'       => get_post_meta( $eca_id, '_eca_currency', true ),
			'term'           => get_post_meta( $eca_id, '_eca_term', true ),
			'enrolled_count' => get_post_meta( $eca_id, '_eca_enrolled_count', true ),
			'created_at'     => $eca->post_date,
			'modified_at'    => $eca->post_modified,
		);

		return array(
			'success' => true,
			'eca'     => $eca_data,
		);
	}
}
