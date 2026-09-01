<?php
/**
 * Tool that lists configured Gmail connections.
 *
 * Lets an agent discover valid connection_id values for search_gmail,
 * get_gmail_message, get_gmail_thread, and modify_gmail_message instead of
 * guessing. Credentials are never exposed — only id, name, type, enabled
 * state, and the configured user email are returned.
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

/**
 * Provides an assistant tool for discovering Gmail connections.
 */
class WP_MCP_AI_Pro_Tool_List_Gmail_Connections implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * Connection types that count as Gmail-capable inboxes.
	 */
	const GMAIL_CONNECTION_TYPES = array( 'gmail', 'google_workspace', 'email_imap' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_gmail_connections';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Gmail Connections', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists configured Gmail connections (Remote Sites roster plus the settings-based fallback) with their connection IDs, names, types, enabled state, and user emails. Use the returned IDs as connection_id for search_gmail, get_gmail_message, get_gmail_thread, and modify_gmail_message. Credentials are never returned.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'required'             => array(),
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
	 * @param array $arguments Tool arguments (none).
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters( 'wp_mcp_ai_list_gmail_connections_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_gmail_forbidden', __( 'You do not have permission to list Gmail connections.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_gmail_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$connections = array();

		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

			if ( is_array( $all_connections ) ) {
				foreach ( $all_connections as $conn_id => $connection ) {
					if ( ! is_array( $connection ) ) {
						continue;
					}

					$conn_type = isset( $connection['connection_type'] ) ? sanitize_key( $connection['connection_type'] ) : '';
					if ( ! in_array( $conn_type, self::GMAIL_CONNECTION_TYPES, true ) ) {
						continue;
					}

					// Redacted by construction: credentials are never copied out.
					$connections[] = array(
						'id'              => (string) $conn_id,
						'name'            => isset( $connection['name'] ) ? (string) $connection['name'] : (string) $conn_id,
						'connection_type' => $conn_type,
						'enabled'         => ! empty( $connection['enabled'] ),
						'user_email'      => isset( $connection['user_email'] ) ? (string) $connection['user_email'] : '',
					);
				}
			}
		}

		// Settings-based fallback: represents the inbox used when connection_id is omitted.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		if ( ! empty( $settings['gmail_client_id'] ) && ! empty( $settings['gmail_refresh_token'] ) ) {
			$connections[] = array(
				'id'              => 'settings',
				'name'            => __( 'NV oOS Gmail settings (default connection)', 'mcp-ai-wpoos-pro' ),
				'connection_type' => 'gmail',
				'enabled'         => true,
				'user_email'      => isset( $settings['gmail_user_email'] ) ? (string) $settings['gmail_user_email'] : '',
			);
		}

		$data = array(
			'connections' => $connections,
			'note'        => __( 'Credentials are never included in this list. Omit connection_id to use the settings-based default ("settings" row).', 'mcp-ai-wpoos-pro' ),
		);

		return $this->format_success_response(
			__( 'Gmail connections listed.', 'mcp-ai-wpoos-pro' ),
			array( 'data' => $data )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read-only',            // Only reads configuration, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
