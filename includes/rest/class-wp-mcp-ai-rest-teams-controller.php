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
	 * Get team members.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_team_members( $request ) {
		$team_id = absint( $request->get_param( 'id' ) );

		if ( ! $team_id ) {
			return new WP_Error(
				'invalid_team_id',
				__( 'Invalid team ID provided.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Get team members from meta.
		$team_members = get_post_meta( $team_id, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );

		if ( ! is_array( $team_members ) ) {
			$team_members = array();
		}

		// Build member data.
		$members = array();

		foreach ( $team_members as $member_id ) {
			$member = get_post( $member_id );

			if ( ! $member || 'mcp_ai_profession' !== $member->post_type ) {
				continue;
			}

			// Get profession metadata.
			$category  = get_post_meta( $member_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );
			$expertise = get_post_meta( $member_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
			$tools     = get_post_meta( $member_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true );

			$category_labels = array(
				'advisory'   => __( 'Advisory/Consulting', 'wp-mcp-ai' ),
				'creative'   => __( 'Creative Services', 'wp-mcp-ai' ),
				'technical'  => __( 'Technical', 'wp-mcp-ai' ),
				'healthcare' => __( 'Healthcare', 'wp-mcp-ai' ),
				'legal'      => __( 'Legal', 'wp-mcp-ai' ),
				'financial'  => __( 'Financial', 'wp-mcp-ai' ),
				'other'      => __( 'Other', 'wp-mcp-ai' ),
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

		return new WP_REST_Response(
			array(
				'team_id' => $team_id,
				'members' => $members,
				'count'   => count( $members ),
			),
			200
		);
	}
}
