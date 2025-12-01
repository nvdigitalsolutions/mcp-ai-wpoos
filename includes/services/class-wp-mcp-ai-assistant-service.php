<?php
/**
 * Assistant Service
 *
 * Handles assistant management operations.
 * Extracted from WP_MCP_AI_REST as part of service layer refactoring.
 *
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assistant Service class
 *
 * Responsible for:
 * - Assistant validation and access control
 * - Assistant configuration retrieval
 * - Assistant capability management
 * - Default assistant resolution
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Assistant_Service {

	/**
	 * Settings repository instance
	 *
	 * @var WP_MCP_AI_Settings_Repository
	 */
	private $settings_repository;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Settings_Repository $settings_repository Settings repository (optional, for DI).
	 */
	public function __construct( $settings_repository = null ) {
		// Use dependency injection or fall back to getting from container (backward compatibility).
		$this->settings_repository = $settings_repository ?? wp_mcp_ai_get_settings_repository();
	}

	/**
	 * Validate assistant exists and user has access
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @param int $user_id      User ID (0 for current user).
	 * @return WP_Post|WP_Error Assistant post object or error.
	 */
	public function validate_assistant_access( $assistant_id, $user_id = 0 ) {
		if ( ! $assistant_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_assistant',
				__( 'Assistant ID is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$assistant_post = get_post( $assistant_id );

		if ( ! $assistant_post || 'mcp_ai_assistant' !== $assistant_post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_assistant',
				__( 'Invalid assistant ID.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		if ( 'publish' !== $assistant_post->post_status ) {
			return new WP_Error(
				'wp_mcp_ai_assistant_not_published',
				__( 'Assistant is not published.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Check user capabilities.
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$required_capability = get_post_meta( $assistant_id, 'mcp_ai_required_capability', true );
		if ( $required_capability && ! user_can( $user_id, $required_capability ) ) {
			return new WP_Error(
				'wp_mcp_ai_insufficient_permissions',
				__( 'You do not have permission to use this assistant.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		return $assistant_post;
	}

	/**
	 * Get assistant configuration
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Assistant configuration.
	 */
	public function get_assistant_configuration( $assistant_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return array();
		}

		// Use cache helper if available.
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			return WP_MCP_AI_Cache_Helper::get_assistant_config(
				$assistant_id,
				function ( $id ) {
					return WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $id );
				}
			);
		}

		return WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
	}

	/**
	 * Get default assistant ID
	 *
	 * @return int|null Default assistant ID or null if none set.
	 */
	public function get_default_assistant_id() {
		$default_assistant = $this->settings_repository->get( 'default_assistant' );

		if ( ! $default_assistant ) {
			return null;
		}

		return absint( $default_assistant );
	}

	/**
	 * Resolve assistant ID
	 *
	 * If no ID provided, returns default assistant.
	 *
	 * @param int|null $assistant_id Assistant ID or null.
	 * @return int|null Resolved assistant ID or null.
	 */
	public function resolve_assistant_id( $assistant_id = null ) {
		if ( $assistant_id ) {
			return absint( $assistant_id );
		}

		return $this->get_default_assistant_id();
	}

	/**
	 * Get assistants list
	 *
	 * @param array $args Query arguments.
	 * @return array List of assistants.
	 */
	public function get_assistants_list( $args = array() ) {
		$defaults = array(
			'post_type'      => 'mcp_ai_assistant',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$query_args = wp_parse_args( $args, $defaults );
		$query      = new WP_Query( $query_args );

		$assistants = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$assistants[] = $this->format_assistant_for_response( $post );
			}
		}

		return $assistants;
	}

	/**
	 * Format assistant for API response
	 *
	 * @param WP_Post $post Assistant post object.
	 * @return array Formatted assistant data.
	 */
	private function format_assistant_for_response( $post ) {
		$config = $this->get_assistant_configuration( $post->ID );

		return array(
			'id'          => $post->ID,
			'name'        => $post->post_title,
			'description' => $post->post_content,
			'provider'    => $config['provider'] ?? '',
			'model'       => $config['model'] ?? '',
			'created_at'  => $post->post_date,
			'modified_at' => $post->post_modified,
		);
	}

	/**
	 * Check if assistant has required capability
	 *
	 * @param int    $assistant_id Assistant ID.
	 * @param string $capability   Capability to check.
	 * @param int    $user_id      User ID (0 for current user).
	 * @return bool True if has capability.
	 */
	public function assistant_has_capability( $assistant_id, $capability, $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$required_capability = get_post_meta( $assistant_id, 'mcp_ai_required_capability', true );

		if ( ! $required_capability ) {
			return true; // No capability required.
		}

		return user_can( $user_id, $required_capability );
	}

	/**
	 * Get assistant tools
	 *
	 * @param int $assistant_id Assistant ID.
	 * @return array List of enabled tool slugs.
	 */
	public function get_assistant_tools( $assistant_id ) {
		$config = $this->get_assistant_configuration( $assistant_id );

		if ( isset( $config['tools'] ) && is_array( $config['tools'] ) ) {
			return $config['tools'];
		}

		return array();
	}

	/**
	 * Update assistant configuration
	 *
	 * @param int   $assistant_id Assistant ID.
	 * @param array $config       Configuration to update.
	 * @return bool True on success, false on failure.
	 */
	public function update_assistant_configuration( $assistant_id, $config ) {
		if ( ! $assistant_id ) {
			return false;
		}

		// Validate assistant exists.
		$assistant = $this->validate_assistant_access( $assistant_id );
		if ( is_wp_error( $assistant ) ) {
			return false;
		}

		// Update configuration meta fields.
		foreach ( $config as $key => $value ) {
			$meta_key = 'mcp_ai_' . $key;
			update_post_meta( $assistant_id, $meta_key, $value );
		}

		return true;
	}
}
