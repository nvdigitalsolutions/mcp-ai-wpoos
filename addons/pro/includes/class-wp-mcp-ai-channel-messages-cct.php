<?php
/**
 * JetEngine Custom Content Type registration for channel messages.
 *
 * Stores inbound and outbound messages from all supported chat channels
 * (WhatsApp, Telegram, Slack, Discord, Microsoft Teams, Facebook Messenger,
 * Google Chat, Twitter/X) in a unified CCT table for querying via the
 * Chat Channels inbox dashboard.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure the channel messages CCT exists and expose helper accessors.
 */
class WP_MCP_AI_Channel_Messages_CCT {

	/**
	 * CCT slug.
	 */
	const SLUG = 'channel_messages';

	/**
	 * Base ID for meta field identifiers (41000 range).
	 */
	const FIELD_ID_BASE = 41000;

	/**
	 * Hook into JetEngine to provision the content type.
	 */
	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 100 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate_conversation_type' ), 101 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate_connection_id' ), 102 );
	}

	/**
	 * Ensure the conversation_type column exists in the messages CCT table.
	 */
	public static function maybe_migrate_conversation_type() {
		if ( get_option( 'wp_mcp_ai_channel_messages_migration_v1' ) ) {
			return;
		}
		if ( ! self::table_exists() ) {
			return;
		}
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		if ( ! in_array( 'conversation_type', $existing_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `conversation_type` VARCHAR(20) NOT NULL DEFAULT 'dm'" );
		}
		update_option( 'wp_mcp_ai_channel_messages_migration_v1', true );
	}

	/**
	 * Ensure the connection_id column exists in the messages CCT table.
	 *
	 * Older installations that created the CCT before this field was added to
	 * the schema will not have the column, causing queries that reference it
	 * to fail silently and break the inbox.
	 */
	public static function maybe_migrate_connection_id() {
		if ( get_option( 'wp_mcp_ai_channel_messages_migration_v2' ) ) {
			return;
		}
		if ( ! self::table_exists() ) {
			return;
		}
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		if ( ! in_array( 'connection_id', $existing_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `connection_id` VARCHAR(100) NOT NULL DEFAULT ''" );
		}
		update_option( 'wp_mcp_ai_channel_messages_migration_v2', true );
	}

	/**
	 * Retrieve the CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Return the raw database table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'jet_cct_' . self::SLUG;
	}

	/**
	 * Check whether the CCT database table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Insert a single message row.
	 *
	 * Falls back to a direct wpdb insert when JetEngine is not available so
	 * messages are never silently dropped. When JetEngine IS available, the
	 * item_handler is used so that JetEngine indexing stays in sync.
	 *
	 * @param array $data { // phpcs:ignore Squiz.Commenting.FunctionComment.ParamCommentFullStop -- Nested param structure.
	 *   @type string $channel          Platform slug, e.g. 'whatsapp'.
	 *   @type string $channel_contact_id  Platform-side contact ID.
	 *   @type string $contact_name     Display name of the contact.
	 *   @type string $direction        'inbound' or 'outbound'.
	 *   @type string $message_id       Platform message ID (for deduplication).
	 *   @type string $message_type     'text', 'image', 'video', 'audio', 'document', 'interactive', 'location', 'other'.
	 *   @type string $content          Human-readable message text.
	 *   @type string $raw_payload      JSON-encoded full platform payload.
	 *   @type string $status           'received', 'sent', 'delivered', 'read', 'failed'.
	 *   @type string $connection_id    Settings connection identifier.
	 *   @type string $phone_number_id  Platform phone/channel ID.
	 *   @type int    $timestamp        Unix timestamp of the message.
	 *   @type int    $reply_sent       1 when an AI reply has been dispatched.
	 *   @type string $assigned_agent   Post ID of the AI assistant assigned to this conversation.
	 * }
	 * @return int|false Inserted row ID or false on failure.
	 */
	public static function insert( array $data ) {
		$handler = self::get_item_handler();

		$row = array(
			'channel'            => isset( $data['channel'] ) ? sanitize_key( $data['channel'] ) : '',
			'channel_contact_id' => isset( $data['channel_contact_id'] ) ? sanitize_text_field( $data['channel_contact_id'] ) : '',
			'contact_name'       => isset( $data['contact_name'] ) ? sanitize_text_field( $data['contact_name'] ) : '',
			'direction'          => isset( $data['direction'] ) && 'outbound' === $data['direction'] ? 'outbound' : 'inbound',
			'message_id'         => isset( $data['message_id'] ) ? sanitize_text_field( $data['message_id'] ) : '',
			'message_type'       => isset( $data['message_type'] ) ? sanitize_text_field( $data['message_type'] ) : 'text',
			'content'            => isset( $data['content'] ) ? sanitize_textarea_field( $data['content'] ) : '',
			'raw_payload'        => isset( $data['raw_payload'] ) ? wp_json_encode( $data['raw_payload'] ) : '',
			'status'             => isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'received',
			'connection_id'      => isset( $data['connection_id'] ) ? sanitize_text_field( $data['connection_id'] ) : '',
			'phone_number_id'    => isset( $data['phone_number_id'] ) ? sanitize_text_field( $data['phone_number_id'] ) : '',
			'cct_status'         => 'publish',
			'reply_sent'         => ! empty( $data['reply_sent'] ) ? 1 : 0,
			'assigned_agent'     => isset( $data['assigned_agent'] ) ? sanitize_text_field( $data['assigned_agent'] ) : '',
			'conversation_type'  => isset( $data['conversation_type'] ) ? sanitize_key( $data['conversation_type'] ) : 'dm',
		);

		// Store Unix timestamp as integer.
		$row['message_timestamp'] = isset( $data['timestamp'] ) ? absint( $data['timestamp'] ) : time();

		$message_id = false;

		if ( $handler && method_exists( $handler, 'create_item' ) ) {
			$result     = $handler->create_item( $row );
			$message_id = is_numeric( $result ) ? (int) $result : false;
		}

		// Fallback: direct DB insert when JetEngine is not available but table exists.
		if ( ! $message_id && self::table_exists() ) {
			global $wpdb;
			$table = self::get_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $row );
			$message_id = $wpdb->insert_id ? (int) $wpdb->insert_id : false;
		}

		// Final fallback: use the CPT store when neither JetEngine nor the table
		// is available (e.g. first run without JetEngine installed).
		if ( ! $message_id && class_exists( 'WP_MCP_AI_Channel_Messages_CPT' ) ) {
			$message_id = WP_MCP_AI_Channel_Messages_CPT::insert( $data );
		}

		// Fire the message-received hook so CRM and other toolkits can react.
		if ( $message_id && 'inbound' === $row['direction'] ) {
			do_action(
				'wp_mcp_ai_chat_channel_message_received',
				$message_id,
				$row['channel'],
				$row['channel_contact_id'],
				$row['contact_name'],
				$row['content'],
				$row['message_type'],
				$row['connection_id']
			);
		}

		return $message_id;
	}

	/**
	 * Retrieve recent messages for a contact from the CCT as OpenAI-style chat pairs.
	 *
	 * Returns up to $limit of the most recent text messages for the given channel
	 * contact on the given connection, ordered chronologically (oldest first) so
	 * they can be prepended to the current user message when building the AI
	 * request. Inbound messages map to role "user"; outbound to role "assistant".
	 *
	 * The method is intentionally lightweight (one direct SQL query) and safe to
	 * call when the JetEngine module is not active—it falls back to direct $wpdb
	 * access whenever the table exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel    Platform slug, e.g. 'telegram'.
	 * @param string $contact_id Platform-side contact/user ID.
	 * @param string $connection_id Plugin connection identifier.
	 * @param int    $limit      Maximum number of message pairs to return (default 10).
	 * @return array[] Array of ['role' => 'user'|'assistant', 'content' => string].
	 */
	public static function get_recent_messages( $channel, $contact_id, $connection_id, $limit = 10 ) {
		if ( ! self::table_exists() ) {
			// Fall back to the CPT store when the CCT table is unavailable.
			if ( class_exists( 'WP_MCP_AI_Channel_Messages_CPT' ) ) {
				return WP_MCP_AI_Channel_Messages_CPT::get_recent_messages( $channel, $contact_id, $connection_id, $limit );
			}
			return array();
		}

		global $wpdb;

		$table = self::get_table_name();
		$limit = max( 1, (int) $limit );

		// Retrieve the most recent $limit rows in reverse-chronological order, then
		// reverse them so the returned array is oldest-first (chronological).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT direction, content FROM `{$table}`
				 WHERE channel = %s
				   AND channel_contact_id = %s
				   AND connection_id = %s
				   AND message_type = 'text'
				   AND content != ''
				 ORDER BY message_timestamp DESC, _ID DESC
				 LIMIT %d",
				sanitize_key( $channel ),
				sanitize_text_field( $contact_id ),
				sanitize_text_field( $connection_id ),
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array();
		}

		// Reverse to restore chronological order (oldest → newest).
		$rows     = array_reverse( $rows );
		$messages = array();

		foreach ( $rows as $row ) {
			$direction = isset( $row['direction'] ) ? $row['direction'] : '';
			$content   = isset( $row['content'] ) ? trim( (string) $row['content'] ) : '';

			if ( '' === $content ) {
				continue;
			}

			$messages[] = array(
				'role'    => 'outbound' === $direction ? 'assistant' : 'user',
				'content' => $content,
			);
		}

		return $messages;
	}

	/**
	 * Retrieve the JetEngine item handler.
	 *
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();
		if ( ! $module || empty( $module->manager ) ) {
			return null;
		}

		if ( ! self::cct_exists( $module ) ) {
			self::maybe_register_cct();
		}

		$instance = $module->manager->get_content_types( self::SLUG );
		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}

	/**
	 * Register the CCT in JetEngine if it has not been registered yet.
	 */
	public static function maybe_register_cct() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_chat_channels_toolkit'] ) ) {
			return;
		}

		$module = self::get_cct_module();
		if ( ! $module ) {
			return;
		}

		if ( self::cct_exists( $module ) ) {
			return;
		}

		if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
			return;
		}

		$module->manager->data->set_request( self::get_registration_request() );
		$module->manager->data->create_item( false );
	}

	/**
	 * Get the JetEngine CCT module instance.
	 *
	 * @return object|null
	 */
	protected static function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return null;
		}

		if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
			return null;
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );
		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return null;
		}

		return $module_wrapper->instance;
	}

	/**
	 * Check whether the CCT slug exists in JetEngine (database check).
	 *
	 * @param object $module CCT module.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		if ( empty( $module->manager ) || empty( $module->manager->data ) || empty( $module->manager->data->db ) ) {
			return false;
		}

		$records = $module->manager->data->db->query(
			'post_types',
			array(
				'slug'   => self::SLUG,
				'status' => 'content-type',
			),
			null,
			false
		);

		return ! empty( $records );
	}

	/**
	 * Build the JetEngine registration request payload.
	 *
	 * @return array
	 */
	protected static function get_registration_request() {
		$label = __( 'Channel Messages', 'mcp-ai-wpoos-pro' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_fields_schema(),
		);
	}

	/**
	 * Assemble JetEngine CCT arguments for channel messages.
	 *
	 * @param string $label Human-readable label.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-format-chat',
			'capability'          => 'manage_options',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => false,
			'rest_post_enabled'   => false,
			'rest_delete_enabled' => false,
			'rest_get_access'     => 'manage_options',
			'rest_put_access'     => 'edit_posts',
			'rest_post_access'    => 'edit_posts',
			'rest_delete_access'  => 'edit_posts',
			'admin_columns'       => array(
				'_ID'                => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'channel'            => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'channel_contact_id' => array( 'enabled' => true ),
				'direction'          => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'content'            => array( 'enabled' => true ),
				'message_timestamp'  => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'cct_created'        => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Field schema for the channel_messages CCT.
	 *
	 * @return array
	 */
	protected static function get_fields_schema() {
		$b = self::FIELD_ID_BASE;

		$fields = array(
			array(
				'id'          => $b + 1,
				'title'       => __( 'Channel', 'mcp-ai-wpoos-pro' ),
				'name'        => 'channel',
				'type'        => 'select',
				'search'      => true,
				'width'       => '100%',
				'default_val' => 'whatsapp',
				'options'     => array(
					array(
						'key'   => 'whatsapp',
						'value' => 'WhatsApp',
					),
					array(
						'key'   => 'telegram',
						'value' => 'Telegram',
					),
					array(
						'key'   => 'slack',
						'value' => 'Slack',
					),
					array(
						'key'   => 'discord',
						'value' => 'Discord',
					),
					array(
						'key'   => 'teams',
						'value' => 'Microsoft Teams',
					),
					array(
						'key'   => 'messenger',
						'value' => 'Facebook Messenger',
					),
					array(
						'key'   => 'google_chat',
						'value' => 'Google Chat',
					),
					array(
						'key'   => 'twitter',
						'value' => 'Twitter/X',
					),
					array(
						'key'   => 'webchat',
						'value' => 'WebChat',
					),
				),
				'description' => __( 'Chat platform', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 2,
				'title'       => __( 'Channel Contact ID', 'mcp-ai-wpoos-pro' ),
				'name'        => 'channel_contact_id',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Platform-side contact identifier (phone number, user ID, etc.)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 3,
				'title'       => __( 'Contact Name', 'mcp-ai-wpoos-pro' ),
				'name'        => 'contact_name',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Display name of the contact', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 4,
				'title'       => __( 'Direction', 'mcp-ai-wpoos-pro' ),
				'name'        => 'direction',
				'type'        => 'select',
				'search'      => true,
				'width'       => '100%',
				'default_val' => 'inbound',
				'options'     => array(
					array(
						'key'   => 'inbound',
						'value' => __( 'Inbound', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'outbound',
						'value' => __( 'Outbound', 'mcp-ai-wpoos-pro' ),
					),
				),
				'description' => __( 'Whether this message was received or sent', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 5,
				'title'       => __( 'Platform Message ID', 'mcp-ai-wpoos-pro' ),
				'name'        => 'message_id',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Unique message ID from the platform (used for deduplication)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 6,
				'title'       => __( 'Message Type', 'mcp-ai-wpoos-pro' ),
				'name'        => 'message_type',
				'type'        => 'select',
				'search'      => true,
				'width'       => '100%',
				'default_val' => 'text',
				'options'     => array(
					array(
						'key'   => 'text',
						'value' => __( 'Text', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'image',
						'value' => __( 'Image', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'video',
						'value' => __( 'Video', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'audio',
						'value' => __( 'Audio', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'document',
						'value' => __( 'Document', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'interactive',
						'value' => __( 'Interactive', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'location',
						'value' => __( 'Location', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'other',
						'value' => __( 'Other', 'mcp-ai-wpoos-pro' ),
					),
				),
				'description' => __( 'Type of message content', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 7,
				'title'       => __( 'Content', 'mcp-ai-wpoos-pro' ),
				'name'        => 'content',
				'type'        => 'textarea',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Human-readable message text or description', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 8,
				'title'       => __( 'Raw Payload', 'mcp-ai-wpoos-pro' ),
				'name'        => 'raw_payload',
				'type'        => 'textarea',
				'search'      => false,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'JSON-encoded raw platform payload', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 9,
				'title'       => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'status',
				'type'        => 'select',
				'search'      => true,
				'width'       => '100%',
				'default_val' => 'received',
				'options'     => array(
					array(
						'key'   => 'received',
						'value' => __( 'Received', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'sent',
						'value' => __( 'Sent', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'delivered',
						'value' => __( 'Delivered', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'read',
						'value' => __( 'Read', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'failed',
						'value' => __( 'Failed', 'mcp-ai-wpoos-pro' ),
					),
				),
				'description' => __( 'Message delivery status', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 10,
				'title'       => __( 'Connection ID', 'mcp-ai-wpoos-pro' ),
				'name'        => 'connection_id',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Plugin connection/account identifier', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 11,
				'title'       => __( 'Phone / Channel ID', 'mcp-ai-wpoos-pro' ),
				'name'        => 'phone_number_id',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Platform phone number or channel ID that received/sent the message', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 12,
				'title'       => __( 'Message Timestamp', 'mcp-ai-wpoos-pro' ),
				'name'        => 'message_timestamp',
				'type'        => 'number',
				'search'      => true,
				'is_num'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Unix timestamp of the message', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 13,
				'title'       => __( 'Reply Sent', 'mcp-ai-wpoos-pro' ),
				'name'        => 'reply_sent',
				'type'        => 'checkbox',
				'is_array'    => false,
				'width'       => '100%',
				'default_val' => false,
				'description' => __( 'Whether an AI reply has been dispatched for this message', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 14,
				'title'       => __( 'Assigned Agent', 'mcp-ai-wpoos-pro' ),
				'name'        => 'assigned_agent',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Post ID of the AI assistant assigned to this conversation', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 15,
				'title'       => __( 'Conversation Type', 'mcp-ai-wpoos-pro' ),
				'name'        => 'conversation_type',
				'type'        => 'select',
				'search'      => true,
				'width'       => '100%',
				'default_val' => 'dm',
				'options'     => array(
					array(
						'key'   => 'dm',
						'value' => __( 'Direct Message', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'channel',
						'value' => __( 'Channel', 'mcp-ai-wpoos-pro' ),
					),
					array(
						'key'   => 'group',
						'value' => __( 'Group', 'mcp-ai-wpoos-pro' ),
					),
				),
				'description' => __( 'Whether this message is from a DM, channel, or group conversation', 'mcp-ai-wpoos-pro' ),
			),
		);

		foreach ( $fields as &$field ) {
			$field['object_type'] = 'field';
			$field['isNested']    = false;
		}
		unset( $field );

		return $fields;
	}
}
