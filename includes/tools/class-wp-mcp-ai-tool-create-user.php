<?php
/**
 * Tool: create_user — Creates a new WordPress user.
 *
 * Port of mcp-wordpress wp_create_user tool.
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
 * Creates a new WordPress user with optional profile fields and roles.
 */
class WP_MCP_AI_Tool_Create_User implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_user';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create User', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new WordPress user with optional profile fields, roles, and welcome notification.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Creating a new WordPress user account with profile fields and optional roles.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Editing an existing account; use update_user instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'list_users', 'get_user_info', 'update_user', 'delete_user' ),
			'notes'           => __( 'Returns user_id for chaining into update_user. Assigning custom roles requires the promote_users capability; otherwise the site default role is used. Passwords are never echoed back in the response.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'username'          => array(
					'type'        => 'string',
					'description' => __( 'The login name for the new user.', 'mcp-ai-wpoos' ),
				),
				'email'             => array(
					'type'        => 'string',
					'description' => __( 'The email address for the new user. Must not already be in use.', 'mcp-ai-wpoos' ),
				),
				'password'          => array(
					'type'        => 'string',
					'description' => __( 'The password for the new user. Never returned in the response.', 'mcp-ai-wpoos' ),
				),
				'roles'             => array(
					'type'        => 'array',
					'description' => __( 'Optional: role slugs to assign, e.g. ["editor"]. Requires the promote_users capability.', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'first_name'        => array(
					'type'        => 'string',
					'description' => __( 'Optional: first name of the new user.', 'mcp-ai-wpoos' ),
				),
				'last_name'         => array(
					'type'        => 'string',
					'description' => __( 'Optional: last name of the new user.', 'mcp-ai-wpoos' ),
				),
				'display_name'      => array(
					'type'        => 'string',
					'description' => __( 'Optional: display name of the new user.', 'mcp-ai-wpoos' ),
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'Optional: biographical description of the new user.', 'mcp-ai-wpoos' ),
				),
				'send_notification' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, sends the new user a welcome notification email.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'             => array( 'username', 'email', 'password' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => 'user_id',
			'consumes' => null,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'create_users';
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to create users.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'create_users' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create users.', 'mcp-ai-wpoos' ) );
		}

		$username = isset( $arguments['username'] ) ? sanitize_user( $arguments['username'], true ) : '';
		$email    = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';
		$password = isset( $arguments['password'] ) ? (string) $arguments['password'] : '';

		if ( '' === $username ) {
			return new WP_Error( 'wp_mcp_ai_invalid_username', __( 'A valid username is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! validate_username( $username ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_username', __( 'The username contains invalid characters.', 'mcp-ai-wpoos' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_email', __( 'A valid email address is required.', 'mcp-ai-wpoos' ) );
		}

		if ( '' === $password ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'password is required.', 'mcp-ai-wpoos' ) );
		}

		if ( username_exists( $username ) ) {
			return new WP_Error( 'wp_mcp_ai_username_exists', __( 'That username is already in use.', 'mcp-ai-wpoos' ) );
		}

		if ( email_exists( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_email_exists', __( 'That email address is already in use.', 'mcp-ai-wpoos' ) );
		}

		// Resolve requested roles. Roles are only honored when the acting user
		// may promote users; invalid role slugs are skipped.
		$can_promote = user_can( $acting_user_id, 'promote_users' );
		$valid_roles = array();

		if ( $can_promote && isset( $arguments['roles'] ) && is_array( $arguments['roles'] ) ) {
			$wp_roles = wp_roles();
			foreach ( $arguments['roles'] as $role ) {
				$role = sanitize_key( $role );
				if ( $wp_roles->is_role( $role ) && ! in_array( $role, $valid_roles, true ) ) {
					$valid_roles[] = $role;
				}
			}
		}

		$first_role   = ! empty( $valid_roles ) ? $valid_roles[0] : get_option( 'default_role', 'subscriber' );
		$extra_roles  = array_slice( $valid_roles, 1 );
		$roles_wanted = ( isset( $arguments['roles'] ) && is_array( $arguments['roles'] ) && ! empty( $arguments['roles'] ) );

		$userdata = array(
			'user_login' => $username,
			'user_email' => $email,
			'user_pass'  => $password,
			'role'       => $first_role,
		);

		if ( isset( $arguments['first_name'] ) && '' !== $arguments['first_name'] ) {
			$userdata['first_name'] = sanitize_text_field( $arguments['first_name'] );
		}

		if ( isset( $arguments['last_name'] ) && '' !== $arguments['last_name'] ) {
			$userdata['last_name'] = sanitize_text_field( $arguments['last_name'] );
		}

		if ( isset( $arguments['display_name'] ) && '' !== $arguments['display_name'] ) {
			$userdata['display_name'] = sanitize_text_field( $arguments['display_name'] );
		}

		if ( isset( $arguments['description'] ) && '' !== $arguments['description'] ) {
			$userdata['description'] = sanitize_textarea_field( $arguments['description'] );
		}

		$user_id = wp_insert_user( $userdata );

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_user_create_failed',
				__( 'The user could not be created.', 'mcp-ai-wpoos' ),
				array( 'reason' => $user_id->get_error_message() )
			);
		}

		// Apply any additional valid roles beyond the first one.
		$user = get_userdata( $user_id );
		if ( $user && ! empty( $extra_roles ) ) {
			foreach ( $extra_roles as $extra_role ) {
				$user->add_role( $extra_role );
			}
			$user = get_userdata( $user_id );
		}

		$send_notification = isset( $arguments['send_notification'] ) ? (bool) $arguments['send_notification'] : false;
		if ( $send_notification ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}

		$final_roles = $user ? array_values( $user->roles ) : array( $first_role );

		$message = sprintf(
			/* translators: 1: username, 2: user ID */
			__( 'User %1$s created (ID: %2$d).', 'mcp-ai-wpoos' ),
			esc_html( $username ),
			absint( $user_id )
		);

		if ( $send_notification ) {
			$message .= ' ' . __( 'A welcome notification was sent to the user.', 'mcp-ai-wpoos' );
		}

		if ( $roles_wanted && ! $can_promote ) {
			$message .= ' ' . __( 'Requested roles were not applied because you do not have permission to assign roles; the site default role was used.', 'mcp-ai-wpoos' );
		}

		return array(
			'message'  => $message,
			'user_id'  => absint( $user_id ),
			'username' => esc_html( $username ),
			'email'    => esc_html( $email ),
			'roles'    => $final_roles,
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
			'write',                  // Creates a user account.
			'local-only',             // No external API calls.
			'requires-capability',    // Requires the create_users capability.
			'state-changing',         // Modifies database state.
			'access-control-change',  // May assign roles and access rights.
		);
	}
}
