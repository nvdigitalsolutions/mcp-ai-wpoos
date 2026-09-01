<?php
/**
 * Tool that reads a full Gmail thread by thread ID.
 *
 * Returns every message in the thread, newest first, with per-message plain
 * text (or sanitised HTML) bodies. Solves digest-style reads — e.g. an invoice
 * thread or a PR/CI notification chain — in a single call.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-envelope.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once __DIR__ . '/class-wp-mcp-ai-pro-gmail-client.php';

/**
 * Provides an assistant tool for reading a full Gmail thread.
 */
class WP_MCP_AI_Pro_Tool_Get_Gmail_Thread implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_gmail_thread';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Gmail Thread', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Reads a full Gmail thread by thread ID and returns all messages newest-first with plain text (default) or sanitised HTML bodies, up to max_messages. Use thread IDs from search_gmail results. Bodies longer than max_chars are truncated at a word boundary with the truncated flag set.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'thread_id'           => array(
					'type'        => 'string',
					'description' => __( 'Gmail thread ID to read. Obtain thread IDs from search_gmail results.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id'       => array(
					'type'        => 'string',
					'description' => __( 'Optional Gmail connection ID from Remote Sites. If not provided, uses settings-based credentials.', 'mcp-ai-wpoos-pro' ),
				),
				'max_messages'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of messages to return from the thread (1-50).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'max_chars'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum body characters per message (100-50000). Longer bodies are truncated at a word boundary and flagged with truncated=true.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 100,
					'maximum'     => 50000,
					'default'     => 4000,
				),
				'format'              => array(
					'type'        => 'string',
					'description' => __( 'Body format: "plain" strips all tags (default), "html" returns sanitised HTML with scripts, styles, and images removed.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'plain', 'html' ),
					'default'     => 'plain',
				),
				'include_attachments' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, includes attachment filenames per message. Default true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'thread_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters( 'wp_mcp_ai_get_gmail_thread_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_gmail_forbidden', __( 'You do not have permission to read Gmail threads.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_gmail_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Gate 1 — sanitise at entry.
		$thread_id           = isset( $arguments['thread_id'] ) ? sanitize_text_field( $arguments['thread_id'] ) : '';
		$connection_id       = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$max_messages        = isset( $arguments['max_messages'] ) ? absint( $arguments['max_messages'] ) : 10;
		$max_chars           = isset( $arguments['max_chars'] ) ? absint( $arguments['max_chars'] ) : 4000;
		$format              = isset( $arguments['format'] ) ? sanitize_key( $arguments['format'] ) : 'plain';
		$include_attachments = array_key_exists( 'include_attachments', $arguments ) ? (bool) $arguments['include_attachments'] : true;

		if ( '' === $thread_id ) {
			return new WP_Error( 'wp_mcp_ai_gmail_missing_thread_id', __( 'A Gmail thread ID is required. Obtain thread IDs from search_gmail results.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $max_messages < 1 ) {
			$max_messages = 1;
		}
		if ( $max_messages > 50 ) {
			$max_messages = 50;
		}
		if ( $max_chars < 100 ) {
			$max_chars = 100;
		}
		if ( $max_chars > 50000 ) {
			$max_chars = 50000;
		}
		if ( 'html' !== $format ) {
			$format = 'plain';
		}

		$credentials = WP_MCP_AI_Pro_Gmail_Client::resolve_credentials( $connection_id );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$timeout = WP_MCP_AI_Pro_Gmail_Client::get_request_timeout();

		$access_token = WP_MCP_AI_Pro_Gmail_Client::request_access_token(
			$credentials['client_id'],
			$credentials['client_secret'],
			$credentials['refresh_token'],
			$timeout
		);
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$gmail_user = '' !== $credentials['configured_user'] ? $credentials['configured_user'] : 'me';

		$payload = WP_MCP_AI_Pro_Gmail_Client::request(
			'GET',
			$gmail_user,
			'threads/' . rawurlencode( $thread_id ),
			$access_token,
			$timeout,
			array( 'format' => 'full' )
		);
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$raw_messages = isset( $payload['messages'] ) && is_array( $payload['messages'] ) ? $payload['messages'] : array();

		// The Gmail API does not guarantee thread message ordering — sort newest first.
		usort(
			$raw_messages,
			static function ( $a, $b ) {
				$ta = WP_MCP_AI_Pro_Gmail_Client::get_timestamp( $a );
				$tb = WP_MCP_AI_Pro_Gmail_Client::get_timestamp( $b );
				$ta = null === $ta ? 0 : $ta;
				$tb = null === $tb ? 0 : $tb;

				return $tb <=> $ta;
			}
		);

		$messages = array();
		foreach ( array_slice( $raw_messages, 0, $max_messages ) as $message_payload ) {
			$messages[] = WP_MCP_AI_Pro_Gmail_Client::normalize_message(
				$message_payload,
				$format,
				$max_chars,
				false,
				$include_attachments,
				''
			);
		}

		$data = array(
			'thread_id'      => isset( $payload['id'] ) ? (string) $payload['id'] : $thread_id,
			'history_id'     => isset( $payload['historyId'] ) ? (string) $payload['historyId'] : '',
			'message_count'  => count( $raw_messages ),
			'returned_count' => count( $messages ),
			'messages'       => $messages,
		);

		return $this->format_success_response(
			__( 'Gmail thread retrieved.', 'mcp-ai-wpoos-pro' ),
			array( 'data' => $data )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Calls the Gmail API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
			'pii-data',             // Thread bodies contain personal data.
		);
	}
}
