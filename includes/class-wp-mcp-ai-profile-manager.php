<?php
/**
 * Profile Manager — Tool permission profiles for agent safety.
 *
 * Provides built-in profiles (Write, Ask, Minimal) and custom profiles
 * with per-tool allow/deny/confirm permission resolution. Mirrors Zed's
 * agent profile system with tool-level permission patterns.
 *
 * @package NV_oOS
 * @since   1.7.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class WP_MCP_AI_Profile_Manager
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Profile_Manager {

	/**
	 * Built-in profile definitions.
	 *
	 * These are not stored in the database — they are resolved dynamically.
	 * Custom profiles override built-in profiles when they share a name.
	 *
	 * @since 1.7.0
	 * @var array
	 */
	const BUILTIN_PROFILES = array(
		'write'   => array(
			'label'            => 'Write',
			'description'      => 'All tools enabled — full agentic editing and content management.',
			'default_approval' => 'confirm',
			'tool_allowlist'   => null,
			'tool_denylist'    => array(),
			'always_confirm'   => array(),
			'always_allow'     => array(),
		),
		'ask'     => array(
			'label'            => 'Ask',
			'description'      => 'Read-only tools only — safe for research, analysis, and questions.',
			'default_approval' => 'allow',
			'tool_allowlist'   => null,
			'tool_denylist'    => array(),
			'always_confirm'   => array(),
			'always_allow'     => array(),
		),
		'minimal' => array(
			'label'            => 'Minimal',
			'description'      => 'No tools — pure LLM conversation without WordPress access.',
			'default_approval' => 'allow',
			'tool_allowlist'   => array(),
			'tool_denylist'    => array(),
			'always_confirm'   => array(),
			'always_allow'     => array(),
		),
	);

	/**
	 * WordPress database abstraction.
	 *
	 * @since 1.7.0
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Profiles table name (prefixed).
	 *
	 * @since 1.7.0
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @since 1.7.0
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = $wpdb->prefix . 'mcp_ai_profiles';
	}

	// ──────────────────────────────────────────────
	// Profile CRUD
	// ──────────────────────────────────────────────

	/**
	 * List all available profiles for a user.
	 *
	 * Built-in profiles are always included. Custom profiles are
	 * loaded from the database. User-specific profiles override
	 * site-wide custom profiles.
	 *
	 * @since 1.7.0
	 *
	 * @param int $user_id WordPress user ID (0 = site-wide only).
	 * @return array
	 */
	public function list_profiles( $user_id = 0 ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user_id  = absint( $user_id );
		$profiles = self::BUILTIN_PROFILES;

		// Load custom profiles from database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM `{$this->table}` WHERE user_id IS NULL OR user_id = %d ORDER BY is_builtin DESC, label ASC",
				$user_id
			),
			ARRAY_A
		);

		if ( $rows ) {
			foreach ( $rows as $row ) {
				$name              = $row['name'];
				$profiles[ $name ] = array(
					'id'               => (int) $row['id'],
					'name'             => $name,
					'label'            => $row['label'],
					'is_builtin'       => (bool) $row['is_builtin'],
					'description'      => '',
					'default_approval' => $row['default_approval'],
					'tool_allowlist'   => $this->json_decode_array( $row['tool_allowlist'] ),
					'tool_denylist'    => $this->json_decode_array( $row['tool_denylist'] ),
					'always_confirm'   => $this->json_decode_array( $row['always_confirm'] ),
					'always_allow'     => $this->json_decode_array( $row['always_allow'] ),
				);
			}
		}

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Return as indexed array suitable for API response.
		$list = array();
		foreach ( $profiles as $name => $data ) {
			$data['name'] = $name;
			$list[]       = $data;
		}

		return $list;
	}

	/**
	 * Get a single profile by name.
	 *
	 * @since 1.7.0
	 *
	 * @param string $name    Profile name.
	 * @param int    $user_id WordPress user ID (0 = site-wide only).
	 * @return array|null     Profile data or null if not found.
	 */
	public function get_profile( $name, $user_id = 0 ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$name    = sanitize_key( $name );
		$user_id = absint( $user_id );

		// Check built-in first.
		if ( isset( self::BUILTIN_PROFILES[ $name ] ) ) {
			$data               = self::BUILTIN_PROFILES[ $name ];
			$data['name']       = $name;
			$data['is_builtin'] = true;
			$data['id']         = 0;
			return $data;
		}

		// Check database.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM `{$this->table}` WHERE name = %s AND (user_id IS NULL OR user_id = %d) ORDER BY user_id DESC LIMIT 1",
				$name,
				$user_id
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}

		return array(
			'id'               => (int) $row['id'],
			'name'             => $name,
			'label'            => $row['label'],
			'is_builtin'       => false,
			'description'      => '',
			'default_approval' => $row['default_approval'],
			'tool_allowlist'   => $this->json_decode_array( $row['tool_allowlist'] ),
			'tool_denylist'    => $this->json_decode_array( $row['tool_denylist'] ),
			'always_confirm'   => $this->json_decode_array( $row['always_confirm'] ),
			'always_allow'     => $this->json_decode_array( $row['always_allow'] ),
		);
	}

	/**
	 * Create a custom profile.
	 *
	 * @since 1.7.0
	 *
	 * @param string $name    Profile name (slug).
	 * @param string $label   Human-readable label.
	 * @param array  $config  Profile config: default_approval, tool_allowlist, tool_denylist, always_confirm, always_allow.
	 * @param int    $user_id WordPress user ID (0 = site-wide).
	 * @return array|WP_Error
	 */
	public function create_profile( $name, $label, $config = array(), $user_id = 0 ) {
		$name    = sanitize_key( $name );
		$label   = sanitize_text_field( $label );
		$user_id = empty( $user_id ) ? null : absint( $user_id );

		if ( empty( $name ) || empty( $label ) ) {
			return new WP_Error( 'invalid_input', __( 'Profile name and label are required.', 'mcp-ai-wpoos' ) );
		}

		// Prevent overriding built-in profile slugs with custom profiles in the DB.
		if ( isset( self::BUILTIN_PROFILES[ $name ] ) ) {
			return new WP_Error(
				'reserved_name',
				sprintf(
					/* translators: %s: profile name */
					__( '"%s" is a built-in profile name. To customize it, use the override endpoint instead.', 'mcp-ai-wpoos' ),
					esc_html( $name )
				)
			);
		}

		// Check for duplicates.
		$existing = $this->get_profile( $name, absint( $user_id ) );
		if ( null !== $existing ) {
			return new WP_Error( 'duplicate', __( 'A profile with this name already exists.', 'mcp-ai-wpoos' ) );
		}

		$default_approval = isset( $config['default_approval'] ) ? sanitize_key( $config['default_approval'] ) : 'confirm';
		if ( ! in_array( $default_approval, array( 'allow', 'deny', 'confirm' ), true ) ) {
			$default_approval = 'confirm';
		}

		$data = array(
			'name'             => $name,
			'label'            => $label,
			'is_builtin'       => 0,
			'default_approval' => $default_approval,
			'tool_allowlist'   => isset( $config['tool_allowlist'] ) ? wp_json_encode( $config['tool_allowlist'] ) : null,
			'tool_denylist'    => isset( $config['tool_denylist'] ) ? wp_json_encode( $config['tool_denylist'] ) : null,
			'always_confirm'   => isset( $config['always_confirm'] ) ? wp_json_encode( $config['always_confirm'] ) : null,
			'always_allow'     => isset( $config['always_allow'] ) ? wp_json_encode( $config['always_allow'] ) : null,
			'user_id'          => $user_id,
			'created_at'       => current_time( 'mysql' ),
			'updated_at'       => current_time( 'mysql' ),
		);

		$inserted = $this->wpdb->insert( $this->table, $data );
		if ( false === $inserted ) {
			return new WP_Error( 'db_error', __( 'Failed to create profile.', 'mcp-ai-wpoos' ) );
		}

		$profile_id = (int) $this->wpdb->insert_id;

		/**
		 * Fires after a custom profile is created.
		 *
		 * @since 1.7.0
		 *
		 * @param int    $profile_id The new profile ID.
		 * @param string $name       Profile name.
		 * @param array  $data       Full profile data.
		 */
		do_action( 'wp_mcp_ai_profile_created', $profile_id, $name, $data );

		return array(
			'success' => true,
			'message' => __( 'Profile created.', 'mcp-ai-wpoos' ),
			'data'    => array_merge(
				array(
					'id'   => $profile_id,
					'name' => $name,
				),
				$data
			),
		);
	}

	/**
	 * Delete a custom profile.
	 *
	 * Built-in profiles cannot be deleted.
	 *
	 * @since 1.7.0
	 *
	 * @param string $name    Profile name.
	 * @param int    $user_id WordPress user ID.
	 * @return array|WP_Error
	 */
	public function delete_profile( $name, $user_id = 0 ) {
		$name    = sanitize_key( $name );
		$user_id = absint( $user_id );

		if ( isset( self::BUILTIN_PROFILES[ $name ] ) ) {
			return new WP_Error( 'cannot_delete_builtin', __( 'Built-in profiles cannot be deleted.', 'mcp-ai-wpoos' ) );
		}

		$deleted = $this->wpdb->delete(
			$this->table,
			array(
				'name'    => $name,
				'user_id' => empty( $user_id ) ? null : $user_id,
			)
		);

		if ( false === $deleted ) {
			return new WP_Error( 'db_error', __( 'Failed to delete profile.', 'mcp-ai-wpoos' ) );
		}

		if ( 0 === $deleted ) {
			return new WP_Error( 'not_found', __( 'Profile not found.', 'mcp-ai-wpoos' ) );
		}

		/**
		 * Fires after a profile is deleted.
		 *
		 * @since 1.7.0
		 *
		 * @param string $name    Deleted profile name.
		 * @param int    $user_id WordPress user ID.
		 */
		do_action( 'wp_mcp_ai_profile_deleted', $name, $user_id );

		return array(
			'success' => true,
			'message' => __( 'Profile deleted.', 'mcp-ai-wpoos' ),
			'data'    => array( 'name' => $name ),
		);
	}

	// ──────────────────────────────────────────────
	// Permission Resolution
	// ──────────────────────────────────────────────

	/**
	 * Resolve whether a tool is allowed/denied/needs-confirmation.
	 *
	 * Resolution order (first match wins):
	 * 1. always_deny pattern match → 'deny'
	 * 2. always_allow pattern match → 'allow'
	 * 3. tool_denylist exact match → 'deny'
	 * 4. tool_allowlist check → 'allow' or 'deny'
	 *    - null = all tools allowed
	 *    - empty array = no tools allowed
	 *    - populated array = only listed tools allowed
	 * 5. default_approval → 'confirm' (or whatever the configured fallback is)
	 *
	 * @since 1.7.0
	 *
	 * @param string $profile_name Profile name.
	 * @param string $tool_slug    Tool slug.
	 * @param int    $user_id      WordPress user ID.
	 * @return string 'allow' | 'deny' | 'confirm'
	 */
	public function resolve_permission( $profile_name, $tool_slug, $user_id = 0 ) {
		$profile_name = sanitize_key( $profile_name );
		$tool_slug    = sanitize_key( $tool_slug );

		$profile = $this->get_profile( $profile_name, absint( $user_id ) );

		if ( null === $profile ) {
			// Unknown profile — fall back to 'write' (most permissive).
			$profile         = self::BUILTIN_PROFILES['write'];
			$profile['name'] = 'write';
		}

		$always_deny  = isset( $profile['always_deny'] ) ? (array) $profile['always_deny'] : array();
		$always_allow = isset( $profile['always_allow'] ) ? (array) $profile['always_allow'] : array();
		$denylist     = isset( $profile['tool_denylist'] ) ? (array) $profile['tool_denylist'] : array();
		$allowlist    = isset( $profile['tool_allowlist'] ) ? $profile['tool_allowlist'] : null;
		$default      = isset( $profile['default_approval'] ) ? $profile['default_approval'] : 'confirm';

		// Special handling for 'ask' profile — filter to read-only tools.
		if ( 'ask' === $profile_name && null === $allowlist ) {
			if ( ! $this->is_tool_read_only( $tool_slug ) ) {
				return 'deny';
			}
			return 'allow';
		}

		// 1. always_deny patterns.
		foreach ( $always_deny as $pattern ) {
			if ( $this->match_pattern( $pattern, $tool_slug ) ) {
				return 'deny';
			}
		}

		// 2. always_allow patterns.
		foreach ( $always_allow as $pattern ) {
			if ( $this->match_pattern( $pattern, $tool_slug ) ) {
				return 'allow';
			}
		}

		// 3. tool_denylist exact match.
		if ( in_array( $tool_slug, $denylist, true ) ) {
			return 'deny';
		}

		// 4. tool_allowlist check.
		if ( null === $allowlist ) {
			// null = all tools allowed.
			return 'allow';
		}

		if ( is_array( $allowlist ) && empty( $allowlist ) ) {
			// empty = no tools.
			return 'deny';
		}

		if ( is_array( $allowlist ) && in_array( $tool_slug, $allowlist, true ) ) {
			return 'allow';
		}

		// 5. default_approval.
		return $default;
	}

	/**
	 * Filter tool definitions to only those allowed by a profile.
	 *
	 * Used when building the tools array for the LLM API request.
	 *
	 * @since 1.7.0
	 *
	 * @param string $profile_name Profile name.
	 * @param array  $tools        All registered tool definitions (slug => definition).
	 * @param int    $user_id      WordPress user ID.
	 * @return array               Filtered tool definitions.
	 */
	public function filter_tools_for_profile( $profile_name, $tools, $user_id = 0 ) {
		$filtered = array();

		foreach ( $tools as $slug => $definition ) {
			$permission = $this->resolve_permission( $profile_name, $slug, $user_id );
			if ( 'deny' !== $permission ) {
				$filtered[ $slug ] = $definition;
			}
		}

		return $filtered;
	}

	// ──────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────

	/**
	 * Check if a tool is read-only by inspecting the tool registry.
	 *
	 * @since 1.7.0
	 *
	 * @param string $tool_slug Tool slug.
	 * @return bool
	 */
	private function is_tool_read_only( $tool_slug ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return false;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( $tool_slug );

		if ( ! $tool ) {
			return false;
		}

		// Check capability flags interface.
		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
			return in_array( 'read-only', $flags, true );
		}

		return false;
	}

	/**
	 * Simple glob-style pattern match for tool slugs.
	 *
	 * Supports '*' wildcard (e.g., 'delete_*' matches 'delete_post').
	 *
	 * @since 1.7.0
	 *
	 * @param string $pattern  Glob-style pattern.
	 * @param string $tool_slug Tool slug to test.
	 * @return bool
	 */
	private function match_pattern( $pattern, $tool_slug ) {
		if ( $pattern === $tool_slug ) {
			return true;
		}

		// Convert glob pattern to regex.
		$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
		return (bool) preg_match( $regex, $tool_slug );
	}

	/**
	 * Safely decode a JSON column value to an array.
	 *
	 * @since 1.7.0
	 *
	 * @param string|null $json JSON string or null.
	 * @return array
	 */
	private function json_decode_array( $json ) {
		if ( empty( $json ) || 'null' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
