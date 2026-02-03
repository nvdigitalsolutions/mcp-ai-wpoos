<?php
/**
 * Tool for retrieving WebChat messages from CCT.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Retrieves messages from the WebChat messages CCT.
 */
class WP_MCP_AI_Tool_Get_WebChat_Messages implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if WebChat is enabled and JetEngine is active.
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_webchat_integration'] ) && function_exists( 'jet_engine' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_webchat_messages';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get WebChat Messages', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves messages from the webchat_messages CCT for a specific room. Requires JetEngine Custom Content Types.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'room_id'      => array(
					'type'        => 'integer',
					'description' => __( 'WebChat room post ID to retrieve messages from.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of messages to retrieve (default: 50, max: 100).', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'offset'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of messages to skip (default: 0).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
				'message_type' => array(
					'type'        => 'string',
					'description' => __( 'Optional filter by message type: text, image, file, or system.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'text', 'image', 'file', 'system' ),
				),
			),
			'required'             => array( 'room_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to read WebChat messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if JetEngine is available.
		if ( ! function_exists( 'jet_engine' ) ) {
			return new WP_Error(
				'wp_mcp_ai_jetengine_unavailable',
				__( 'JetEngine Custom Content Types is required to retrieve WebChat messages.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Load the CCT handler.
		$cct_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-webchat-messages-cct.php';
		if ( file_exists( $cct_file ) ) {
			require_once $cct_file;
		}

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_WebChat_Messages_CCT' ) ) {
			return new WP_Error(
				'wp_mcp_ai_cct_unavailable',
				__( 'WebChat messages CCT is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if table exists.
		if ( ! WP_MCP_AI_JetEngine_WebChat_Messages_CCT::table_exists() ) {
			return new WP_Error(
				'wp_mcp_ai_cct_table_missing',
				__( 'WebChat messages table does not exist. Ensure JetEngine Custom Content Types module is active.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize and validate inputs.
		$room_id      = isset( $arguments['room_id'] ) ? absint( $arguments['room_id'] ) : 0;
		$limit        = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;
		$offset       = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;
		$message_type = isset( $arguments['message_type'] ) ? sanitize_text_field( $arguments['message_type'] ) : '';

		if ( ! $room_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_room_id', __( 'Room ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Enforce limits.
		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		// Validate message type if provided.
		$valid_types = array( 'text', 'image', 'file', 'system' );
		if ( '' !== $message_type && ! in_array( $message_type, $valid_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_message_type',
				__( 'Invalid message type. Must be: text, image, file, or system.', 'mcp-ai-wpoos-pro' )
			);
		}

		global $wpdb;
		$table = WP_MCP_AI_JetEngine_WebChat_Messages_CCT::get_table_name();

		// Build query.
		$where_clauses = array( 'room_id = %d' );
		$where_values  = array( $room_id );

		if ( '' !== $message_type ) {
			$where_clauses[] = 'message_type = %s';
			$where_values[]  = $message_type;
		}

		$where_sql    = implode( ' AND ', $where_clauses );
		$query_values = array_merge( $where_values, array( $limit, $offset ) );

		// Escape table name.
		$table = esc_sql( $table );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped, $where_sql contains only hardcoded placeholders.
		$query = $wpdb->prepare(
			"SELECT _ID, room_id, peer_id, user_id, sender_name, message, message_type, is_encrypted, timestamp, metadata, cct_created
			FROM {$table}
			WHERE {$where_sql}
			ORDER BY cct_created DESC
			LIMIT %d OFFSET %d",
			$query_values
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		WP_MCP_AI_Logger::log_event(
			'webchat_get_messages',
			'Retrieving WebChat messages from CCT.',
			array(
				'room_id'      => $room_id,
				'limit'        => $limit,
				'offset'       => $offset,
				'message_type' => $message_type,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $query, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		// Get total count.
		$count_values = $where_values;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped, $where_sql contains only hardcoded placeholders.
		$count_query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
			$count_values
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = absint( $wpdb->get_var( $count_query ) );

		// Format messages.
		$messages = array();
		foreach ( $rows as $row ) {
			$messages[] = array(
				'message_id'   => absint( $row['_ID'] ),
				'room_id'      => absint( $row['room_id'] ),
				'peer_id'      => $row['peer_id'],
				'user_id'      => absint( $row['user_id'] ),
				'sender_name'  => $row['sender_name'],
				'message'      => $row['message'],
				'message_type' => $row['message_type'],
				'is_encrypted' => (bool) $row['is_encrypted'],
				'timestamp'    => $row['timestamp'],
				'metadata'     => $row['metadata'],
				'created_at'   => $row['cct_created'],
			);
		}

		return array(
			'room_id'       => $room_id,
			'messages'      => $messages,
			'message_count' => count( $messages ),
			'total'         => $total,
			'limit'         => $limit,
			'offset'        => $offset,
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
		);
	}
}
