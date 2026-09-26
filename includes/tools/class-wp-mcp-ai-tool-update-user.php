<?php
/**
 * Tool: update_user — Updates an existing WordPress user.
 *
 * Port of mcp-wordpress wp_update_user tool.
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
 * Updates an existing WordPress user's profile fields, password, or roles.
 */
class WP_MCP_AI_Tool_Update_User implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_user';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update User', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing WordPress user\'s profile fields, password, or roles.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Updating profile fields, password, or roles for a known user ID.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Creating a new account; use create_user. Removing an account; use delete_user.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'list_users', 'get_user_info', 'create_user', 'delete_user' ),
			'notes'           => __( 'user_id comes from list_users or create_user. You may always edit your own profile; editing another user requires the edit_users capability and changing roles requires promote_users. Passwords are never echoed back in the response.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id'      => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the user to update.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'email'        => array(
					'type'        => 'string',
					'description' => __( 'Optional: new email address. Must not belong to another user.', 'mcp-ai-wpoos' ),
				),
				'password'     => array(
					'type'        => 'string',
					'description' => __( 'Optional: new password. Never returned in the response.', 'mcp-ai-wpoos' ),
				),
				'display_name' => array(
					'type'        => 'string',
					'description' => __( 'Optional: new display name.', 'mcp-ai-wpoos' ),
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'Optional: new biographical description.', 'mcp-ai-wpoos' ),
				),
				'first_name'   => array(
					'type'        => 'string',
					'description' => __( 'Optional: new first name.', 'mcp-ai-wpoos' ),
				),
				'last_name'    => array(
					'type'        => 'string',
					'description' => __( 'Optional: new last name.', 'mcp-ai-wpoos' ),
				),
				'roles'        => array(
					'type'        => 'array',
					'description' => __( 'Optional: replacement role slugs, e.g. ["editor"]. Requires the promote_users capability.', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'user_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'user_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_users';
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to update users.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$user_id = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : 0;

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'user_id is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'wp_mcp_ai_user_not_found', __( 'The requested user could not be found.', 'mcp-ai-wpoos' ) );
		}

		// Users may always edit their own profile; editing another user requires edit_users.
		if ( $user_id !== $acting_user_id && ! user_can( $acting_user_id, 'edit_users' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit other users.', 'mcp-ai-wpoos' ) );
		}

		$email        = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';
		$password     = isset( $arguments['password'] ) ? (string) $arguments['password'] : '';
		$display_name = isset( $arguments['display_name'] ) ? sanitize_text_field( $arguments['display_name'] ) : '';
		$description  = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$first_name   = isset( $arguments['first_name'] ) ? sanitize_text_field( $arguments['first_name'] ) : '';
		$last_name    = isset( $arguments['last_name'] ) ? sanitize_text_field( $arguments['last_name'] ) : '';
		$roles_raw    = isset( $arguments['roles'] ) ? (array) $arguments['roles'] : array();

		if (
			'' === $email &&
			'' === $password &&
			'' === $display_name &&
			'' === $description &&
			'' === $first_name &&
			'' === $last_name &&
			empty( $roles_raw )
		) {
			return new WP_Error( 'wp_mcp_ai_no_updates', __( 'Provide at least one field to update.', 'mcp-ai-wpoos' ) );
		}

		if ( '' !== $email ) {
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_email', __( 'A valid email address is required.', 'mcp-ai-wpoos' ) );
			}

			$email_owner = email_exists( $email );
			if ( $email_owner && absint( $email_owner ) !== $user_id ) {
				return new WP_Error( 'wp_mcp_ai_email_exists', __( 'That email address is already in use by another user.', 'mcp-ai-wpoos' ) );
			}
		}

		// Resolve replacement roles. Changing roles requires promote_users.
		$can_promote = user_can( $acting_user_id, 'promote_users' );
		$valid_roles = array();

		if ( ! empty( $roles_raw ) ) {
			if ( ! $can_promote ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to change user roles.', 'mcp-ai-wpoos' ) );
			}

			$wp_roles = wp_roles();
			foreach ( $roles_raw as $role ) {
				$role = sanitize_key( $role );
				if ( $wp_roles->is_role( $role ) && ! in_array( $role, $valid_roles, true ) ) {
					$valid_roles[] = $role;
				}
			}
		}

		$userdata = array( 'ID' => $user_id );

		if ( '' !== $email ) {
			$userdata['user_email'] = $email;
		}

		if ( '' !== $password ) {
			$userdata['user_pass'] = $password;
		}

		if ( '' !== $display_name ) {
			$userdata['display_name'] = $display_name;
		}

		if ( '' !== $description ) {
			$userdata['description'] = $description;
		}

		if ( '' !== $first_name ) {
			$userdata['first_name'] = $first_name;
		}

		if ( '' !== $last_name ) {
			$userdata['last_name'] = $last_name;
		}

		if ( count( $userdata ) > 1 ) {
			$result = wp_update_user( $userdata );

			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'wp_mcp_ai_user_update_failed',
					__( 'The user could not be updated.', 'mcp-ai-wpoos' ),
					array( 'reason' => $result->get_error_message() )
				);
			}
		}

		// Apply replacement roles after the profile update.
		$user = get_userdata( $user_id );
		if ( $user && ! empty( $valid_roles ) ) {
			$user->set_role( $valid_roles[0] );
			foreach ( array_slice( $valid_roles, 1 ) as $extra_role ) {
				$user->add_role( $extra_role );
			}
			$user = get_userdata( $user_id );
		}

		$message = sprintf(
			/* translators: 1: username, 2: user ID */
			__( 'User %1$s updated (ID: %2$d).', 'mcp-ai-wpoos' ),
			$user ? esc_html( $user->user_login ) : $user_id,
			$user_id
		);

		return array(
			'message'      => $message,
			'user_id'      => $user_id,
			'username'     => $user ? esc_html( $user->user_login ) : '',
			'email'        => $user ? esc_html( $user->user_email ) : '',
			'display_name' => $user ? esc_html( $user->display_name ) : '',
			'roles'        => $user ? array_values( $user->roles ) : array(),
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
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
	public function get_capability_flags() {
		return array(
			'write',                  // Modifies user profile data.
			'local-only',             // No external API calls.
			'requires-capability',    // Requires edit_users (or self-edit) capability.
			'state-changing',         // Modifies database state.
			'access-control-change',  // May change roles and access rights.
		);
	}
}
