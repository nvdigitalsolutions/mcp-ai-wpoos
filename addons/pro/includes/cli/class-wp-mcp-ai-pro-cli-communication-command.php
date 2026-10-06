<?php
/**
 * WP-CLI communications commands for NV oOS Pro.
 *
 * Read-only parity for the Chat Channels toolkit inbox stores: channel
 * contacts (mcp_chan_contact) and channel messages (mcp_chan_message).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-pro-cli-base-command.php';

/**
 * Inspect NV oOS Pro channel communications from the command line.
 *
 * ## EXAMPLES
 *
 *     # List all channel contacts.
 *     $ wp mcp-ai communication contacts
 *
 *     # Show recent WhatsApp messages as JSON.
 *     $ wp mcp-ai communication messages --channel=whatsapp --format=json
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Communication_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * List channel contacts.
	 *
	 * ## OPTIONS
	 *
	 * [--channel=<channel>]
	 * : Filter by platform slug (e.g. whatsapp, telegram, discord).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns (default: ID,name,channel,channel_contact_id,crm_status,last_message_at).
	 *
	 * ## EXAMPLES
	 *
	 *     # List all contacts.
	 *     $ wp mcp-ai communication contacts
	 *
	 *     # Only WhatsApp contacts as CSV.
	 *     $ wp mcp-ai communication contacts --channel=whatsapp --format=csv
	 *
	 *     # Restrict the rendered columns.
	 *     $ wp mcp-ai communication contacts --fields=ID,name,channel
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function contacts( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_chat_channels_toolkit', 'Chat Channels Toolkit' );

		$channel = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'channel', '' ) );
		$format  = $this->get_format( $assoc_args );
		$fields  = $this->get_fields(
			$assoc_args,
			array( 'ID', 'name', 'channel', 'channel_contact_id', 'crm_status', 'last_message_at' )
		);

		$post_type = class_exists( 'WP_MCP_AI_Channel_Contacts_CPT' )
			? WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE
			: 'mcp_chan_contact';

		if ( ! post_type_exists( $post_type ) ) {
			WP_CLI::warning( __( 'The channel contacts store is not available (mcp_chan_contact post type is not registered).', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$query = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_last_message_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'          => 'DESC',
		);

		if ( '' !== $channel ) {
			$query['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_channel',
					'value' => $channel,
				),
			);
		}

		$posts = get_posts( $query );

		if ( empty( $posts ) ) {
			WP_CLI::log( __( 'No contacts found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'ID'                 => $post->ID,
				'name'               => $post->post_title,
				'channel'            => (string) get_post_meta( $post->ID, '_channel', true ),
				'channel_contact_id' => (string) get_post_meta( $post->ID, '_channel_contact_id', true ),
				'connection_id'      => (string) get_post_meta( $post->ID, '_connection_id', true ),
				'phone'              => (string) get_post_meta( $post->ID, '_phone_number', true ),
				'email'              => (string) get_post_meta( $post->ID, '_email', true ),
				'crm_status'         => (string) get_post_meta( $post->ID, '_crm_status', true ),
				'human_takeover'     => (bool) get_post_meta( $post->ID, '_human_takeover', true ) ? 'yes' : 'no',
				'assigned_agent'     => (string) get_post_meta( $post->ID, '_assigned_agent', true ),
				'last_message_at'    => $this->format_timestamp( (int) get_post_meta( $post->ID, '_last_message_at', true ) ),
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * List channel messages.
	 *
	 * ## OPTIONS
	 *
	 * [--channel=<channel>]
	 * : Filter by platform slug (e.g. whatsapp, telegram, discord).
	 *
	 * [--status=<status>]
	 * : Filter by delivery status (e.g. received, sent, delivered, read).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated columns (default: ID,channel,contact,direction,status,type,timestamp).
	 *
	 * ## EXAMPLES
	 *
	 *     # List all messages (newest first).
	 *     $ wp mcp-ai communication messages
	 *
	 *     # Only outbound Telegram messages.
	 *     $ wp mcp-ai communication messages --channel=telegram --fields=ID,direction,status,timestamp
	 *
	 *     # Only undelivered messages.
	 *     $ wp mcp-ai communication messages --status=received --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function messages( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_chat_channels_toolkit', 'Chat Channels Toolkit' );

		$channel = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'channel', '' ) );
		$status  = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', '' ) );
		$format  = $this->get_format( $assoc_args );
		$fields  = $this->get_fields(
			$assoc_args,
			array( 'ID', 'channel', 'contact', 'direction', 'status', 'type', 'timestamp' )
		);

		$post_type = class_exists( 'WP_MCP_AI_Channel_Messages_CPT' )
			? WP_MCP_AI_Channel_Messages_CPT::POST_TYPE
			: 'mcp_chan_message';

		if ( ! post_type_exists( $post_type ) ) {
			WP_CLI::warning( __( 'The channel messages store is not available (mcp_chan_message post type is not registered).', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$query = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_message_timestamp', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'          => 'DESC',
		);

		$meta_query = array();
		if ( '' !== $channel ) {
			$meta_query[] = array(
				'key'   => '_channel',
				'value' => $channel,
			);
		}
		if ( '' !== $status ) {
			$meta_query[] = array(
				'key'   => '_status',
				'value' => $status,
			);
		}
		if ( ! empty( $meta_query ) ) {
			$query['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$posts = get_posts( $query );

		if ( empty( $posts ) ) {
			WP_CLI::log( __( 'No messages found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'ID'                 => $post->ID,
				'channel'            => (string) get_post_meta( $post->ID, '_channel', true ),
				'contact'            => $post->post_title,
				'channel_contact_id' => (string) get_post_meta( $post->ID, '_channel_contact_id', true ),
				'connection_id'      => (string) get_post_meta( $post->ID, '_connection_id', true ),
				'direction'          => (string) get_post_meta( $post->ID, '_direction', true ),
				'message_id'         => (string) get_post_meta( $post->ID, '_message_id', true ),
				'type'               => (string) get_post_meta( $post->ID, '_message_type', true ),
				'status'             => (string) get_post_meta( $post->ID, '_status', true ),
				'reply_sent'         => (bool) get_post_meta( $post->ID, '_reply_sent', true ) ? 'yes' : 'no',
				'assigned_agent'     => (string) get_post_meta( $post->ID, '_assigned_agent', true ),
				'timestamp'          => $this->format_timestamp( (int) get_post_meta( $post->ID, '_message_timestamp', true ) ),
				'content'            => $post->post_content,
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Format a Unix timestamp using the site timezone.
	 *
	 * @param int $timestamp Unix timestamp (0 renders as an empty string).
	 * @return string
	 */
	private function format_timestamp( $timestamp ) {
		return $timestamp > 0 ? wp_date( 'Y-m-d H:i:s', $timestamp ) : '';
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai communication', 'WP_MCP_AI_Pro_CLI_Communication_Command' );
}
