<?php
/**
 * Tool: para_list_areas
 *
 * Lists existing PARA Areas, optionally filtered by owner or review cadence.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List Areas.
 */
class WP_MCP_AI_Tool_PARA_List_Areas implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'para_list_areas';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'PARA: List Areas', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'List PARA Areas (ongoing responsibilities), optionally filtered by owner or review cadence. Returns title, owner, standard, cadence, and last-reviewed timestamp.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'owner_id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'review_cadence' => array(
					'type' => 'string',
					'enum' => array( 'weekly', 'biweekly', 'monthly', 'quarterly', 'annually' ),
				),
				'limit'          => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 25,
				),
			),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'paginated' );
	}

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) && WP_MCP_AI_PARA_Taxonomy::is_enabled();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list areas.', 'mcp-ai-wpoos-pro' ) );
		}

		$args = array(
			'post_type'      => WP_MCP_AI_PARA_Area_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => isset( $arguments['limit'] ) ? min( 100, max( 1, absint( $arguments['limit'] ) ) ) : 25,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$meta_query = array();
		if ( ! empty( $arguments['owner_id'] ) ) {
			$meta_query[] = array(
				'key'     => '_para_owner',
				'value'   => absint( $arguments['owner_id'] ),
				'compare' => '=',
			);
		}
		if ( ! empty( $arguments['review_cadence'] ) ) {
			$meta_query[] = array(
				'key'     => '_para_review_cadence',
				'value'   => sanitize_key( $arguments['review_cadence'] ),
				'compare' => '=',
			);
		}
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded list.
		}

		$query = new WP_Query( $args );
		$areas = array();
		foreach ( $query->posts as $post ) {
			$areas[] = WP_MCP_AI_PARA_Area_CPT::get_area( $post->ID );
		}

		return array(
			'success' => true,
			'count'   => count( $areas ),
			'areas'   => $areas,
		);
	}
}
