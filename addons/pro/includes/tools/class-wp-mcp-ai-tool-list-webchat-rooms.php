<?php
/**
 * Tool for listing WebChat rooms.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists available WebChat rooms.
 */
class WP_MCP_AI_Tool_List_WebChat_Rooms implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_webchat_rooms';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List WebChat Rooms', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists available WebChat rooms with optional filtering by status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by room status: active, inactive, or archived.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'inactive', 'archived' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of rooms to retrieve per page.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Check if tool is available.
	 *
	 * @return bool Whether the tool is available.
	 */
	public static function is_available() {
		// WebChat is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_webchat_integration'] );
	}

	/**
	 * Get unavailable reason message.
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return __( 'WebChat integration is only available in the Pro version.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'WebChat integration is not enabled in settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check availability.
		if ( ! self::is_available() ) {
			WP_MCP_AI_Logger::log_activity( 'Tool unavailable: list_webchat_rooms' );
			return new WP_Error( 'wp_mcp_ai_tool_unavailable', self::get_unavailable_reason() );
		}

		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view WebChat rooms.', 'mcp-ai-wpoos-pro' ) );
		}

		$status   = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$query_args = array(
			'post_type'      => 'mcp_ai_webchat_room',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Add status filter if provided.
		if ( $status && in_array( $status, array( 'active', 'inactive', 'archived' ), true ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_mcp_ai_webchat_status',
					'value' => $status,
				),
			);
		}

		$query = new WP_Query( $query_args );

		$rooms = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$room_id = get_the_ID();

				$rooms[] = array(
					'room_id'              => $room_id,
					'title'                => get_the_title(),
					'description'          => get_post_meta( $room_id, '_mcp_ai_webchat_description', true ),
					'max_participants'     => absint( get_post_meta( $room_id, '_mcp_ai_webchat_max_participants', true ) ),
					'active_participants'  => absint( get_post_meta( $room_id, '_mcp_ai_webchat_active_participants', true ) ),
					'allow_anonymous'      => (bool) get_post_meta( $room_id, '_mcp_ai_webchat_allow_anonymous', true ),
					'status'               => get_post_meta( $room_id, '_mcp_ai_webchat_status', true ),
					'room_url'             => get_permalink( $room_id ),
					'author_id'            => absint( get_the_author_meta( 'ID' ) ),
					'created_at'           => get_the_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'summary'     => sprintf(
				/* translators: %d: number of rooms */
				_n( 'Found %d WebChat room', 'Found %d WebChat rooms', count( $rooms ), 'mcp-ai-wpoos-pro' ),
				count( $rooms )
			),
			'rooms'       => $rooms,
			'total'       => $query->found_posts,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $query->max_num_pages,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
			'paginated',
		);
	}
}
