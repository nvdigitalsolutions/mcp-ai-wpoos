<?php
/**
 * Tests the WordPress org-team REST contract (090-p W7).
 *
 * The surface mirrors Laravel's `api/v1/org/teams` envelopes and invariants:
 * the creator becomes owner, a team keeps at least one owner, non-members get
 * 404, and memberships are unique per (team, user).
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @since     1.2.22
 */
class WP_MCP_AI_REST_Org_Teams_Controller_Test extends WP_UnitTestCase {

	/**
	 * Owner user.
	 *
	 * @var int
	 */
	private $owner_id = 0;

	/**
	 * Member user.
	 *
	 * @var int
	 */
	private $member_id = 0;

	/**
	 * Outsider user.
	 *
	 * @var int
	 */
	private $outsider_id = 0;

	/**
	 * Site administrator.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	/**
	 * Create the users and make sure the CPT is registered.
	 */
	public function setUp(): void {
		parent::setUp();

		WP_MCP_AI_Org_Team_CPT::register();

		$this->owner_id    = $this->factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Team Owner',
				'user_email'   => 'owner@example.com',
			)
		);
		$this->member_id   = $this->factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Team Member',
				'user_email'   => 'member@example.com',
			)
		);
		$this->outsider_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin_id    = $this->factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Build a request with params.
	 *
	 * @param string $method HTTP method.
	 * @param array  $params Request params.
	 * @return WP_REST_Request
	 */
	private function request( $method, $params = array() ) {
		$request = new WP_REST_Request( $method, '/mcp-ai/v1/org/teams' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * A fresh controller.
	 *
	 * @return WP_MCP_AI_REST_Org_Teams_Controller
	 */
	private function controller() {
		return new WP_MCP_AI_REST_Org_Teams_Controller();
	}

	/**
	 * Create a team as the owner.
	 *
	 * @param string $name Team name.
	 * @return int Team post ID.
	 */
	private function create_team( $name = 'Alpha Team' ) {
		wp_set_current_user( $this->owner_id );

		$response = $this->controller()->store( $this->request( 'POST', array( 'name' => $name ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );

		return (int) $data['id'];
	}

	/**
	 * Anonymous sessions are refused.
	 */
	public function test_anonymous_is_forbidden() {
		wp_set_current_user( 0 );

		$result = $this->controller()->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Creating a team makes the caller its owner.
	 */
	public function test_creating_a_team_makes_the_caller_its_owner() {
		$team_id = $this->create_team( 'Alpha Team' );

		$result = $this->controller()->show( $this->request( 'GET', array( 'team' => $team_id ) ) );
		$data   = $result->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'Alpha Team', $data['data']['name'] );
		$this->assertSame( 'alpha-team', $data['data']['slug'] );
		$this->assertSame( 1, $data['data']['member_count'] );
		$this->assertSame( $this->owner_id, $data['data']['members'][0]['user_id'] );
		$this->assertSame( 'owner', $data['data']['members'][0]['role'] );
		$this->assertSame( 'Team Owner', $data['data']['members'][0]['name'] );
		$this->assertNotSame( '', $data['data']['created_at'] );
	}

	/**
	 * The list is per membership, like Laravel's `listForUser`.
	 */
	public function test_the_list_is_scoped_to_memberships() {
		$alpha = $this->create_team( 'Alpha Team' );

		wp_set_current_user( $this->member_id );
		$beta = $this->controller()->store( $this->request( 'POST', array( 'name' => 'Beta Team' ) ) );
		$this->assertSame( 201, $beta->get_status() );

		wp_set_current_user( $this->owner_id );
		$owner_list = $this->controller()->index()->get_data();
		$this->assertSame( 1, $owner_list['total'] );
		$this->assertSame( $alpha, $owner_list['teams'][0]['id'] );
		$this->assertSame( 'owner', $owner_list['teams'][0]['role'] );

		wp_set_current_user( $this->member_id );
		$member_list = $this->controller()->index()->get_data();
		$this->assertSame( 1, $member_list['total'] );
		$this->assertSame( 'owner', $member_list['teams'][0]['role'] );
	}

	/**
	 * The detail carries the joined user records the console renders.
	 */
	public function test_the_detail_carries_member_user_records() {
		$team_id = $this->create_team();

		$added = $this->controller()->store_member(
			$this->request(
				'POST',
				array(
					'team'    => $team_id,
					'user_id' => $this->member_id,
					'role'    => 'viewer',
				)
			)
		);

		$this->assertSame( 201, $added->get_status() );
		$this->assertSame( 'Team Member', $added->get_data()['name'] );
		$this->assertSame( 'member@example.com', $added->get_data()['email'] );

		$show = $this->controller()->show( $this->request( 'GET', array( 'team' => $team_id ) ) )->get_data();
		$this->assertSame( 2, $show['data']['member_count'] );
	}

	/**
	 * A non-member gets 404 (no existence disclosure).
	 */
	public function test_a_non_member_gets_404() {
		$team_id = $this->create_team();

		wp_set_current_user( $this->outsider_id );
		$result = $this->controller()->show( $this->request( 'GET', array( 'team' => $team_id ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * A site administrator overrides membership.
	 */
	public function test_a_site_administrator_can_open_any_team() {
		$team_id = $this->create_team();

		wp_set_current_user( $this->admin_id );
		$result = $this->controller()->show( $this->request( 'GET', array( 'team' => $team_id ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertTrue( $result->get_data()['success'] );
	}

	/**
	 * Re-adding a member updates the role instead of erroring.
	 */
	public function test_re_adding_a_member_updates_the_role() {
		$team_id = $this->create_team();

		$first = $this->controller()->store_member(
			$this->request(
				'POST',
				array(
					'team'    => $team_id,
					'user_id' => $this->member_id,
					'role'    => 'viewer',
				)
			)
		);
		$this->assertSame( 201, $first->get_status() );

		$second = $this->controller()->store_member(
			$this->request(
				'POST',
				array(
					'team'    => $team_id,
					'user_id' => $this->member_id,
					'role'    => 'manager',
				)
			)
		);

		$this->assertSame( 200, $second->get_status() );
		$this->assertTrue( $second->get_data()['success'] );
		$this->assertSame( 'manager', $second->get_data()['data']['role'] );
	}

	/**
	 * The last owner cannot be demoted or removed.
	 */
	public function test_the_last_owner_cannot_be_demoted_or_removed() {
		$team_id = $this->create_team();

		$demote = $this->controller()->update_member(
			$this->request(
				'PATCH',
				array(
					'team' => $team_id,
					'user' => $this->owner_id,
					'role' => 'member',
				)
			)
		);
		$this->assertWPError( $demote );
		$this->assertSame( 422, $demote->get_error_data()['status'] );

		$remove = $this->controller()->destroy_member(
			$this->request(
				'DELETE',
				array(
					'team' => $team_id,
					'user' => $this->owner_id,
				)
			)
		);
		$this->assertWPError( $remove );
		$this->assertSame( 422, $remove->get_error_data()['status'] );
	}

	/**
	 * A second owner can be demoted; the invariant only protects the last one.
	 */
	public function test_a_second_owner_can_be_demoted() {
		$team_id = $this->create_team();

		$this->controller()->store_member(
			$this->request(
				'POST',
				array(
					'team'    => $team_id,
					'user_id' => $this->member_id,
					'role'    => 'owner',
				)
			)
		);

		$result = $this->controller()->update_member(
			$this->request(
				'PATCH',
				array(
					'team' => $team_id,
					'user' => $this->member_id,
					'role' => 'admin',
				)
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 'admin', $result->get_data()['data']['role'] );
	}

	/**
	 * An unknown role is a typed 422.
	 */
	public function test_an_unknown_role_is_rejected() {
		$team_id = $this->create_team();

		$result = $this->controller()->update_member(
			$this->request(
				'PATCH',
				array(
					'team' => $team_id,
					'user' => $this->owner_id,
					'role' => 'superuser',
				)
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	/**
	 * An empty profession string is stored as null (the Laravel shape).
	 */
	public function test_an_empty_profession_is_stored_as_null() {
		$team_id = $this->create_team();

		$result = $this->controller()->store_member(
			$this->request(
				'POST',
				array(
					'team'            => $team_id,
					'user_id'         => $this->member_id,
					'role'            => 'member',
					'profession_slug' => '',
				)
			)
		);

		$this->assertSame( 201, $result->get_status() );
		$this->assertNull( $result->get_data()['profession_slug'] );
	}

	/**
	 * Slugs are unique per site.
	 */
	public function test_slugs_are_unique_per_site() {
		$first  = $this->create_team( 'Alpha Team' );
		$second = $this->create_team( 'Alpha Team' );

		$second_data = $this->controller()->show( $this->request( 'GET', array( 'team' => $second ) ) )->get_data();

		$this->assertSame( 'alpha-team', get_post( $first )->post_name );
		$this->assertSame( 'alpha-team-2', $second_data['data']['slug'] );
	}

	/**
	 * Deleting a team removes it from the list and the detail.
	 */
	public function test_deleting_a_team_removes_it() {
		$team_id = $this->create_team();

		$deleted = $this->controller()->destroy( $this->request( 'DELETE', array( 'team' => $team_id ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $deleted );
		$this->assertSame( 204, $deleted->get_status() );

		$missing = $this->controller()->show( $this->request( 'GET', array( 'team' => $team_id ) ) );
		$this->assertWPError( $missing );

		$list = $this->controller()->index()->get_data();
		$this->assertSame( 0, $list['total'] );
	}

	/**
	 * The routes are registered under the console's key fragment.
	 */
	public function test_routes_are_registered() {
		$GLOBALS['wp_rest_server'] = null;
		$routes                    = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/org/teams', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/org/teams/(?P<team>\d+)', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/org/teams/(?P<team>\d+)/members', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/org/teams/(?P<team>\d+)/members/(?P<user>\d+)', $routes );
	}
}
