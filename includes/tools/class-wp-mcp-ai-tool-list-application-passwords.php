<?php
/**
 * Tool: list_application_passwords — Lists a user's WordPress application passwords.
 *
 * Port of mcp-wordpress wp_get_application_passwords tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the application passwords registered for a WordPress user.
 *
 * Only metadata is returned (name, UUID, created/last-used timestamps);
 * the one-time password values are never stored by WordPress and therefore
 * can never be listed.
 */
class WP_MCP_AI_Tool_List_Application_Passwords implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_application_passwords';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Application Passwords', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists the application passwords registered for a WordPress user (name, UUID, created and last-used dates). The one-time password values themselves are never recoverable.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Reviewing which external applications hold credentials for a user before rotating or revoking them.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Recovering a lost password value; WordPress never stores it. Create a replacement with create_application_password instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'create_application_password', 'delete_application_password', 'list_users' ),
			'notes'           => __( 'Users can list their own passwords; viewing another user requires manage_options. The raw password is never included in results.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the user whose application passwords to list. Defaults to the current user.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
			),
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
			'requires-capability',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'security_compliance',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'systems_administrator', 'security_analyst' ),
			'risk_level'            => 'info',
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
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to list application passwords.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$target_user_id = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : $acting_user_id;

		if ( $target_user_id !== $acting_user_id && ! user_can( $acting_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list another user\'s application passwords.', 'mcp-ai-wpoos' ) );
		}

		$target_user = get_userdata( $target_user_id );
		if ( ! $target_user ) {
			return new WP_Error( 'wp_mcp_ai_user_not_found', __( 'The requested user could not be found.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_Error( 'wp_mcp_ai_application_passwords_unavailable', __( 'Application passwords are not supported on this WordPress installation.', 'mcp-ai-wpoos' ) );
		}

		$items = WP_Application_Passwords::get_user_application_passwords( $target_user_id );

		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$passwords = array();

		foreach ( $items as $item ) {
			$passwords[] = array(
				'uuid'      => sanitize_text_field( $item['uuid'] ),
				'name'      => sanitize_text_field( $item['name'] ),
				'created'   => isset( $item['created'] ) ? absint( $item['created'] ) : 0,
				'last_used' => isset( $item['last_used'] ) ? absint( $item['last_used'] ) : null,
				'last_ip'   => isset( $item['last_ip'] ) ? sanitize_text_field( $item['last_ip'] ) : null,
			);
		}

		return array(
			'message'   => sprintf(
				/* translators: 1: user login, 2: number of passwords */
				__( 'Found %2$d application password(s) for %1$s.', 'mcp-ai-wpoos' ),
				esc_html( $target_user->user_login ),
				count( $passwords )
			),
			'user_id'   => $target_user_id,
			'passwords' => $passwords,
		);
	}
}
