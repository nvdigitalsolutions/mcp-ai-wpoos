<?php
/**
 * Tool that modifies Gmail message labels.
 *
 * Supports adding/removing labels, marking read/unread (UNREAD label), and
 * archiving (INBOX label removal). Deliberately excludes trash/delete: those
 * are irreversible and belong behind a separate, stricter gate.
 *
 * State-changing by design: flagged "write" + "state-changing" so the
 * WP_MCP_AI_Destructive_Ops_Gate requires an explicit confirm_destructive=true
 * whenever the require_confirm_destructive_ops setting is enabled (default).
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
 * Provides an assistant tool for modifying Gmail message labels.
 */
class WP_MCP_AI_Pro_Tool_Modify_Gmail_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'modify_gmail_message';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Modify Gmail Message', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Modifies labels on a Gmail message: add or remove labels, mark read or unread, or archive. Never deletes messages. This is a state-changing operation — the site may require an explicit confirm_destructive=true argument before it executes.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'message_id'          => array(
					'type'        => 'string',
					'description' => __( 'Gmail message ID to modify. Obtain IDs from search_gmail results.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id'       => array(
					'type'        => 'string',
					'description' => __( 'Optional Gmail connection ID from Remote Sites. If not provided, uses settings-based credentials.', 'mcp-ai-wpoos-pro' ),
				),
				'add_label_ids'       => array(
					'type'        => 'array',
					'description' => __( 'Optional label IDs to add (e.g. "IMPORTANT" or "Label_123").', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'remove_label_ids'    => array(
					'type'        => 'array',
					'description' => __( 'Optional label IDs to remove (e.g. "CATEGORY_PROMOTIONS" or "Label_123").', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'mark_read'           => array(
					'type'        => 'boolean',
					'description' => __( 'When true, marks the message as read (removes the UNREAD label).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'mark_unread'         => array(
					'type'        => 'boolean',
					'description' => __( 'When true, marks the message as unread (adds the UNREAD label). Mutually exclusive with mark_read.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'archive'             => array(
					'type'        => 'boolean',
					'description' => __( 'When true, archives the message (removes the INBOX label).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'confirm_destructive' => array(
					'type'        => 'boolean',
					'description' => __( 'Set to true to explicitly confirm this state-changing operation. Required when destructive-op confirmation is enabled on the site.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'message_id' ),
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

		$required_capability = apply_filters( 'wp_mcp_ai_modify_gmail_message_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_gmail_forbidden', __( 'You do not have permission to modify Gmail messages.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_gmail_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Gate 1 — sanitise at entry.
		$message_id    = isset( $arguments['message_id'] ) ? sanitize_text_field( $arguments['message_id'] ) : '';
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$mark_read     = ! empty( $arguments['mark_read'] );
		$mark_unread   = ! empty( $arguments['mark_unread'] );
		$archive       = ! empty( $arguments['archive'] );

		$add_label_ids    = $this->sanitize_label_ids( $arguments, 'add_label_ids' );
		$remove_label_ids = $this->sanitize_label_ids( $arguments, 'remove_label_ids' );

		if ( '' === $message_id ) {
			return new WP_Error( 'wp_mcp_ai_gmail_missing_message_id', __( 'A Gmail message ID is required. Obtain IDs from search_gmail results.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $mark_read && $mark_unread ) {
			return new WP_Error( 'wp_mcp_ai_gmail_modify_conflict', __( 'mark_read and mark_unread are mutually exclusive.', 'mcp-ai-wpoos-pro' ) );
		}

		// Map convenience flags onto Gmail label semantics.
		if ( $mark_unread ) {
			$add_label_ids[] = 'UNREAD';
		}
		if ( $mark_read ) {
			$remove_label_ids[] = 'UNREAD';
		}
		if ( $archive ) {
			$remove_label_ids[] = 'INBOX';
		}

		$add_label_ids    = array_values( array_unique( $add_label_ids ) );
		$remove_label_ids = array_values( array_unique( $remove_label_ids ) );

		if ( empty( $add_label_ids ) && empty( $remove_label_ids ) ) {
			return new WP_Error( 'wp_mcp_ai_gmail_modify_nothing_to_do', __( 'No label operations were requested. Provide add_label_ids, remove_label_ids, mark_read, mark_unread, or archive.', 'mcp-ai-wpoos-pro' ) );
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
			'POST',
			$gmail_user,
			'messages/' . rawurlencode( $message_id ) . '/modify',
			$access_token,
			$timeout,
			array(),
			array(
				'addLabelIds'    => $add_label_ids,
				'removeLabelIds' => $remove_label_ids,
			)
		);
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$data = array(
			'id'        => isset( $payload['id'] ) ? (string) $payload['id'] : $message_id,
			'thread_id' => isset( $payload['threadId'] ) ? (string) $payload['threadId'] : '',
			'label_ids' => isset( $payload['labelIds'] ) && is_array( $payload['labelIds'] ) ? array_values( array_map( 'strval', $payload['labelIds'] ) ) : array(),
			'applied'   => array(
				'add_label_ids'    => $add_label_ids,
				'remove_label_ids' => $remove_label_ids,
			),
		);

		return $this->format_success_response(
			__( 'Gmail message updated.', 'mcp-ai-wpoos-pro' ),
			array( 'data' => $data )
		);
	}

	/**
	 * Sanitise an array of label IDs from tool arguments.
	 *
	 * @param array  $arguments Tool arguments.
	 * @param string $key       Argument key to sanitise.
	 * @return array Sanitised label IDs (empty when absent).
	 */
	private function sanitize_label_ids( $arguments, $key ) {
		$labels = array();

		if ( empty( $arguments[ $key ] ) || ! is_array( $arguments[ $key ] ) ) {
			return $labels;
		}

		foreach ( $arguments[ $key ] as $label ) {
			$label = sanitize_text_field( (string) $label );
			if ( '' !== $label && strlen( $label ) <= 100 ) {
				$labels[] = $label;
			}
		}

		return $labels;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Creates or modifies remote data.
			'state-changing',       // Modifies message labels on the Gmail side.
			'external-api',         // Calls the Gmail API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
			'pii-data',             // Operates on personal mailbox data.
		);
	}
}
