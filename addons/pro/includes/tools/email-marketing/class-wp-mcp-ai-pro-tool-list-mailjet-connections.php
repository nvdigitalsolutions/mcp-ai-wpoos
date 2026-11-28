<?php
/**
 * Tool that lists the Mailjet connections an assistant may use.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once __DIR__ . '/class-wp-mcp-ai-pro-mailjet-credentials.php';

/**
 * Lists Mailjet connections without exposing key material.
 *
 * This is the discovery half of the "never guess" contract: when more than one
 * Mailjet connection is available, the credential resolver refuses to choose and
 * names the candidates. The model then calls this tool to pick one and passes
 * its `id` as `connection_id` on the next call.
 *
 * @since 1.2.25
 */
class WP_MCP_AI_Pro_Tool_List_Mailjet_Connections implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_mailjet_connections';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Mailjet Connections', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists the Mailjet accounts (Remote Sites connections) this assistant can use, with their region and default sender. Never returns API keys.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'When a Mailjet tool reports that several connections are available and asks for a connection_id, or when the operator asks which Mailjet accounts are configured.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Listing Gmail or Brevo connections; use list_gmail_connections for inboxes. Not needed when only one Mailjet connection exists — the tools resolve it automatically.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'send_mailjet_email', 'get_mailjet_statistics', 'manage_mailjet_contacts' ),
			'notes'           => __( 'Pass a returned id as connection_id on any Mailjet tool. Each API key is a separate Mailjet subaccount with its own validated senders and statistics.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read',                 // Read-only operation.
			'requires-capability',  // Requires user capabilities.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including assistant_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		unset( $arguments );

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to list Mailjet connections.', 'mcp-ai-wpoos-pro' )
			);
		}

		$connections = WP_MCP_AI_Pro_Mailjet_Credentials::list_connections( $context );

		// Output gate: every value leaves through an escaping function, even
		// though it was sanitised on the way in.
		$safe = array();

		foreach ( $connections as $connection ) {
			$safe[] = array(
				'id'              => sanitize_text_field( (string) $connection['id'] ),
				'name'            => sanitize_text_field( (string) $connection['name'] ),
				'region'          => sanitize_key( (string) $connection['region'] ),
				'from_email'      => sanitize_email( (string) $connection['from_email'] ),
				'from_name'       => sanitize_text_field( (string) $connection['from_name'] ),
				'has_credentials' => (bool) $connection['has_credentials'],
				'has_mcp_bridge'  => (bool) $connection['has_mcp_bridge'],
			);
		}

		$total = count( $safe );

		return array(
			'success'                   => true,
			'message'                   => 0 === $total
				? __( 'No Mailjet connections are assigned to this assistant.', 'mcp-ai-wpoos-pro' )
				: sprintf(
					/* translators: %d: number of connections. */
					_n( '%d Mailjet connection is available.', '%d Mailjet connections are available.', $total, 'mcp-ai-wpoos-pro' ),
					$total
				),
			'total'                     => $total,
			'connections'               => $safe,
			'settings_fallback_allowed' => WP_MCP_AI_Pro_Mailjet_Credentials::is_settings_fallback_allowed( $context ),
		);
	}
}
