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
	 * @param array $data {
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
		);

		// Store Unix timestamp as integer.
		$row['message_timestamp'] = isset( $data['timestamp'] ) ? absint( $data['timestamp'] ) : time();

		if ( $handler && method_exists( $handler, 'create_item' ) ) {
			$result = $handler->create_item( $row );
			return is_numeric( $result ) ? (int) $result : false;
		}

		// Fallback: direct DB insert when JetEngine is not available.
		if ( self::table_exists() ) {
			global $wpdb;
			$table = self::get_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $row );
			return $wpdb->insert_id ? $wpdb->insert_id : false;
		}

		return false;
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
		$module = self::get_cct_module();
		if ( ! $module ) {
			return;
		}

		if ( self::cct_exists( $module ) ) {
			return;
		}

		$args = array(
			'slug'           => self::SLUG,
			'name'           => __( 'Channel Messages', 'mcp-ai-wpoos-pro' ),
			'singular_name'  => __( 'Channel Message', 'mcp-ai-wpoos-pro' ),
			'status'         => 'publish',
			'show_edit_link' => false,
			'has_single'     => false,
			'fields'         => self::get_fields_schema(),
		);

		if ( method_exists( $module->manager, 'edit_item' ) ) {
			$module->manager->edit_item( false, $args );
		}
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
	 * Check whether the CCT slug exists in JetEngine.
	 *
	 * @param object $module CCT module.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		if ( empty( $module->manager ) ) {
			return false;
		}

		return ! empty( $module->manager->get_content_types( self::SLUG ) );
	}

	/**
	 * Field schema for the channel_messages CCT.
	 *
	 * @return array
	 */
	protected static function get_fields_schema() {
		$b = self::FIELD_ID_BASE;

		return array(
			array(
				'id'          => $b + 1,
				'title'       => __( 'Channel', 'mcp-ai-wpoos-pro' ),
				'name'        => 'channel',
				'type'        => 'select',
				'search'      => true,
				'width'       => '100%',
				'default_val' => 'whatsapp',
				'options'     => array(
					array( 'key' => 'whatsapp', 'value' => 'WhatsApp' ),
					array( 'key' => 'telegram', 'value' => 'Telegram' ),
					array( 'key' => 'slack', 'value' => 'Slack' ),
					array( 'key' => 'discord', 'value' => 'Discord' ),
					array( 'key' => 'teams', 'value' => 'Microsoft Teams' ),
					array( 'key' => 'messenger', 'value' => 'Facebook Messenger' ),
					array( 'key' => 'google_chat', 'value' => 'Google Chat' ),
					array( 'key' => 'twitter', 'value' => 'Twitter/X' ),
					array( 'key' => 'webchat', 'value' => 'WebChat' ),
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
					array( 'key' => 'inbound', 'value' => __( 'Inbound', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'outbound', 'value' => __( 'Outbound', 'mcp-ai-wpoos-pro' ) ),
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
					array( 'key' => 'text', 'value' => __( 'Text', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'image', 'value' => __( 'Image', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'video', 'value' => __( 'Video', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'audio', 'value' => __( 'Audio', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'document', 'value' => __( 'Document', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'interactive', 'value' => __( 'Interactive', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'location', 'value' => __( 'Location', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'other', 'value' => __( 'Other', 'mcp-ai-wpoos-pro' ) ),
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
					array( 'key' => 'received', 'value' => __( 'Received', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'sent', 'value' => __( 'Sent', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'delivered', 'value' => __( 'Delivered', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'read', 'value' => __( 'Read', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'failed', 'value' => __( 'Failed', 'mcp-ai-wpoos-pro' ) ),
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
		);
	}
}
