<?php
/**
 * Tool: create_application_password — Creates a new WordPress application password.
 *
 * Port of mcp-wordpress wp_create_application_password tool.
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
 * Creates a new application password for a WordPress user.
 *
 * The generated password is shown exactly once in the tool result (and is
 * declared as a sensitive result field so it is masked in logs); WordPress
 * stores only its hash, so a lost password cannot be recovered.
 */
class WP_MCP_AI_Tool_Create_Application_Password implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Sensitive_Result_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_application_password';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Application Password', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new application password for a WordPress user (for REST API authentication). The password is shown exactly once and cannot be recovered later.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Issuing a new credential for an external MCP server, CLI tool, or integration that authenticates against this site.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Rotating an existing credential in place; revoke the old one with delete_application_password and create a fresh one.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'list_application_passwords', 'delete_application_passwords', 'list_users' ),
			'notes'           => __( 'Users can create passwords for themselves; creating for another user requires manage_options. Store the returned value immediately — it is never shown again.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'    => array(
					'type'        => 'string',
					'description' => __( 'The human-readable name of the application this password is for (e.g. "mcp-wordpress").', 'mcp-ai-wpoos' ),
					'minLength'   => 1,
					'maxLength'   => 255,
				),
				'user_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the user to create the password for. Defaults to the current user; another user requires manage_options.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'name' ),
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
	 * {@inheritdoc}
	 */
	public function get_sensitive_result_fields() {
		return array( 'password' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to create application passwords.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$target_user_id = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : $acting_user_id;

		if ( $target_user_id !== $acting_user_id && ! user_can( $acting_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create application passwords for another user.', 'mcp-ai-wpoos' ) );
		}

		$name = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'An application name is required.', 'mcp-ai-wpoos' ) );
		}

		$target_user = get_userdata( $target_user_id );
		if ( ! $target_user ) {
			return new WP_Error( 'wp_mcp_ai_user_not_found', __( 'The requested user could not be found.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return new WP_Error( 'wp_mcp_ai_application_passwords_unavailable', __( 'Application passwords are not supported on this WordPress installation.', 'mcp-ai-wpoos' ) );
		}

		if ( ! wp_is_application_passwords_available_for_user( $target_user_id ) ) {
			return new WP_Error( 'wp_mcp_ai_application_passwords_disabled', __( 'Application passwords are disabled for this user (typically requires HTTPS or the wp_is_application_passwords_available filter).', 'mcp-ai-wpoos' ) );
		}

		$result = WP_Application_Passwords::create_new_application_password( $target_user_id, array( 'name' => $name ) );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'wp_mcp_ai_application_password_create_failed', $result->get_error_message() );
		}

		if ( ! is_array( $result ) || ! isset( $result[0] ) ) {
			return new WP_Error( 'wp_mcp_ai_application_password_create_failed', __( 'WordPress did not return a new password.', 'mcp-ai-wpoos' ) );
		}

		$new_password = (string) $result[0];

		return array(
			'message'  => __( 'Application password created. Store it securely — it is shown only once and cannot be recovered.', 'mcp-ai-wpoos' ),
			'user_id'  => $target_user_id,
			'name'     => $name,
			'password' => $new_password,
		);
	}
}
