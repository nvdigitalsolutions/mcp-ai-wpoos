<?php
/**
 * PHPUnit test for ManagersController — the Astro CMS manager grants.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\REST\ManagersController;
use NvoosAstroBridge\Schema;

/**
 * Manager promotion/demotion surface for the Astro CMS.
 *
 * @covers \NvoosAstroBridge\REST\ManagersController
 * @group nvoos-astro-bridge
 */
class TestManagersController extends WP_Test_REST_TestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Ambient auth cookie (the bootstrap's test-admin cookie), restored in tearDown.
	 *
	 * @var string|null
	 */
	private $saved_cookie;

	/**
	 * Ambient REST nonce header, restored in tearDown.
	 *
	 * @var string|null
	 */
	private $saved_nonce;

	/**
	 * Set up routes, an admin user, and snapshot the ambient auth state.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Snapshot/restore of the bootstrap's ambient auth state; no input processing.
		$this->saved_cookie = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? $_COOKIE[ LOGGED_IN_COOKIE ] : null;
		$this->saved_nonce  = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? $_SERVER['HTTP_X_WP_NONCE'] : null;
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Restore the ambient auth cookie and nonce.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->saved_cookie ) {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		} else {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $this->saved_cookie;
		}
		if ( null === $this->saved_nonce ) {
			unset( $_SERVER['HTTP_X_WP_NONCE'] );
		} else {
			$_SERVER['HTTP_X_WP_NONCE'] = $this->saved_nonce;
		}
		parent::tearDown();
	}

	/**
	 * Make the next dispatched REST request authenticate as a given user.
	 *
	 * The repo bootstrap seeds an ambient auth cookie + REST nonce for the
	 * bootstrap test admin, which `rest_cookie_check_errors()` applies to
	 * every dispatch — order-dependently (the cookie survives some suites and
	 * not others). Minting the cookie + nonce for the acting user makes each
	 * test deterministic.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	private function act_as( int $user_id ): void {
		wp_set_current_user( $user_id );
		$_SERVER['HTTP_X_WP_NONCE']  = wp_create_nonce( 'wp_rest' );
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
	}

	/**
	 * Make the next dispatched REST request run unauthenticated.
	 *
	 * @return void
	 */
	private function act_anonymous(): void {
		wp_set_current_user( 0 );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );
	}

	/**
	 * Unauthenticated requests are rejected on both surfaces.
	 */
	public function test_unauthenticated_cannot_read_or_write(): void {
		$this->act_anonymous();
		$request  = new WP_REST_Request( 'GET', '/funiq/v1/managers' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );

		$request = new WP_REST_Request( 'PUT', '/funiq/v1/managers/1' );
		$request->set_body_params( array( 'manage' => true ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_forbidden', $response, 401 );
	}

	/**
	 * A logged-in user without the manage capability is forbidden.
	 */
	public function test_subscriber_without_cap_is_forbidden(): void {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$this->act_as( $subscriber );

		$request  = new WP_REST_Request( 'GET', '/funiq/v1/managers' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_forbidden', $response, 403 );
	}

	/**
	 * GET /managers returns the Payload envelope with only managers inside.
	 */
	public function test_list_returns_only_managers_with_envelope(): void {
		$editor     = $this->factory->user->create( array( 'role' => 'editor' ) );
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$this->act_as( $this->admin_id );
		$request  = new WP_REST_Request( 'GET', '/funiq/v1/managers' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		foreach ( array( 'docs', 'totalDocs', 'limit', 'totalPages', 'page', 'hasNextPage', 'hasPrevPage' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}

		$ids = wp_list_pluck( $data['docs'], 'id' );
		$this->assertContains( $editor, $ids, 'Role-granted managers appear.' );
		$this->assertNotContains( $subscriber, $ids, 'Users without the cap do not.' );

		$doc = $data['docs'][0];
		foreach ( array( 'id', 'email', 'name', 'isManager', 'protected', 'isSelf' ) as $key ) {
			$this->assertArrayHasKey( $key, $doc );
		}
	}

	/**
	 * GET /managers?search= returns matching users with isManager flags.
	 */
	public function test_search_returns_candidates_with_flags(): void {
		$editor     = $this->factory->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'editor.managers@example.org',
			)
		);
		$subscriber = $this->factory->user->create(
			array(
				'role'       => 'subscriber',
				'user_email' => 'candidate.managers@example.org',
			)
		);

		$this->act_as( $this->admin_id );
		$request = new WP_REST_Request( 'GET', '/funiq/v1/managers' );
		$request->set_query_params( array( 'search' => 'candidate.managers@example.org' ) );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$this->assertCount( 1, $data['docs'] );
		$this->assertSame( $subscriber, $data['docs'][0]['id'] );
		$this->assertFalse( $data['docs'][0]['isManager'] );

		$request = new WP_REST_Request( 'GET', '/funiq/v1/managers' );
		$request->set_query_params( array( 'search' => 'editor.managers@example.org' ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['docs'] );
		$this->assertSame( $editor, $data['docs'][0]['id'] );
		$this->assertTrue( $data['docs'][0]['isManager'] );
	}

	/**
	 * Searches shorter than two characters are rejected by the schema.
	 */
	public function test_search_minimum_length(): void {
		$this->act_as( $this->admin_id );
		$request = new WP_REST_Request( 'GET', '/funiq/v1/managers' );
		$request->set_query_params( array( 'search' => 'a' ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_invalid_param', $response, 400 );
	}

	/**
	 * PUT {manage:true} grants the capability at the user level.
	 */
	public function test_promote_grants_capability(): void {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$this->act_as( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', '/funiq/v1/managers/' . $subscriber );
		$request->set_body_params( array( 'manage' => true ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['isManager'] );
		// phpcs:ignore WordPress.WP.Capabilities -- Capability slug lives in Schema::CAP_MANAGE.
		$this->assertTrue( user_can( $subscriber, Schema::CAP_MANAGE ) );
	}

	/**
	 * PUT {manage:false} removes the capability at the user level.
	 */
	public function test_demote_removes_capability(): void {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		$user       = get_userdata( $subscriber );
		$user->add_cap( Schema::CAP_MANAGE );

		$this->act_as( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', '/funiq/v1/managers/' . $subscriber );
		$request->set_body_params( array( 'manage' => false ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['isManager'] );
		// phpcs:ignore WordPress.WP.Capabilities -- Capability slug lives in Schema::CAP_MANAGE.
		$this->assertFalse( user_can( $subscriber, Schema::CAP_MANAGE ) );
	}

	/**
	 * A manager cannot remove their own access.
	 */
	public function test_cannot_demote_self(): void {
		$editor = $this->factory->user->create( array( 'role' => 'editor' ) );

		$this->act_as( $editor );
		$request = new WP_REST_Request( 'PUT', '/funiq/v1/managers/' . $editor );
		$request->set_body_params( array( 'manage' => false ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'astro_managers_self', $response, 400 );
	}

	/**
	 * Super admins (site owners) cannot be promoted or demoted from the CMS.
	 */
	public function test_cannot_touch_super_admin(): void {
		$editor = $this->factory->user->create( array( 'role' => 'editor' ) );

		$this->act_as( $editor );
		$request = new WP_REST_Request( 'PUT', '/funiq/v1/managers/' . $this->admin_id );
		$request->set_body_params( array( 'manage' => false ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'astro_managers_protected', $response, 400 );

		$request = new WP_REST_Request( 'PUT', '/funiq/v1/managers/' . $this->admin_id );
		$request->set_body_params( array( 'manage' => true ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'astro_managers_protected', $response, 400 );
	}

	/**
	 * The manage field is required.
	 */
	public function test_update_requires_manage_param(): void {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$this->act_as( $this->admin_id );
		$request  = new WP_REST_Request( 'PUT', '/funiq/v1/managers/' . $subscriber );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_missing_callback_param', $response, 400 );
	}

	/**
	 * Unknown user IDs return 404.
	 */
	public function test_unknown_user_returns_404(): void {
		$this->act_as( $this->admin_id );
		$request = new WP_REST_Request( 'PUT', '/funiq/v1/managers/999999' );
		$request->set_body_params( array( 'manage' => true ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'astro_managers_not_found', $response, 404 );
	}

	/**
	 * The route is aliased under the NV commerce contract namespace.
	 */
	public function test_route_aliased_under_nv_namespace(): void {
		$this->act_as( $this->admin_id );
		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/managers' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'docs', $response->get_data() );
	}
}
