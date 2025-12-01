<?php
/**
 * Users Tool - Operations for WordPress users.
 *
 *
 * @package WP_MCP_AI_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for WordPress user operations.
 *
 * Provides read access to WordPress users including:
 * - Getting user info
 * - Listing users
 * - Getting current user
 *
 * Write operations are restricted to users with appropriate capabilities.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Core_Tool_Users implements WP_MCP_AI_Core_Tool_Interface, WP_MCP_AI_Core_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'users';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Users', 'wp-mcp-ai-core' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Query WordPress users. Supports getting user details by ID, listing users by role, and getting current user information.', 'wp-mcp-ai-core' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'   => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: get, list, current, search.', 'wp-mcp-ai-core' ),
					'enum'        => array( 'get', 'list', 'current', 'search' ),
					'default'     => 'current',
				),
				'user_id'  => array(
					'type'        => 'integer',
					'description' => __( 'User ID for get action.', 'wp-mcp-ai-core' ),
				),
				'role'     => array(
					'type'        => 'string',
					'description' => __( 'Filter users by role (e.g., administrator, editor, author).', 'wp-mcp-ai-core' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of users to return. Default: 10. Max: 100.', 'wp-mcp-ai-core' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'wp-mcp-ai-core' ),
					'default'     => 1,
				),
				'search'   => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter users.', 'wp-mcp-ai-core' ),
				),
				'orderby'  => array(
					'type'        => 'string',
					'description' => __( 'Field to order by. Default: display_name.', 'wp-mcp-ai-core' ),
					'enum'        => array( 'ID', 'display_name', 'login', 'email', 'registered' ),
					'default'     => 'display_name',
				),
				'order'    => array(
					'type'        => 'string',
					'description' => __( 'Order direction. Default: ASC.', 'wp-mcp-ai-core' ),
					'enum'        => array( 'ASC', 'DESC' ),
					'default'     => 'ASC',
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'read-only',    // Only read operations.
			'local-only',   // No external API calls.
			'pii-data',     // Returns user data.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'current';

		switch ( $action ) {
			case 'get':
				return $this->get_user( $arguments, $context );
			case 'list':
				return $this->list_users( $arguments, $context );
			case 'current':
				return $this->get_current_user( $context );
			case 'search':
				return $this->search_users( $arguments, $context );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'wp-mcp-ai-core' )
				);
		}
	}

	/**
	 * Get a single user by ID.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function get_user( $arguments, $context ) {
		if ( empty( $arguments['user_id'] ) ) {
			return new WP_Error(
				'missing_user_id',
				__( 'User ID is required for get action.', 'wp-mcp-ai-core' )
			);
		}

		$requesting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$target_user_id     = absint( $arguments['user_id'] );

		// Users can always see their own info.
		// For other users, require list_users capability.
		if ( $requesting_user_id !== $target_user_id && ! user_can( $requesting_user_id, 'list_users' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to view this user.', 'wp-mcp-ai-core' )
			);
		}

		$user = get_userdata( $target_user_id );

		if ( ! $user ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'wp-mcp-ai-core' )
			);
		}

		return $this->format_user( $user, $requesting_user_id === $target_user_id );
	}

	/**
	 * Get the current user.
	 *
	 * @param array $context Execution context.
	 * @return array|WP_Error
	 */
	protected function get_current_user( $context ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error(
				'not_logged_in',
				__( 'No user is currently logged in.', 'wp-mcp-ai-core' )
			);
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'wp-mcp-ai-core' )
			);
		}

		return $this->format_user( $user, true );
	}

	/**
	 * List users.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function list_users( $arguments, $context ) {
		$requesting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Require list_users capability.
		if ( ! user_can( $requesting_user_id, 'list_users' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to list users.', 'wp-mcp-ai-core' )
			);
		}

		$query_args = array(
			'number'  => isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10,
			'paged'   => isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1,
			'orderby' => isset( $arguments['orderby'] ) ? sanitize_key( $arguments['orderby'] ) : 'display_name',
			'order'   => isset( $arguments['order'] ) ? strtoupper( sanitize_key( $arguments['order'] ) ) : 'ASC',
		);

		if ( ! empty( $arguments['role'] ) ) {
			$query_args['role'] = sanitize_key( $arguments['role'] );
		}

		if ( ! empty( $arguments['search'] ) ) {
			$query_args['search']         = '*' . sanitize_text_field( $arguments['search'] ) . '*';
			$query_args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
		}

		$query = new WP_User_Query( $query_args );

		$users = array();
		foreach ( $query->get_results() as $user ) {
			$users[] = $this->format_user( $user, false );
		}

		return array(
			'users'       => $users,
			'total'       => $query->get_total(),
			'total_pages' => ceil( $query->get_total() / $query_args['number'] ),
			'page'        => $query_args['paged'],
		);
	}

	/**
	 * Search users.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	protected function search_users( $arguments, $context ) {
		if ( empty( $arguments['search'] ) ) {
			return new WP_Error(
				'missing_search_term',
				__( 'Search term is required for search action.', 'wp-mcp-ai-core' )
			);
		}

		return $this->list_users( $arguments, $context );
	}

	/**
	 * Format a user for output.
	 *
	 * @param WP_User $user    User object.
	 * @param bool    $is_self Whether this is the requesting user's own info.
	 * @return array
	 */
	protected function format_user( $user, $is_self = false ) {
		$data = array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'roles'        => $user->roles,
			'registered'   => $user->user_registered,
			'avatar_url'   => get_avatar_url( $user->ID ),
			'url'          => $user->user_url,
			'description'  => $user->description,
		);

		// Only include email for the user's own info or if current user can edit users.
		if ( $is_self || current_user_can( 'edit_users' ) ) {
			$data['email'] = $user->user_email;
		}

		// Include capabilities for the user's own info.
		if ( $is_self ) {
			$data['capabilities'] = array_keys( array_filter( $user->allcaps ) );
		}

		return $data;
	}
}
