<?php
/**
 * REST API controller for Teams.
 *
 * Handles REST endpoints specific to team testing functionality.
 * Follows SoC by separating team-specific REST logic.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent fatal error if WP_REST_Controller is not available yet.
// This can happen during plugin activation before WordPress REST API is fully loaded.
if ( ! class_exists( 'WP_REST_Controller' ) ) {
	return;
}

// Ensure Team and Profession CPT classes are loaded for constants.
if ( ! class_exists( 'WP_MCP_AI_Team_CPT' ) && file_exists( WP_MCP_AI_PATH . 'includes/teams/class-wp-mcp-ai-team-cpt.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/teams/class-wp-mcp-ai-team-cpt.php';
}

if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) && file_exists( WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
}

/**
 * Teams REST controller class.
 */
class WP_MCP_AI_REST_Teams_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Routes are registered by calling register_routes() directly from the main REST controller.
		// This matches the pattern used by other REST controllers (Chat, MCP, Tools).
	}

	/**
	 * Register REST routes for teams.
	 *
	 * Registers team management REST API endpoints:
	 * - GET /teams/{id}/members: Get list of profession members in a team
	 *
	 * Teams are collections of profession custom post types that define:
	 * - Multi-agent collaboration workflows
	 * - Specialized profession roles (advisory, creative, technical, etc.)
	 * - Profession expertise areas and capabilities
	 * - Default tool configurations per profession
	 *
	 * The members endpoint returns:
	 * - Profession ID, title, and description
	 * - Category (advisory, creative, technical, healthcare, legal, financial, other)
	 * - Expertise areas array
	 * - Assigned tools count
	 *
	 * Access control:
	 * - Requires 'manage_options' capability (admin-only)
	 * - Validates team post type existence
	 * - Filters out invalid or deleted profession members
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Get team members endpoint.
		register_rest_route(
			self::REST_NAMESPACE,
			'/teams/(?P<id>\d+)/members',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_team_members' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( $this, 'validate_team_id' ),
					),
				),
			)
		);
	}

	/**
	 * Check if user has permission to access teams.
	 *
	 * @return bool True if user can manage options.
	 */
	public function check_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Validate team ID.
	 *
	 * @param int $team_id Team post ID.
	 * @return bool True if valid team ID.
	 */
	public function validate_team_id( $team_id ) {
		$team_id = absint( $team_id );

		if ( ! $team_id ) {
			return false;
		}

		$post = get_post( $team_id );

		if ( ! $post || 'mcp_ai_team' !== $post->post_type ) {
			return false;
		}

		return true;
	}

	/**
	 * Log an event if the logger is available.
	 *
	 * @param string $event   Event name.
	 * @param string $message Event message.
	 * @param array  $context Event context data.
	 */
	private function log_event( $event, $message, $context = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event( $event, $message, $context );
		}
	}

	/**
	 * Log an error if the logger is available.
	 *
	 * @param string $message Error message.
	 * @param array  $context Error context data.
	 */
	private function log_error( $message, $context = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_error( $message, $context );
		}
	}

	/**
	 * Get team members.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_team_members( $request ) {
		$team_id = absint( $request->get_param( 'id' ) );

		// Log the request for debugging.
		$this->log_event(
			'rest_teams_get_members',
			'Team members request received',
			array(
				'team_id' => $team_id,
				'user_id' => get_current_user_id(),
			)
		);

		if ( ! $team_id ) {
			return new WP_Error(
				'invalid_team_id',
				__( 'Invalid team ID provided.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Safety check: Ensure Team CPT class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Team_CPT' ) ) {
			$this->log_error(
				'Team CPT class not loaded',
				array( 'team_id' => $team_id )
			);
			return new WP_Error(
				'team_cpt_not_loaded',
				__( 'Team system is not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Get team post to access title.
		$team_post = get_post( $team_id );

		// Get driver assistant.
		$driver_assistant_id = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_DRIVER_ASSISTANT, true );
		if ( ! $driver_assistant_id ) {
			$driver_assistant_id = get_option( 'wp_mcp_ai_team_default_driver_assistant', 0 );
		}

		// Get team members from meta.
		$team_members = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );

		if ( ! is_array( $team_members ) ) {
			$team_members = array();
		}

		// Get team orchestration settings for multi-agent coordination.
		$orchestration_mode         = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_ORCHESTRATION_MODE, true );
		$result_aggregation         = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_RESULT_AGGREGATION_STRATEGY, true );
		$enable_multi_agent_teams   = WP_MCP_AI_Settings_Registry::get_setting( 'enable_multi_agent_teams', true );

		// Build member data.
		$members = array();

		foreach ( $team_members as $member_id ) {
			$member = get_post( $member_id );

			if ( ! $member || 'mcp_ai_profession' !== $member->post_type ) {
				continue;
			}

			// Safety check: Ensure Profession CPT class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
				$this->log_error(
					'Profession CPT class not loaded',
					array(
						'team_id'   => $team_id,
						'member_id' => $member_id,
					)
				);
				continue; // Skip this member if Profession CPT isn't available.
			}

			// Get profession metadata.
			$category  = get_post_meta( $member_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );
			$expertise = get_post_meta( $member_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
			$tools     = get_post_meta( $member_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true );

			$category_labels = array(
				'advisory'   => __( 'Advisory/Consulting', 'mcp-ai-wpoos' ),
				'creative'   => __( 'Creative Services', 'mcp-ai-wpoos' ),
				'technical'  => __( 'Technical', 'mcp-ai-wpoos' ),
				'healthcare' => __( 'Healthcare', 'mcp-ai-wpoos' ),
				'legal'      => __( 'Legal', 'mcp-ai-wpoos' ),
				'financial'  => __( 'Financial', 'mcp-ai-wpoos' ),
				'other'      => __( 'Other', 'mcp-ai-wpoos' ),
			);

			$category_display = isset( $category_labels[ $category ] ) ? $category_labels[ $category ] : ( $category ? ucfirst( $category ) : '' );

			$members[] = array(
				'id'            => $member_id,
				'title'         => $member->post_title,
				'category'      => $category_display,
				'category_slug' => $category,
				'excerpt'       => $member->post_excerpt,
				'expertise'     => is_array( $expertise ) ? $expertise : array(),
				'tools_count'   => is_array( $tools ) ? count( $tools ) : 0,
			);
		}

		// Log successful response.
		$this->log_event(
			'rest_teams_get_members_success',
			'Team members loaded successfully',
			array(
				'team_id'      => $team_id,
				'member_count' => count( $members ),
			)
		);

		return new WP_REST_Response(
			array(
				'team_id'                  => $team_id,
				'team_title'               => $team_post ? $team_post->post_title : '',
				'driver_assistant_id'      => $driver_assistant_id ? absint( $driver_assistant_id ) : 0,
				'has_driver_assistant'     => (bool) $driver_assistant_id,
				'members'                  => $members,
				'count'                    => count( $members ),
				'orchestration_mode'       => $orchestration_mode ? $orchestration_mode : 'sequential',
				'result_aggregation'       => $result_aggregation ? $result_aggregation : 'consensus',
				'multi_agent_enabled'      => $enable_multi_agent_teams,
				'supports_unified_mode'    => $enable_multi_agent_teams && count( $members ) > 1 && (bool) $driver_assistant_id,
			),
			200
		);
	}
}
