<?php
/**
 * JetEngine Custom Content Type registration for WebChat messages.
 *
 * Stores P2P chat messages from WebChat browser extension in a CCT
 * for efficient storage and querying. Follows the pattern of the
 * internal chat transcripts CCT.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure the WebChat messages CCT exists and expose helper accessors.
 */
class WP_MCP_AI_JetEngine_WebChat_Messages_CCT {
	/**
	 * CCT slug.
	 */
	const SLUG = 'webchat_messages';

	/**
	 * Base ID for meta field identifiers.
	 * Using 40000 range to avoid conflicts with other CCT fields.
	 */
	const FIELD_ID_BASE = 40000;

	/**
	 * Hook into JetEngine to provision the webchat messages content type.
	 */
	public static function bootstrap() {
		// Run after JetEngine initialises the Custom Content Types module.
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 100 );

		// Ensure data stores module is enabled when JetEngine is active.
		add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 100 );
	}

	/**
	 * Retrieve the webchat messages CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Retrieve the JetEngine item handler for the webchat messages content type.
	 *
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();

		if ( ! $module || empty( $module->manager ) ) {
			return null;
		}

		// Ensure CCT is registered.
		$cct_exists = self::cct_exists( $module );
		if ( ! $cct_exists ) {
			self::maybe_register_cct();
		}

		$instance = $module->manager->get_content_types( self::SLUG );

		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}

	/**
	 * Automatically enable the JetEngine data stores module if it's not already active.
	 */
	public static function maybe_enable_data_stores() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return;
		}

		// Check if data stores module is already active.
		if ( $engine->modules->is_module_active( 'data-stores' ) ) {
			return;
		}

		// Activate the data stores module.
		if ( method_exists( $engine->modules, 'get_module' ) ) {
			$module = $engine->modules->get_module( 'data-stores' );
			if ( $module && method_exists( $module, 'module_init' ) ) {
				$module->module_init();
			}
		}
	}

	/**
	 * Register the CCT if it doesn't exist.
	 */
	public static function maybe_register_cct() {
		$module = self::get_cct_module();

		if ( ! $module ) {
			return;
		}

		// Check if the CCT already exists.
		if ( self::cct_exists( $module ) ) {
			return;
		}

		// Register the CCT.
		$args = array(
			'slug'          => self::SLUG,
			'name'          => __( 'WebChat Messages', 'mcp-ai-wpoos-pro' ),
			'singular_name' => __( 'WebChat Message', 'mcp-ai-wpoos-pro' ),
			'status'        => 'publish',
			'show_edit_link' => false,
			'has_single'    => false,
			'fields'        => self::get_fields_schema(),
		);

		if ( method_exists( $module->manager, 'edit_item' ) ) {
			$module->manager->edit_item( false, $args );
		}
	}

	/**
	 * Get the CCT module from JetEngine.
	 *
	 * @return object|null
	 */
	protected static function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) ) {
			return null;
		}

		return $engine->modules->modules['custom-content-types'];
	}

	/**
	 * Check if the CCT exists in JetEngine.
	 *
	 * @param object $module The CCT module.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		if ( empty( $module->manager ) ) {
			return false;
		}

		$instance = $module->manager->get_content_types( self::SLUG );

		return ! empty( $instance );
	}

	/**
	 * Get the fields schema for the WebChat messages CCT.
	 *
	 * @return array
	 */
	protected static function get_fields_schema() {
		$base_id = self::FIELD_ID_BASE;

		return array(
			// Room ID (references mcp_ai_webchat CPT post ID).
			array(
				'id'              => $base_id + 1,
				'title'           => __( 'Room ID', 'mcp-ai-wpoos-pro' ),
				'name'            => 'room_id',
				'type'            => 'number',
				'search'          => true,
				'is_num'          => true,
				'width'           => '100%',
				'default_val'     => '',
				'description'     => __( 'WebChat room post ID', 'mcp-ai-wpoos-pro' ),
			),
			// Peer ID (WebRTC peer identifier).
			array(
				'id'              => $base_id + 2,
				'title'           => __( 'Peer ID', 'mcp-ai-wpoos-pro' ),
				'name'            => 'peer_id',
				'type'            => 'text',
				'search'          => true,
				'width'           => '100%',
				'default_val'     => '',
				'description'     => __( 'WebRTC peer identifier', 'mcp-ai-wpoos-pro' ),
			),
			// User ID (WordPress user if logged in, 0 for anonymous).
			array(
				'id'              => $base_id + 3,
				'title'           => __( 'User ID', 'mcp-ai-wpoos-pro' ),
				'name'            => 'user_id',
				'type'            => 'number',
				'search'          => true,
				'is_num'          => true,
				'width'           => '100%',
				'default_val'     => '0',
				'description'     => __( 'WordPress user ID (0 for anonymous)', 'mcp-ai-wpoos-pro' ),
			),
			// Sender name.
			array(
				'id'              => $base_id + 4,
				'title'           => __( 'Sender Name', 'mcp-ai-wpoos-pro' ),
				'name'            => 'sender_name',
				'type'            => 'text',
				'search'          => true,
				'width'           => '100%',
				'default_val'     => '',
				'description'     => __( 'Display name of the sender', 'mcp-ai-wpoos-pro' ),
			),
			// Message content.
			array(
				'id'              => $base_id + 5,
				'title'           => __( 'Message', 'mcp-ai-wpoos-pro' ),
				'name'            => 'message',
				'type'            => 'textarea',
				'search'          => true,
				'width'           => '100%',
				'default_val'     => '',
				'description'     => __( 'Message content', 'mcp-ai-wpoos-pro' ),
			),
			// Message type (text, image, file, system).
			array(
				'id'              => $base_id + 6,
				'title'           => __( 'Message Type', 'mcp-ai-wpoos-pro' ),
				'name'            => 'message_type',
				'type'            => 'select',
				'options'         => array(
					array(
						'key'   => 'text',
						'value' => __( 'Text', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'image',
						'value' => __( 'Image', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'file',
						'value' => __( 'File', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'system',
						'value' => __( 'System', 'mcp-ai-wpoos-pro' ),
					),
				),
				'width'           => '100%',
				'default_val'     => 'text',
				'description'     => __( 'Type of message', 'mcp-ai-wpoos-pro' ),
			),
			// Is encrypted (WebRTC E2EE).
			array(
				'id'              => $base_id + 7,
				'title'           => __( 'Is Encrypted', 'mcp-ai-wpoos-pro' ),
				'name'            => 'is_encrypted',
				'type'            => 'checkbox',
				'is_array'        => false,
				'width'           => '100%',
				'default_val'     => false,
				'description'     => __( 'Whether message was end-to-end encrypted', 'mcp-ai-wpoos-pro' ),
			),
			// Timestamp (message sent time).
			array(
				'id'              => $base_id + 8,
				'title'           => __( 'Timestamp', 'mcp-ai-wpoos-pro' ),
				'name'            => 'timestamp',
				'type'            => 'datetime-local',
				'search'          => true,
				'width'           => '100%',
				'default_val'     => '',
				'description'     => __( 'Message timestamp', 'mcp-ai-wpoos-pro' ),
			),
			// Metadata (JSON for additional data).
			array(
				'id'              => $base_id + 9,
				'title'           => __( 'Metadata', 'mcp-ai-wpoos-pro' ),
				'name'            => 'metadata',
				'type'            => 'textarea',
				'search'          => false,
				'width'           => '100%',
				'default_val'     => '',
				'description'     => __( 'JSON metadata for the message', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get the table name for the CCT.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'jet_cct_' . self::SLUG;
	}

	/**
	 * Check if the CCT table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $result === $table;
	}
}
