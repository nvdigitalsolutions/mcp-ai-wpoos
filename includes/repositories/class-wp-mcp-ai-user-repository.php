<?php
/**
 * User Repository
 *
 * Data access layer for WordPress users.
 * Part of completing the Repository Pattern (Priority 2 from Architecture Review).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User Repository Class
 *
 * Provides abstraction layer for user CRUD operations.
 * Isolates WordPress user API from business logic.
 *
 * Benefits:
 * - Single source of truth for user operations
 * - Easier to test (can mock repository)
 * - Consistent data sanitization and validation
 * - Easier to swap storage backend if needed
 * - Centralizes user-related queries
 *
 * @since 1.0.0
 */
class WP_MCP_AI_User_Repository {

	/**
	 * Find a user by ID.
	 *
	 * @param int $user_id User ID.
	 * @return WP_User|false User object on success, false on failure.
	 */
	public function find( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );

		return $user;
	}

	/**
	 * Find a user by email.
	 *
	 * @param string $email User email address.
	 * @return WP_User|false User object on success, false on failure.
	 */
	public function find_by_email( $email ) {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return false;
		}

		return get_user_by( 'email', $email );
	}

	/**
	 * Find a user by username.
	 *
	 * @param string $username Username.
	 * @return WP_User|false User object on success, false on failure.
	 */
	public function find_by_username( $username ) {
		$username = sanitize_user( $username );

		if ( ! $username ) {
			return false;
		}

		return get_user_by( 'login', $username );
	}

	/**
	 * Find a user by slug.
	 *
	 * @param string $slug User slug (nicename).
	 * @return WP_User|false User object on success, false on failure.
	 */
	public function find_by_slug( $slug ) {
		$slug = sanitize_title( $slug );

		if ( ! $slug ) {
			return false;
		}

		return get_user_by( 'slug', $slug );
	}

	/**
	 * Find multiple users by IDs.
	 *
	 * @param array $user_ids Array of user IDs.
	 * @return WP_User[] Array of user objects, keyed by user ID.
	 */
	public function find_many( array $user_ids ) {
		$user_ids = array_filter( array_map( 'absint', $user_ids ) );

		if ( empty( $user_ids ) ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => $user_ids,
				'orderby' => 'include',
			)
		);

		// Re-key by user ID for easier lookup.
		$keyed = array();
		foreach ( $users as $user ) {
			$keyed[ $user->ID ] = $user;
		}

		return $keyed;
	}

	/**
	 * Query users with given criteria.
	 *
	 * @param array $args Query arguments. Supports all get_users() parameters.
	 * @return WP_User[] Array of user objects.
	 */
	public function query( array $args = array() ) {
		$defaults = array(
			'orderby' => 'display_name',
			'order'   => 'ASC',
			'number'  => 10,
		);

		$args = wp_parse_args( $args, $defaults );

		return get_users( $args );
	}

	/**
	 * Create a new user.
	 *
	 * @param array $user_data User data array.
	 * @return int|WP_Error User ID on success, WP_Error on failure.
	 */
	public function create( array $user_data ) {
		// Ensure required fields exist.
		if ( empty( $user_data['user_login'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_user_login',
				__( 'User login is required.', 'wp-mcp-ai' )
			);
		}

		if ( empty( $user_data['user_email'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_user_email',
				__( 'User email is required.', 'wp-mcp-ai' )
			);
		}

		// Sanitize common fields.
		$user_data['user_login'] = sanitize_user( $user_data['user_login'] );
		$user_data['user_email'] = sanitize_email( $user_data['user_email'] );

		if ( isset( $user_data['display_name'] ) ) {
			$user_data['display_name'] = sanitize_text_field( $user_data['display_name'] );
		}

		if ( isset( $user_data['first_name'] ) ) {
			$user_data['first_name'] = sanitize_text_field( $user_data['first_name'] );
		}

		if ( isset( $user_data['last_name'] ) ) {
			$user_data['last_name'] = sanitize_text_field( $user_data['last_name'] );
		}

		if ( isset( $user_data['role'] ) ) {
			$user_data['role'] = sanitize_key( $user_data['role'] );
		}

		// Validate email.
		if ( ! is_email( $user_data['user_email'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_email',
				__( 'Invalid email address.', 'wp-mcp-ai' )
			);
		}

		// Allow filtering before creation.
		$user_data = apply_filters( 'wp_mcp_ai_user_repository_before_create', $user_data );

		$user_id = wp_insert_user( $user_data );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Fire action after successful creation.
		do_action( 'wp_mcp_ai_user_repository_after_create', $user_id, $user_data );

		return $user_id;
	}

	/**
	 * Update an existing user.
	 *
	 * @param int   $user_id   User ID.
	 * @param array $user_data User data to update.
	 * @return int|WP_Error User ID on success, WP_Error on failure.
	 */
	public function update( $user_id, array $user_data ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_user_id',
				__( 'Invalid user ID provided.', 'wp-mcp-ai' )
			);
		}

		// Ensure user ID is set.
		$user_data['ID'] = $user_id;

		// Sanitize common fields.
		if ( isset( $user_data['user_login'] ) ) {
			$user_data['user_login'] = sanitize_user( $user_data['user_login'] );
		}

		if ( isset( $user_data['user_email'] ) ) {
			$user_data['user_email'] = sanitize_email( $user_data['user_email'] );
		}

		if ( isset( $user_data['display_name'] ) ) {
			$user_data['display_name'] = sanitize_text_field( $user_data['display_name'] );
		}

		if ( isset( $user_data['first_name'] ) ) {
			$user_data['first_name'] = sanitize_text_field( $user_data['first_name'] );
		}

		if ( isset( $user_data['last_name'] ) ) {
			$user_data['last_name'] = sanitize_text_field( $user_data['last_name'] );
		}

		if ( isset( $user_data['role'] ) ) {
			$user_data['role'] = sanitize_key( $user_data['role'] );
		}

		// Validate email if provided.
		if ( isset( $user_data['user_email'] ) && ! is_email( $user_data['user_email'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_email',
				__( 'Invalid email address.', 'wp-mcp-ai' )
			);
		}

		// Allow filtering before update.
		$user_data = apply_filters( 'wp_mcp_ai_user_repository_before_update', $user_data, $user_id );

		$result = wp_update_user( $user_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Fire action after successful update.
		do_action( 'wp_mcp_ai_user_repository_after_update', $user_id, $user_data );

		return $user_id;
	}

	/**
	 * Delete a user.
	 *
	 * @param int $user_id   User ID.
	 * @param int $reassign  Optional. Reassign posts to given user ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( $user_id, $reassign = null ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		// Don't allow deleting current user.
		if ( $user_id === get_current_user_id() ) {
			return false;
		}

		// Allow filtering before deletion.
		$should_delete = apply_filters( 'wp_mcp_ai_user_repository_before_delete', true, $user_id );

		if ( ! $should_delete ) {
			return false;
		}

		// Require multisite function if needed.
		if ( is_multisite() ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$result = wpmu_delete_user( $user_id );
		} else {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$result = wp_delete_user( $user_id, $reassign );
		}

		if ( $result ) {
			// Fire action after successful deletion.
			do_action( 'wp_mcp_ai_user_repository_after_delete', $user_id, $reassign );
		}

		return $result;
	}

	/**
	 * Check if a user exists.
	 *
	 * @param int $user_id User ID.
	 * @return bool Whether user exists.
	 */
	public function exists( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		return (bool) get_user_by( 'id', $user_id );
	}

	/**
	 * Get user meta value.
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed Meta value(s).
	 */
	public function get_meta( $user_id, $key, $single = true ) {
		$user_id = absint( $user_id );
		$key     = sanitize_key( $key );

		if ( ! $user_id || ! $key ) {
			return $single ? '' : array();
		}

		return get_user_meta( $user_id, $key, $single );
	}

	/**
	 * Update user meta value.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return int|bool Meta ID if the key didn't exist, true on successful update, false on failure.
	 */
	public function update_meta( $user_id, $meta_key, $meta_value ) {
		$user_id  = absint( $user_id );
		$meta_key = sanitize_key( $meta_key );

		if ( ! $user_id || ! $meta_key ) {
			return false;
		}

		return update_user_meta( $user_id, $meta_key, $meta_value );
	}

	/**
	 * Delete user meta value.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Optional. Meta value to delete. If provided, only delete meta with this value.
	 * @return bool True on success, false on failure.
	 */
	public function delete_meta( $user_id, $meta_key, $meta_value = '' ) {
		$user_id  = absint( $user_id );
		$meta_key = sanitize_key( $meta_key );

		if ( ! $user_id || ! $meta_key ) {
			return false;
		}

		return delete_user_meta( $user_id, $meta_key, $meta_value );
	}

	/**
	 * Get users by role.
	 *
	 * @param string $role Role name.
	 * @param array  $args Optional. Additional query arguments.
	 * @return WP_User[] Array of user objects.
	 */
	public function get_by_role( $role, array $args = array() ) {
		$role = sanitize_key( $role );

		if ( ! $role ) {
			return array();
		}

		$defaults = array(
			'role'   => $role,
			'number' => 10,
		);

		$args = wp_parse_args( $args, $defaults );

		return $this->query( $args );
	}

	/**
	 * Search users by login, email, or display name.
	 *
	 * @param string $search_term Search term.
	 * @param array  $args        Optional. Additional query arguments.
	 * @return WP_User[] Array of user objects.
	 */
	public function search( $search_term, array $args = array() ) {
		$search_term = sanitize_text_field( $search_term );

		if ( ! $search_term ) {
			return array();
		}

		$defaults = array(
			'search'         => '*' . $search_term . '*',
			'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
			'number'         => 10,
		);

		$args = wp_parse_args( $args, $defaults );

		return $this->query( $args );
	}

	/**
	 * Count users matching criteria.
	 *
	 * @param array $args Query arguments.
	 * @return int Number of users found.
	 */
	public function count( array $args = array() ) {
		$args['count_total'] = true;
		$args['fields']      = 'ID';

		$query = new WP_User_Query( $args );

		return (int) $query->get_total();
	}

	/**
	 * Check if username exists.
	 *
	 * @param string $username Username to check.
	 * @return bool Whether username exists.
	 */
	public function username_exists( $username ) {
		$username = sanitize_user( $username );

		if ( ! $username ) {
			return false;
		}

		return (bool) username_exists( $username );
	}

	/**
	 * Check if email exists.
	 *
	 * @param string $email Email to check.
	 * @return bool Whether email exists.
	 */
	public function email_exists( $email ) {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return false;
		}

		return (bool) email_exists( $email );
	}

	/**
	 * Add role to user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role name.
	 * @return bool True on success, false on failure.
	 */
	public function add_role( $user_id, $role ) {
		$user = $this->find( $user_id );

		if ( ! $user ) {
			return false;
		}

		$role = sanitize_key( $role );

		if ( ! $role ) {
			return false;
		}

		$user->add_role( $role );

		return true;
	}

	/**
	 * Remove role from user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role name.
	 * @return bool True on success, false on failure.
	 */
	public function remove_role( $user_id, $role ) {
		$user = $this->find( $user_id );

		if ( ! $user ) {
			return false;
		}

		$role = sanitize_key( $role );

		if ( ! $role ) {
			return false;
		}

		$user->remove_role( $role );

		return true;
	}

	/**
	 * Set user role (replaces all existing roles).
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role name.
	 * @return bool True on success, false on failure.
	 */
	public function set_role( $user_id, $role ) {
		$user = $this->find( $user_id );

		if ( ! $user ) {
			return false;
		}

		$role = sanitize_key( $role );

		if ( ! $role ) {
			return false;
		}

		$user->set_role( $role );

		return true;
	}
}
