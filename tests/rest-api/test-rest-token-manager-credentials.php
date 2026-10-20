<?php
/**
 * Tests the token-manager REST surface (2.4.0, Proposal 084 Phase 3):
 * GET /token-manager/overview, POST …/credentials/{assistant}/{cred}/{revoke,delete},
 * POST /token-manager/restrictions/{user}/lift.
 *
 * Covers: manage_options gating, the credential public shape (hash never
 * leaks), revoke/delete flows through WP_MCP_AI_Credentials, restriction
 * lift through WP_MCP_AI_Restriction_Registry, and error status mapping.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Token_Manager_Credentials_Controller_Test extends WP_UnitTestCase {

	/**
	 * Admin user driving the requests.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Assistant fixture with a credential.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up an admin user and an assistant post.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id     = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assistant_id = $this->factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Credentials::ASSISTANT_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_REST_Token_Manager_Credentials_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The overview envelope carries stats, credentials, and restrictions.
	 */
	public function test_overview_envelope_as_admin() {
		$response = ( new WP_MCP_AI_REST_Token_Manager_Credentials_Controller() )->get_overview();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'stats', $data['data'] );
		$this->assertArrayHasKey( 'credentials', $data['data'] );
		$this->assertArrayHasKey( 'restrictions', $data['data'] );
		foreach ( array( 'total', 'active', 'revoked', 'assistants' ) as $stat ) {
			$this->assertArrayHasKey( $stat, $data['data']['stats'], "Missing stat: {$stat}" );
		}
	}

	/**
	 * The credential public shape never carries the stored hash.
	 */
	public function test_credential_shape_hides_hash() {
		$issued = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->admin_id );
		$this->assertNotWPError( $issued );

		$response = ( new WP_MCP_AI_REST_Token_Manager_Credentials_Controller() )->get_overview();
		$data     = $response->get_data();

		$this->assertCount( 1, $data['data']['credentials'] );
		$credential = $data['data']['credentials'][0];
		$this->assertArrayNotHasKey( 'hash', $credential );
		$this->assertSame( 'active', $credential['status'] );
		$this->assertSame( $this->assistant_id, $credential['assistant_id'] );
		$this->assertSame( 'Test Assistant', $credential['assistant_title'] );
		$this->assertSame( 1, $data['data']['stats']['active'] );
	}

	/**
	 * Revoking a credential flips its status in the overview.
	 */
	public function test_revoke_credential_via_controller() {
		$issued        = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->admin_id );
		$credential_id = $issued['credential']['id'];

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/token-manager/credentials/1/2/revoke' );
		$request->set_url_params(
			array(
				'assistant_id'  => $this->assistant_id,
				'credential_id' => $credential_id,
				'action'        => 'revoke',
			)
		);

		$controller = new WP_MCP_AI_REST_Token_Manager_Credentials_Controller();
		$response   = $controller->handle_credential_action( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		$overview = $controller->get_overview()->get_data();
		$this->assertSame( 'revoked', $overview['data']['credentials'][0]['status'] );
		$this->assertSame( 0, $overview['data']['stats']['active'] );
		$this->assertSame( 1, $overview['data']['stats']['revoked'] );
	}

	/**
	 * Deleting a credential removes it from the overview.
	 */
	public function test_delete_credential_via_controller() {
		$issued        = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->admin_id );
		$credential_id = $issued['credential']['id'];

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/token-manager/credentials/1/2/delete' );
		$request->set_url_params(
			array(
				'assistant_id'  => $this->assistant_id,
				'credential_id' => $credential_id,
				'action'        => 'delete',
			)
		);

		$controller = new WP_MCP_AI_REST_Token_Manager_Credentials_Controller();
		$response   = $controller->handle_credential_action( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		$overview = $controller->get_overview()->get_data();
		$this->assertEmpty( $overview['data']['credentials'] );
		$this->assertSame( 0, $overview['data']['stats']['total'] );
	}

	/**
	 * Unknown credentials surface as readable 404 errors.
	 */
	public function test_unknown_credential_is_404() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/token-manager/credentials/1/2/revoke' );
		$request->set_url_params(
			array(
				'assistant_id'  => $this->assistant_id,
				'credential_id' => 'cred_missing',
				'action'        => 'revoke',
			)
		);

		$result = ( new WP_MCP_AI_REST_Token_Manager_Credentials_Controller() )->handle_credential_action( $request );

		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * Lifting a restriction clears the registry flag.
	 */
	public function test_lift_restriction_via_controller() {
		if ( ! class_exists( 'WP_MCP_AI_Restriction_Registry' ) ) {
			$this->markTestSkipped( 'Restriction registry not available.' );
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_MCP_AI_Restriction_Registry::add_manual( $user_id, array( 'reason' => 'Test block' ), $this->admin_id );
		$this->assertTrue( WP_MCP_AI_Restriction_Registry::is_restricted( $user_id ) );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/token-manager/restrictions/1/lift' );
		$request->set_url_params( array( 'user_id' => $user_id ) );
		$request->set_param( 'type', 'all' );

		$response = ( new WP_MCP_AI_REST_Token_Manager_Credentials_Controller() )->handle_lift_restriction( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertFalse( WP_MCP_AI_Restriction_Registry::is_restricted( $user_id ) );
	}
}
