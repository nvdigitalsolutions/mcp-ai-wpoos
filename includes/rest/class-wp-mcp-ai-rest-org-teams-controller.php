<?php
/**
 * Org-team REST controller.
 *
 * Mounted at `mcp-ai/v1/org/teams` — `mcp-ai/v1/teams` is already the
 * WordPress agent-team catalogue (a collection of professions), so the org
 * primitive (users with roles) gets its own namespace rather than returning a
 * different shape at the same URL. The Laravel runtime serves the same contract
 * at `api/v1/org/teams` (090-g Phase 3, WordPress parity 090-p W7).
 *
 * Envelopes mirror `TeamController` on Laravel exactly, including the 201 on
 * create/membership and the 204 on delete.
 *
 * @package WP_MCP_AI
 * @since   1.2.22
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Org-team REST controller.
 */
class WP_MCP_AI_REST_Org_Teams_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/org/teams';

	/**
	 * Team service.
	 *
	 * @var WP_MCP_AI_Org_Team_Service
	 */
	private $teams;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Org_Team_Service|null $teams Optional service (tests).
	 */
	public function __construct( $teams = null ) {
		$this->teams = $teams instanceof WP_MCP_AI_Org_Team_Service
			? $teams
			: new WP_MCP_AI_Org_Team_Service();
	}

	/**
	 * Register the org-team routes.
	 *
	 * @since 1.2.22
	 * @return void
	 */
	public function register_routes() {
		$team = '(?P<team>\d+)';
		$user = '(?P<user>\d+)';

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'store' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'name'        => array(
							'type'     => 'string',
							'required' => true,
						),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/' . $team,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->team_args(),
				),
				array(
					'methods'             => array( 'PUT', 'PATCH' ),
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge(
						$this->team_args(),
						array(
							'name'        => array( 'type' => 'string' ),
							'slug'        => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->team_args(),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/' . $team . '/members',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'store_member' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge(
						$this->team_args(),
						array(
							'user_id'         => array(
								'type'     => 'integer',
								'required' => true,
							),
							'role'            => array( 'type' => 'string' ),
							'profession_slug' => array( 'type' => 'string' ),
						)
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/' . $team . '/members/' . $user,
			array(
				array(
					'methods'             => array( 'PUT', 'PATCH' ),
					'callback'            => array( $this, 'update_member' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge(
						$this->team_args(),
						array(
							'user' => array( 'type' => 'integer' ),
							'role' => array(
								'type'     => 'string',
								'required' => true,
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy_member' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array_merge(
						$this->team_args(),
						array( 'user' => array( 'type' => 'integer' ) )
					),
				),
			)
		);
	}

	/**
	 * Any logged-in user may call the surface; team-level access is enforced
	 * per handler (404 for non-members, 403 for a missing role) exactly as the
	 * Laravel `guard()` does.
	 *
	 * @since 1.2.22
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You must be logged in to manage org teams.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * GET /org/teams — the caller's teams.
	 *
	 * @return WP_REST_Response
	 */
	public function index() {
		$teams = $this->teams->list_for_user( get_current_user_id() );

		return rest_ensure_response(
			array(
				'teams' => $teams,
				'total' => count( $teams ),
			)
		);
	}

	/**
	 * POST /org/teams — create a team; the caller becomes owner.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function store( $request ) {
		$result = $this->teams->create(
			array(
				'name'        => sanitize_text_field( (string) $request->get_param( 'name' ) ),
				'slug'        => sanitize_title( (string) $request->get_param( 'slug' ) ),
				'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
				'owner_id'    => get_current_user_id(),
			)
		);

		if ( ! $result['created'] ) {
			return new WP_Error(
				'team_not_created',
				$result['error'],
				array( 'status' => 422 )
			);
		}

		$response = rest_ensure_response( $result['team'] );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * GET /org/teams/{team}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( $request ) {
		$team_id = absint( $request['team'] );
		$guard   = $this->guard( $team_id );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$result = $this->teams->get( $team_id );

		if ( ! $result['found'] ) {
			return $this->not_found();
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['team'],
			)
		);
	}

	/**
	 * PUT/PATCH /org/teams/{team}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( $request ) {
		$team_id = absint( $request['team'] );
		$guard   = $this->guard( $team_id, 'manage_team' );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$attributes = array();

		if ( null !== $request->get_param( 'name' ) ) {
			$attributes['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
		}

		if ( null !== $request->get_param( 'description' ) ) {
			$attributes['description'] = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		}

		if ( null !== $request->get_param( 'slug' ) ) {
			$attributes['slug'] = sanitize_title( (string) $request->get_param( 'slug' ) );
		}

		$result = $this->teams->update( $team_id, $attributes );

		if ( ! $result['updated'] ) {
			return new WP_Error(
				'team_not_updated',
				$result['error'],
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['team'],
			)
		);
	}

	/**
	 * DELETE /org/teams/{team}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( $request ) {
		$team_id = absint( $request['team'] );
		$guard   = $this->guard( $team_id, 'delete_team' );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$result = $this->teams->delete( $team_id );

		if ( ! $result['deleted'] ) {
			return $this->not_found();
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * POST /org/teams/{team}/members.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function store_member( $request ) {
		$team_id = absint( $request['team'] );
		$guard   = $this->guard( $team_id, 'manage_team_members' );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$role_param = $request->get_param( 'role' );
		$role       = sanitize_key(
			(string) ( null === $role_param || '' === $role_param ? 'member' : $role_param )
		);

		$result = $this->teams->add_member(
			$team_id,
			absint( $request->get_param( 'user_id' ) ),
			$role,
			null === $request->get_param( 'profession_slug' ) ? null : sanitize_key( (string) $request->get_param( 'profession_slug' ) ),
			get_current_user_id()
		);

		if ( ! empty( $result['added'] ) ) {
			$response = rest_ensure_response( $result['member'] );
			$response->set_status( 201 );

			return $response;
		}

		// Re-adding an existing member is idempotent: the role was updated.
		if ( 'User is already a member.' === ( $result['error'] ?? '' ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'message' => $result['error'],
					'data'    => $result['member'] ?? null,
				)
			);
		}

		return new WP_Error(
			'team_member_not_added',
			$result['error'] ?? __( 'Member not added.', 'mcp-ai-wpoos' ),
			array( 'status' => 422 )
		);
	}

	/**
	 * PUT/PATCH /org/teams/{team}/members/{user}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_member( $request ) {
		$team_id = absint( $request['team'] );
		$guard   = $this->guard( $team_id, 'manage_team_members' );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$result = $this->teams->change_role(
			$team_id,
			absint( $request['user'] ),
			sanitize_key( (string) $request->get_param( 'role' ) )
		);

		if ( ! $result['updated'] ) {
			return new WP_Error(
				'team_member_not_updated',
				$result['error'] ?? __( 'Member not updated.', 'mcp-ai-wpoos' ),
				array( 'status' => 422 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result['member'],
			)
		);
	}

	/**
	 * DELETE /org/teams/{team}/members/{user}.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy_member( $request ) {
		$team_id = absint( $request['team'] );
		$guard   = $this->guard( $team_id, 'manage_team_members' );

		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$result = $this->teams->remove_member( $team_id, absint( $request['user'] ) );

		if ( ! $result['removed'] ) {
			return new WP_Error(
				'team_member_not_removed',
				$result['error'] ?? __( 'Member not removed.', 'mcp-ai-wpoos' ),
				array( 'status' => 422 )
			);
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Team-scoped authorization.
	 *
	 * Non-members get 404 (no existence disclosure); members missing the
	 * ability get 403. Site administrators pass both.
	 *
	 * @param int         $team_id Team post ID.
	 * @param string|null $ability Optional ability to require.
	 * @return true|WP_Error
	 */
	private function guard( $team_id, $ability = null ) {
		$user_id = get_current_user_id();

		if ( ! $this->teams->get( $team_id )['found'] ) {
			return $this->not_found();
		}

		$role = $this->teams->role_for( $user_id, $team_id );

		if ( null === $role && ! user_can( $user_id, 'manage_options' ) ) {
			return $this->not_found();
		}

		if ( null !== $ability && ! $this->teams->allows( $user_id, $team_id, $ability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to perform this action on the team.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * A typed 404.
	 *
	 * @return WP_Error
	 */
	private function not_found() {
		return new WP_Error(
			'team_not_found',
			__( 'Team not found.', 'mcp-ai-wpoos' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Route arg for the team id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function team_args() {
		return array(
			'team' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
	}
}
