<?php
/**
 * Tool: list_users — Lists users with optional filters.
 *
 * Port of mcp-wordpress wp_list_users tool.
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
 * Lists WordPress users with optional search and role filters.
 */
class WP_MCP_AI_Tool_List_Users implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_users';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Users', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists WordPress users with optional search and role filters. Email addresses and roles are only included for users with the list_users capability.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Browsing users, optionally filtered by a search term or by role.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Fetching a single profile or auditing a user activity trail; use get_user_info or user_activity_auditor instead.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_user_info', 'user_activity_auditor' ),
			'notes'           => __( 'roles accepts an array of role slugs, e.g. ["administrator", "editor"]. Requires the list_users capability.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search'   => array(
					'type'        => 'string',
					'description' => __( 'Optional: only list users matching this search term.', 'mcp-ai-wpoos' ),
				),
				'roles'    => array(
					'type'        => 'array',
					'description' => __( 'Optional: only list users with one of these role slugs.', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of users per page (1-100).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number to retrieve.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'default'     => 1,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'list_users';
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to list users.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'list_users' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list users.', 'mcp-ai-wpoos' ) );
		}

		$search   = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$per_page = min( 100, max( 1, $per_page ) );
		$page     = max( 1, $page );

		$roles = array();
		if ( isset( $arguments['roles'] ) && is_array( $arguments['roles'] ) ) {
			$roles = array_values( array_filter( array_map( 'sanitize_key', $arguments['roles'] ) ) );
		}

		$query_args = array(
			'number' => $per_page,
			'paged'  => $page,
		);

		if ( '' !== $search ) {
			$query_args['search'] = $search;
		}

		if ( ! empty( $roles ) ) {
			$query_args['role__in'] = $roles;
		}

		$user_query = new WP_User_Query( array_merge( $query_args, array( 'count_total' => true ) ) );
		$users      = $user_query->get_results();
		$total      = absint( $user_query->get_total() );

		$users_list = array();
		foreach ( $users as $user ) {
			$author_url = get_author_posts_url( $user->ID );

			$entry = array(
				'id'           => absint( $user->ID ),
				'username'     => esc_html( $user->user_login ),
				'display_name' => esc_html( $user->display_name ),
				'email'        => esc_html( $user->user_email ),
				'roles'        => array_values( $user->roles ),
				'registered'   => $user->user_registered,
			);

			if ( ! empty( $author_url ) ) {
				$entry['url'] = esc_url_raw( $author_url );
			}

			$users_list[] = $entry;
		}

		if ( 0 === $total ) {
			$message = __( 'No users matched the requested filters.', 'mcp-ai-wpoos' );
		} else {
			$message = sprintf(
				/* translators: 1: number of users returned, 2: total number of matching users */
				__( 'Returned %1$d user(s) of %2$d total.', 'mcp-ai-wpoos' ),
				count( $users_list ),
				$total
			);
		}

		return array(
			'message'  => $message,
			'users'    => $users_list,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
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
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires the list_users capability.
		);
	}
}
