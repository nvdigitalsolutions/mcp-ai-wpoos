<?php
/**
 * Tool: delete_application_password — Revokes a WordPress application password.
 *
 * Port of mcp-wordpress wp_delete_application_password tool.
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
 * Revokes an existing application password by UUID.
 *
 * Revocation is immediate: once deleted, requests authenticating with that
 * password start failing with 401 responses.
 */
class WP_MCP_AI_Tool_Delete_Application_Password implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_application_password';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Application Password', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Revokes an existing WordPress application password by its UUID. Requests using the revoked password stop working immediately.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Revoking a compromised, lost, or obsolete application credential.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Rotating a still-needed credential; create the replacement first, then revoke the old one.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'list_application_passwords', 'create_application_password' ),
			'notes'           => __( 'Users can revoke their own passwords; revoking another user\'s requires manage_options. Obtain the UUID from list_application_passwords.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'uuid'    => array(
					'type'        => 'string',
					'description' => __( 'The UUID of the application password to revoke (from list_application_passwords).', 'mcp-ai-wpoos' ),
					'minLength'   => 1,
				),
				'user_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the user who owns the password. Defaults to the current user.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'uuid' ),
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
			'write',
			'local-only',
			'requires-capability',
			'state-changing',
			'access-control-change',
			'reversible',
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
			'risk_level'            => 'standard',
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to revoke application passwords.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$target_user_id = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : $acting_user_id;

		if ( $target_user_id !== $acting_user_id && ! user_can( $acting_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to revoke another user\'s application passwords.', 'mcp-ai-wpoos' ) );
		}

		$uuid = isset( $arguments['uuid'] ) ? sanitize_text_field( $arguments['uuid'] ) : '';

		if ( '' === $uuid ) {
			return new WP_Error( 'wp_mcp_ai_missing_uuid', __( 'The application password UUID is required.', 'mcp-ai-wpoos' ) );
		}

		$target_user = get_userdata( $target_user_id );
		if ( ! $target_user ) {
			return new WP_Error( 'wp_mcp_ai_user_not_found', __( 'The requested user could not be found.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_Error( 'wp_mcp_ai_application_passwords_unavailable', __( 'Application passwords are not supported on this WordPress installation.', 'mcp-ai-wpoos' ) );
		}

		$deleted = WP_Application_Passwords::delete_application_password( $target_user_id, $uuid );

		if ( ! $deleted ) {
			return new WP_Error( 'wp_mcp_ai_application_password_not_found', __( 'No application password with that UUID exists for the requested user.', 'mcp-ai-wpoos' ) );
		}

		return array(
			'message' => sprintf(
				/* translators: 1: user login, 2: uuid */
				__( 'Application password %2$s for %1$s has been revoked.', 'mcp-ai-wpoos' ),
				esc_html( $target_user->user_login ),
				esc_html( $uuid )
			),
			'user_id' => $target_user_id,
			'uuid'    => $uuid,
			'revoked' => true,
		);
	}
}
